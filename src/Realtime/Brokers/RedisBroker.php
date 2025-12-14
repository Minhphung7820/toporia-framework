<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

use Redis;
use Toporia\Framework\Realtime\Brokers\CircuitBreaker\CircuitBreaker;
use Toporia\Framework\Realtime\Brokers\ConnectionPool\BrokerConnectionPool;
use Toporia\Framework\Realtime\Brokers\RedisStream\RedisStreamConsumer;
use Toporia\Framework\Realtime\Brokers\RedisStream\RedisStreamProducer;
use Toporia\Framework\Realtime\Contracts\{BrokerInterface, HealthCheckableInterface, HealthCheckResult, MessageInterface};
use Toporia\Framework\Realtime\Exceptions\BrokerException;
use Toporia\Framework\Realtime\Metrics\BrokerMetrics;
use Toporia\Framework\Realtime\RealtimeManager;

/**
 * Class RedisBroker
 *
 * Redis Streams broker with CORRECT pull-based batch semantics.
 *
 * Redis Streams vs Pub/Sub:
 * - Persistence: Messages stored in append-only log (vs ephemeral)
 * - Consumer groups: Load balancing via pull (vs broadcast)
 * - Reliability: PEL + XACK (vs fire-and-forget)
 * - Replay: Can read history (vs lost if not subscribed)
 *
 * Batch processing reality:
 * - Producer batch: Pipeline XADD commands (network optimization)
 * - Consumer batch: XREADGROUP COUNT N (pull batch, not broker batch)
 * - ACK batch: XACK id1 id2 ... idN (single call)
 * - NO broker-side batching like Kafka (Redis just appends to log)
 *
 * Performance characteristics:
 * - Single publish: ~0.3ms (XADD)
 * - Batch publish: 50K-200K msg/s (pipeline network optimization)
 * - Consumer pull: 50K-200K msg/s (XREADGROUP COUNT 1000)
 * - Batch ACK: O(1) per batch (vs O(N) if ACK per message)
 *
 * Architecture:
 * - Stream = append-only log in RAM
 * - Consumer group = shared cursor + PEL
 * - PEL = pending entries list (tracks un-ACKed messages)
 * - XAUTOCLAIM = recover messages from dead consumers
 *
 * Optimizations:
 * - Pipeline batching (reduce network round-trips)
 * - Approximate trimming MAXLEN ~ (O(1) vs O(N))
 * - Batch ACK (single XACK call for all successful messages)
 * - BLOCK > 0 (50-100ms): prevents busy polling, zero QPS when idle
 * - 2 empty polls = stream exhausted (correct stop condition)
 *
 * When to use Redis Streams:
 * - Moderate throughput (<200K msg/s)
 * - Small payloads (<1KB)
 * - Short history (hours, not days)
 * - At-least-once delivery is acceptable
 * - Want simplicity (no Zookeeper, no partitions)
 *
 * When NOT to use (use Kafka instead):
 * - High sustained throughput (>200K msg/s)
 * - Large payloads (>1KB)
 * - Long replay (days/weeks)
 * - Exactly-once semantics required
 * - Need partition-level ordering
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     4.2.0 (Redis Streams - Fixed Message Exhaustion)
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers
 * @since       2025-12-14
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @changelog
 *   v4.2.0 (2025-12-14): Fixed consumer stopping prematurely at ~11K/15K messages
 *                        - Increased empty poll threshold (2 → 5)
 *                        - Added pending message verification before exit
 *                        - Improved connection error retry logic
 */
final class RedisBroker implements BrokerInterface, HealthCheckableInterface
{
    private ?Redis $redis = null;
    private bool $connected = false;
    private CircuitBreaker $circuitBreaker;
    private BrokerConnectionPool $connectionPool;
    private MemoryManager $memoryManager;
    private string $connectionKey;

    /**
     * @var RedisStreamProducer|null Stream producer instance
     */
    private ?RedisStreamProducer $producer = null;

    /**
     * @var RedisStreamConsumer|null Stream consumer instance
     */
    private ?RedisStreamConsumer $consumer = null;

    /**
     * @var array<string, callable> Subscriptions map [channel => callback]
     */
    private array $subscriptions = [];

    /**
     * @var string Consumer group name
     */
    private string $consumerGroup;

    /**
     * @var string Consumer name
     */
    private string $consumerName;

    /**
     * @var int Maximum stream length
     */
    private int $maxStreamLength;

