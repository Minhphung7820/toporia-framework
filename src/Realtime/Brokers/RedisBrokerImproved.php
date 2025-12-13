<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

use Redis;
use Toporia\Framework\Realtime\Brokers\CircuitBreaker\CircuitBreaker;
use Toporia\Framework\Realtime\Brokers\ConnectionPool\BrokerConnectionPool;
use Toporia\Framework\Realtime\Contracts\{BrokerInterface, HealthCheckableInterface, HealthCheckResult, MessageInterface};
use Toporia\Framework\Realtime\Exceptions\BrokerException;
use Toporia\Framework\Realtime\Metrics\BrokerMetrics;
use Toporia\Framework\Realtime\RealtimeManager;
use Toporia\Framework\Realtime\Message;

/**
 * Class RedisBrokerImproved
 *
 * Improved Redis Pub/Sub broker with connection pooling, circuit breaker,
 * auto-reconnect, and memory management.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RedisBrokerImproved implements BrokerInterface, HealthCheckableInterface
{
    private ?Redis $redis = null;
    private ?Redis $subscriber = null;
    private array $subscriptions = [];
    private bool $connected = false;
    private bool $consuming = false;
    private CircuitBreaker $circuitBreaker;
    private BrokerConnectionPool $connectionPool;
    private MemoryManager $memoryManager;
    private string $connectionKey;

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
        $pooledSubscriber = $this->connectionPool->get($this->connectionKey . ':subscriber');

        if ($pooledRedis && $pooledSubscriber) {
            $this->redis = $pooledRedis;
            $this->subscriber = $pooledSubscriber;
            return;
        }

        // Create new connections
        $this->redis = new Redis();
        $this->subscriber = new Redis();

        // Connect with timeout
        $this->redis->connect($host, $port, $timeout);
        $this->subscriber->connect($host, $port, $timeout);

        // Set read/write timeouts (use setOption with integer constants)
        // Note: Redis extension uses different timeout mechanisms
        // Connection timeout is set in connect(), read timeout in config
        if (defined('Redis::OPT_READ_TIMEOUT')) {
            $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->config['read_timeout'] ?? 5.0);
        }

        // Authenticate if password provided
        if (!empty($password)) {
            $this->redis->auth($password);
            $this->subscriber->auth($password);
        }

        // Select database
        $this->redis->select((int) $database);
        $this->subscriber->select((int) $database);

        // Store in connection pool
        $this->connectionPool->store($this->connectionKey, $this->redis);
        $this->connectionPool->store($this->connectionKey . ':subscriber', $this->subscriber);
    }

    /**
     * {@inheritdoc}
     */
    public function publish(string $channel, MessageInterface $message): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('redis');
        }

        $redisChannel = "realtime:{$channel}";
        $payload = $message->toJson();

        $startTime = microtime(true);

        try {
            $this->circuitBreaker->call(function () use ($redisChannel, $payload) {
                $this->redis->publish($redisChannel, $payload);
            });

            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordPublish('redis', $channel, $duration, true);
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordPublish('redis', $channel, $duration, false);
            BrokerMetrics::recordError('redis', 'publish');

            throw BrokerException::publishFailed('redis', $channel, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(string $channel, callable $callback): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('redis');
        }

        $redisChannel = "realtime:{$channel}";

        // Store callback with channel mapping
        if (!isset($this->subscriptions[$redisChannel])) {
            $this->subscriptions[$redisChannel] = [];
        }
        $this->subscriptions[$redisChannel][$channel] = $callback;
    }

    /**
     * Start consuming messages with auto-reconnect and memory management.
     *
     * @param int $timeoutMs Poll timeout in milliseconds
     * @param int $batchSize Maximum messages per batch
     * @return void
     */
    public function consume(int $timeoutMs = 1000, int $batchSize = 100): void
    {
        if (empty($this->subscriptions)) {
            return;
        }

        $this->consuming = true;
        $redisChannels = array_keys($this->subscriptions);

        if (empty($redisChannels)) {
            return;
        }

        $maxRetries = 5;
        $retryCount = 0;

        while ($this->consuming) {
            try {
                $this->circuitBreaker->call(function () use ($redisChannels) {
                    $this->doConsume($redisChannels);
                });

                // Reset retry counter on successful consume
                $retryCount = 0;
            } catch (\Throwable $e) {
                $retryCount++;

                BrokerMetrics::recordError('redis', 'consume');

                if ($retryCount >= $maxRetries) {
                    error_log("Redis consumer failed after {$maxRetries} retries: {$e->getMessage()}");
                    throw BrokerException::consumeFailed('redis', "Max retries exceeded: {$e->getMessage()}");
                }

                // Exponential backoff: 1s, 2s, 4s, 8s, 16s
                $delay = min((int) pow(2, $retryCount - 1), 16);
                error_log("Redis connection lost. Retry {$retryCount}/{$maxRetries} in {$delay}s");
                sleep($delay);

                // Attempt reconnection
                try {
                    $this->reconnect();
                    BrokerMetrics::recordConnectionEvent('redis', 'reconnect');
                } catch (\Throwable $reconnectError) {
                    error_log("Redis reconnect failed: {$reconnectError->getMessage()}");
                }
            }
        }
    }

    /**
     * Perform actual consume operation.
     *
     * @param array<string> $redisChannels
     * @return void
     */
    private function doConsume(array $redisChannels): void
    {
        $lastActivity = time();

        $this->subscriber->subscribe($redisChannels, function ($redis, $redisChannel, $payload) use (&$lastActivity) {
            // Check if we should stop
            if (!$this->consuming) {
                return false;
            }

            $lastActivity = time();

            // Memory management
            $this->memoryManager->tick();

            // Process message
            $subscriptions = $this->subscriptions[$redisChannel] ?? null;

            if (!$subscriptions) {
                return true;
            }

            try {
                $message = Message::fromJson($payload);
                $channel = str_replace('realtime:', '', $redisChannel);

                // Execute callbacks
                if (is_array($subscriptions)) {
                    $callback = $subscriptions[$channel] ?? null;
                    if ($callback) {
                        $callback($message);
                    } else {
                        foreach ($subscriptions as $cb) {
                            if (is_callable($cb)) {
                                $cb($message);
                            }
                        }
                    }
                } elseif (is_callable($subscriptions)) {
                    $subscriptions($message);
                }
            } catch (\Throwable $e) {
                error_log("Redis subscriber error on {$redisChannel}: {$e->getMessage()}");
                BrokerMetrics::recordError('redis', 'process_message');
            }

            // Periodic health check (every 60 seconds of no messages)
            $now = time();
            if ($now - $lastActivity > 60) {
                try {
                    if (!$this->redis->ping()) {
                        error_log("Redis health check failed - connection lost");
                        return false;
                    }
                    $lastActivity = $now;
                } catch (\Throwable $e) {
                    error_log("Redis ping failed: {$e->getMessage()}");
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Reconnect to Redis.
     *
     * @return void
     * @throws BrokerException
     */
    private function reconnect(): void
    {
        $this->disconnect();
        sleep(1);
        $this->connect();
    }

    /**
     * {@inheritdoc}
     */
    public function stopConsuming(): void
    {
        $this->consuming = false;

        if (!empty($this->subscriptions)) {
            $redisChannels = array_keys($this->subscriptions);
            try {
                $this->subscriber->unsubscribe($redisChannels);
            } catch (\Throwable $e) {
                error_log("Error unsubscribing from Redis: {$e->getMessage()}");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(string $channel): void
    {
        $redisChannel = "realtime:{$channel}";

        if (isset($this->subscriptions[$redisChannel])) {
            if (is_array($this->subscriptions[$redisChannel])) {
                unset($this->subscriptions[$redisChannel][$channel]);

                if (empty($this->subscriptions[$redisChannel])) {
                    unset($this->subscriptions[$redisChannel]);
                    $this->subscriber->unsubscribe([$redisChannel]);
                }
            } else {
                unset($this->subscriptions[$redisChannel]);
                $this->subscriber->unsubscribe([$redisChannel]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriberCount(string $channel): int
    {
        $redisChannel = "realtime:{$channel}";
        $result = $this->redis->pubsub('NUMSUB', $redisChannel);
        return (int) ($result[$redisChannel] ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        try {
            // Don't close pooled connections, just release them
            // The pool will manage their lifecycle
            $this->redis = null;
            $this->subscriber = null;
        } catch (\Throwable $e) {
            error_log("Error disconnecting from Redis: {$e->getMessage()}");
        }

        $this->connected = false;
        $this->subscriptions = [];

        BrokerMetrics::recordConnectionEvent('redis', 'disconnect');
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'redis-improved';
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(): HealthCheckResult
    {
        if (!$this->connected) {
            return HealthCheckResult::unhealthy('Redis broker not connected');
        }

        $start = microtime(true);

        try {
            $pong = $this->redis->ping();
            $latencyMs = (microtime(true) - $start) * 1000;

            if ($pong === true || $pong === '+PONG' || $pong === 'PONG') {
                $info = $this->redis->info('server');
                $version = $info['redis_version'] ?? 'unknown';

                return HealthCheckResult::healthy(
                    message: 'Redis connection healthy',
                    details: [
                        'version' => $version,
                        'connected_clients' => $info['connected_clients'] ?? 0,
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
        return 'redis-broker-improved';
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

    public function __destruct()
    {
        $this->disconnect();
    }
}