    /**
     * @var int Batch size for consumer
     */
    private int $consumerBatchSize;

    /**
     * @var int Block timeout for consumer (ms)
     */
    private int $consumerBlockMs;

    public function __construct(
        private array $config = [],
        private readonly ?RealtimeManager $manager = null
    ) {
        // Runtime check: Ensure Redis extension is loaded
        if (!extension_loaded('redis')) {
            throw BrokerException::invalidConfiguration(
                'redis',
                "Redis extension is not installed. Install it with:\n" .
                    "  Ubuntu/Debian: sudo apt-get install php-redis\n" .
                    "  macOS: pecl install redis"
            );
        }

        // Stream configuration
        $this->consumerGroup = $config['consumer_group'] ?? 'realtime-group';
        $this->consumerName = $config['consumer_name'] ?? $this->generateConsumerName();
        $this->maxStreamLength = (int) ($config['max_stream_length'] ?? 10000);
        $this->consumerBatchSize = (int) ($config['consumer_batch_size'] ?? 100);
        $this->consumerBlockMs = (int) ($config['consumer_block_ms'] ?? 1000);

        // Initialize components
        $this->circuitBreaker = new CircuitBreaker(
            name: 'redis-broker',
            failureThreshold: $config['circuit_breaker_threshold'] ?? 5,
            timeout: $config['circuit_breaker_timeout'] ?? 60
        );

        $this->connectionPool = BrokerConnectionPool::forBroker('redis');
        $this->memoryManager = new MemoryManager();

        // Connection key for pooling
        $this->connectionKey = md5(sprintf(
            '%s:%d:%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 6379,
            $config['password'] ?? ''
        ));

        // Connect to Redis
        $this->connect();
    }

    /**
     * Connect to Redis with circuit breaker protection.
     *
     * @return void
     * @throws BrokerException
     */
    private function connect(): void
    {
        try {
            $this->circuitBreaker->call(function () {
                $this->doConnect();
            });

            $this->connected = true;
            BrokerMetrics::recordConnectionEvent('redis', 'connect');
        } catch (\Throwable $e) {
            BrokerMetrics::recordConnectionEvent('redis', 'connect_failed');
            throw BrokerException::connectionFailed(
                'redis',
                "Connection failed: {$e->getMessage()}",
                $e
            );
        }
    }

    /**
     * Perform actual connection.
     *
     * @return void
     */
    private function doConnect(): void
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int) ($this->config['port'] ?? 6379);
        $timeout = (float) ($this->config['timeout'] ?? 2.0);
        $password = $this->config['password'] ?? null;
        $database = $this->config['database'] ?? 0;

        // Try to get from connection pool
        $pooledRedis = $this->connectionPool->get($this->connectionKey);

        if ($pooledRedis instanceof Redis) {
            $this->redis = $pooledRedis;
        } else {
            // Create new connection
            $this->redis = new Redis();

            // Connect with timeout
            $this->redis->connect($host, $port, $timeout);

            // Set read timeout
            if (defined('Redis::OPT_READ_TIMEOUT')) {
                $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->config['read_timeout'] ?? 5.0);
            }

            // Authenticate if password provided
            if (!empty($password)) {
                $this->redis->auth($password);
            }

            // Select database
            $this->redis->select((int) $database);

            // Store in connection pool
            $this->connectionPool->store($this->connectionKey, $this->redis);
        }

        // Initialize producer and consumer
        $this->producer = new RedisStreamProducer(
            $this->redis,
            $this->maxStreamLength,
            true // Use approximate trimming for performance
        );

        $this->consumer = new RedisStreamConsumer(
            $this->redis,
            $this->consumerGroup,
            $this->consumerName,
            $this->consumerBlockMs,
            $this->consumerBatchSize,
            (int) ($this->config['idle_time_ms'] ?? 60000)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function publish(string $channel, MessageInterface $message): void
    {
        if (!$this->connected || $this->producer === null) {
            throw BrokerException::notConnected('redis');
        }

        $streamKey = $this->getStreamKey($channel);
        $payload = $message->toJson();

        try {
            $messageId = $this->producer->publish($streamKey, $payload);

            if ($messageId === false) {
                throw new \RuntimeException('Failed to publish message to stream');
            }
        } catch (\Throwable $e) {
            throw BrokerException::publishFailed('redis', $channel, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * High-performance batch publishing using Redis Streams pipeline.
     *
     * Performance: 50K-100K msg/s (similar to Kafka batch)
     */
    public function publishBatch(array $messages, int $flushTimeoutMs = 10000): array
    {
        if (!$this->connected || $this->producer === null) {
            throw BrokerException::notConnected('redis');
        }

        $startTime = microtime(true);

        // Group messages by channel/stream
        $streamMessages = [];
        foreach ($messages as $item) {
            $channel = $item['channel'];
            $message = $item['message'];

            $streamKey = $this->getStreamKey($channel);
            $payload = $message->toJson();

            if (!isset($streamMessages[$streamKey])) {
                $streamMessages[$streamKey] = [];
            }
            $streamMessages[$streamKey][] = $payload;
        }

        $totalQueued = 0;
        $totalFailed = 0;
        $allMessageIds = [];

        // Publish to each stream
        foreach ($streamMessages as $streamKey => $payloads) {
            try {
                $result = $this->producer->publishBatch($streamKey, $payloads);

                $totalQueued += $result['queued'];
                $totalFailed += $result['failed'];
                $allMessageIds = array_merge($allMessageIds, $result['message_ids']);
            } catch (\Throwable $e) {
                $totalFailed += count($payloads);
                error_log("[RedisBroker] Batch publish to {$streamKey} failed: {$e->getMessage()}");
            }
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $throughput = $totalTime > 0 ? (int) round($totalQueued / ($totalTime / 1000)) : 0;

        BrokerMetrics::recordPublish('redis', 'batch', $totalTime, $totalFailed === 0);

        return [
            'queued' => $totalQueued,
            'failed' => $totalFailed,
            'queue_time_ms' => round($totalTime, 2),
            'flush_time_ms' => 0, // Redis Streams don't need separate flush
            'total_time_ms' => round($totalTime, 2),
            'throughput' => $throughput,
            'message_ids' => $allMessageIds,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(string $channel, callable $callback): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('redis');
        }

        $this->subscriptions[$channel] = $callback;
    }

    /**
     * Subscribe to channel pattern (compatibility method for wildcards).
     *
     * Note: Redis Streams don't support pattern subscriptions like Pub/Sub.
     * This method maps wildcard patterns to exact channel subscriptions.
     *
     * @param string $pattern Channel pattern (e.g., "events.*")
     * @param callable $callback Message handler (MessageInterface $msg, string $channel)
     * @return void
     */
    public function psubscribe(string $pattern, callable $callback): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('redis');
        }

        // For wildcard patterns, subscribe with exact channel name
        // The pattern matching will be handled by the consume logic
        $wrappedCallback = function (MessageInterface $message) use ($callback, $pattern) {
            // Call with both message and channel (pattern) for compatibility
            $callback($message, $pattern);
        };

        $this->subscriptions[$pattern] = $wrappedCallback;
    }

    /**
     * Start consuming messages from subscribed channels.
     *
     * Uses Redis Streams XREADGROUP for batch consumption with message exhaustion.
     * Consumes from ALL subscribed channels simultaneously in a single XREADGROUP call.
     *
     * IMPORTANT: This method polls CONTINUOUSLY until all available messages are
     * exhausted before returning to the command loop. This ensures high throughput
     * and prevents messages from being left unprocessed.
     *
     * @param int $timeoutMs Poll timeout (not used, controlled by consumer_block_ms config)
     * @param int $batchSize Batch size (not used, controlled by consumer_batch_size config)
     * @return void
     */
    public function consume(int $timeoutMs = 1000, int $batchSize = 100): void
    {
        if (empty($this->subscriptions) || $this->consumer === null) {
            return;
        }

        // Map channels to stream keys with callbacks
        $streamCallbacks = [];
        foreach ($this->subscriptions as $channel => $callback) {
            $streamKey = $this->getStreamKey($channel);
            $streamCallbacks[$streamKey] = $callback;
        }

        try {
            // Consume from all streams simultaneously (single XREADGROUP call)
            // The consumer will poll until all available messages are exhausted
            $this->consumer->consumeMultiple($streamCallbacks);
        } catch (\Throwable $e) {
            error_log("[RedisBroker] Consumer error: {$e->getMessage()}");
            throw BrokerException::consumeFailed('redis', $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopConsuming(): void
    {
        if ($this->consumer !== null) {
            $this->consumer->stopConsuming();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(string $channel): void
    {
        unset($this->subscriptions[$channel]);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriberCount(string $channel): int
    {
        if ($this->redis === null || $this->consumer === null) {
            return 0;
        }

        try {
            $streamKey = $this->getStreamKey($channel);
            $info = $this->consumer->getConsumerInfo($streamKey);

            return (int) ($info['consumers'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->redis !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        $this->stopConsuming();

        // Don't close pooled connections, just release them
        $this->redis = null;
        $this->producer = null;
        $this->consumer = null;

        $this->connected = false;
        $this->subscriptions = [];

        BrokerMetrics::recordConnectionEvent('redis', 'disconnect');
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'redis';
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(): HealthCheckResult
    {
        if (!$this->connected || $this->redis === null) {
            return HealthCheckResult::unhealthy('Redis broker not connected');
        }

        $start = microtime(true);

        try {
            $pong = $this->redis->ping();
            $latencyMs = (microtime(true) - $start) * 1000;

            if ($pong === true || $pong === '+PONG' || $pong === 'PONG') {
                $info = $this->redis->info('server');
                $version = $info['redis_version'] ?? 'unknown';

                // Get stream stats
                $streamStats = [];
                foreach (array_keys($this->subscriptions) as $channel) {
                    $streamKey = $this->getStreamKey($channel);
                    $streamStats[$channel] = [
                        'length' => $this->producer?->getLength($streamKey) ?? 0,
                        'pending' => $this->consumer?->getPendingCount($streamKey) ?? 0,
                    ];
                }

                return HealthCheckResult::healthy(
                    message: 'Redis Streams connection healthy',
                    details: [
                        'mode' => 'streams',
                        'version' => $version,
                        'connected_clients' => $info['connected_clients'] ?? 0,
                        'consumer_group' => $this->consumerGroup,
                        'consumer_name' => $this->consumerName,
                        'subscriptions' => count($this->subscriptions),
                        'streams' => $streamStats,
                        'circuit_breaker' => $this->circuitBreaker->getState()->value,
                        'memory_stats' => $this->memoryManager->getStats(),
                        'metrics' => BrokerMetrics::getMetrics('redis'),
                    ],
                    latencyMs: $latencyMs
                );
            }

            return HealthCheckResult::degraded(
                message: 'Redis ping returned unexpected response',
                details: ['response' => $pong],
                latencyMs: $latencyMs
            );
        } catch (\Throwable $e) {
            return HealthCheckResult::unhealthy(
                message: "Redis health check failed: {$e->getMessage()}",
                details: ['exception' => $e::class]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHealthCheckName(): string
    {
        return 'redis-broker-streams';
    }

    /**
     * Get circuit breaker instance.
     *
     * @return CircuitBreaker
     */
    public function getCircuitBreaker(): CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    /**
     * Get memory manager instance.
     *
     * @return MemoryManager
     */
    public function getMemoryManager(): MemoryManager
    {
        return $this->memoryManager;
    }

    /**
     * Get stream key from channel name.
     *
     * @param string $channel Channel name
     * @return string Stream key
     */
    private function getStreamKey(string $channel): string
    {
        return "realtime:{$channel}";
    }

    /**
     * Generate unique consumer name.
     *
     * @return string
     */
    private function generateConsumerName(): string
    {
        $hostname = gethostname() ?: 'unknown';
        $pid = getmypid() ?: rand(1000, 9999);
        return "{$hostname}-{$pid}";
    }

    /**
     * Get producer instance (for advanced usage).
     *
     * @return RedisStreamProducer|null
     */
    public function getProducer(): ?RedisStreamProducer
    {
        return $this->producer;
    }

    /**
     * Get consumer instance (for advanced usage).
     *
     * @return RedisStreamConsumer|null
     */
    public function getConsumer(): ?RedisStreamConsumer
    {
        return $this->consumer;
    }

    /**
     * Get stats for all streams.
     *
     * @return array<string, array>
     */
    public function getStreamsStats(): array
    {
        $stats = [];

        foreach (array_keys($this->subscriptions) as $channel) {
            $streamKey = $this->getStreamKey($channel);

            $stats[$channel] = [
                'stream_key' => $streamKey,
                'length' => $this->producer?->getLength($streamKey) ?? 0,
                'pending' => $this->consumer?->getPendingCount($streamKey) ?? 0,
                'info' => $this->producer?->getInfo($streamKey) ?? [],
            ];
        }

        return $stats;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
