<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

use Toporia\Framework\Realtime\Brokers\CircuitBreaker\CircuitBreaker;
use Toporia\Framework\Realtime\Brokers\Kafka\Client\{KafkaClientInterface, KafkaMessage, RdKafkaClientImproved};
use Toporia\Framework\Realtime\Brokers\Kafka\TopicStrategy\TopicStrategyFactory;
use Toporia\Framework\Realtime\Contracts\{BrokerInterface, HealthCheckableInterface, HealthCheckResult, MessageInterface, TopicStrategyInterface};
use Toporia\Framework\Realtime\Exceptions\BrokerException;
use Toporia\Framework\Realtime\{Message, Metrics\BrokerMetrics, RealtimeManager};

/**
 * Class KafkaBrokerImproved
 *
 * Improved Kafka broker with backpressure, circuit breaker, and better error handling.
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
final class KafkaBrokerImproved implements BrokerInterface, HealthCheckableInterface
{
    private KafkaClientInterface $client;
    private TopicStrategyInterface $topicStrategy;
    private bool $connected = false;
    private CircuitBreaker $circuitBreaker;
    private MemoryManager $memoryManager;

    /**
     * @var array<string, array<string, callable>> Channel subscriptions [topic => [channel => callback]]
     */
    private array $subscriptions = [];

    /**
     * @var array<string, callable> Wildcard pattern subscriptions [pattern => callback]
     */
    private array $wildcardSubscriptions = [];

    /**
     * @var array<string, array{partition: int, cached_at: int}> Partition cache with TTL [topic:channel => {partition, cached_at}]
     */
    private array $partitionCache = [];

    private const PARTITION_CACHE_TTL = 300; // 5 minutes

    /**
     * @param array<string, mixed> $config Kafka configuration
     * @param RealtimeManager|null $manager Realtime manager instance
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?RealtimeManager $manager = null
    ) {
        $this->client = $this->createClient($config);
        $this->topicStrategy = TopicStrategyFactory::create($config);

        $this->circuitBreaker = new CircuitBreaker(
            name: 'kafka-broker',
            failureThreshold: $config['circuit_breaker_threshold'] ?? 5,
            timeout: $config['circuit_breaker_timeout'] ?? 60
        );

        $this->memoryManager = new MemoryManager();

        $this->initialize();
    }

    /**
     * Initialize Kafka client connection.
     *
     * @return void
     */
    private function initialize(): void
    {
        try {
            $this->circuitBreaker->call(function () {
                $this->client->connect();
            });

            $this->connected = true;
            BrokerMetrics::recordConnectionEvent('kafka', 'connect');
        } catch (\Throwable $e) {
            BrokerMetrics::recordConnectionEvent('kafka', 'connect_failed');
            throw BrokerException::connectionFailed('kafka', $e->getMessage(), $e);
        }
    }

    /**
     * Create Kafka client instance (uses RdKafkaClientImproved for reliability).
     *
     * @param array<string, mixed> $config
     * @return KafkaClientInterface
     */
    private function createClient(array $config): KafkaClientInterface
    {
        if (!extension_loaded('rdkafka')) {
            throw BrokerException::invalidConfiguration(
                'kafka',
                'rdkafka extension is required for KafkaBrokerImproved. Install: pecl install rdkafka'
            );
        }

        $brokers = $this->normalizeBrokers($config['brokers'] ?? ['localhost:9092']);
        $consumerGroup = (string) ($config['consumer_group'] ?? 'realtime-servers');
        $manualCommit = (bool) ($config['manual_commit'] ?? false);
        $producerConfig = $this->sanitizeConfig($config['producer_config'] ?? []);
        $consumerConfig = $this->sanitizeConfig($config['consumer_config'] ?? []);

        return new RdKafkaClientImproved(
            brokers: $brokers,
            consumerGroup: $consumerGroup,
            manualCommit: $manualCommit,
            producerConfig: $producerConfig,
            consumerConfig: $consumerConfig
        );
    }

    /**
     * Normalize broker list to array.
     *
     * @param mixed $brokers
     * @return array<string>
     */
    private function normalizeBrokers(mixed $brokers): array
    {
        if (is_string($brokers)) {
            $brokers = explode(',', $brokers);
        }

        if (!is_array($brokers)) {
            $brokers = ['localhost:9092'];
        }

        return array_filter(
            array_map('trim', $brokers),
            fn($b) => !empty($b)
        );
    }

    /**
     * Sanitize Kafka configuration.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function sanitizeConfig(array $config): array
    {
        $sanitized = [];

        foreach ($config as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $strValue = (string) $value;

            // Remove empty compression settings
            if (in_array($key, ['compression.type', 'compression.codec'])) {
                if ($strValue === '' || strcasecmp($strValue, 'none') === 0 || strcasecmp($strValue, 'off') === 0) {
                    continue;
                }
            }

            $sanitized[$key] = $strValue;
        }

        return $sanitized;
    }

    /**
     * {@inheritdoc}
     */
    public function publish(string $channel, MessageInterface $message): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('kafka');
        }

        $topicName = $this->getTopicName($channel);
        // Use null partition to let Kafka auto-assign based on message key
        // This avoids "Unknown partition" errors when calculated partition exceeds actual partition count
        $partition = null; // RD_KAFKA_PARTITION_UA - Kafka will use key-based partitioning
        $key = $this->getMessageKey($channel);
        $payload = $message->toJson();

        $startTime = microtime(true);

        try {
            $this->circuitBreaker->call(function () use ($topicName, $payload, $partition, $key) {
                $this->client->publish($topicName, $payload, $partition, $key);
            });

            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordPublish('kafka', $channel, $duration, true);
        } catch (BrokerException $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordPublish('kafka', $channel, $duration, false);
            throw $e;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordPublish('kafka', $channel, $duration, false);
            BrokerMetrics::recordError('kafka', 'publish');
            throw BrokerException::publishFailed('kafka', $channel, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(string $channel, callable $callback): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('kafka');
        }

        $topicName = $this->getTopicName($channel);

        // Store callback with channel mapping
        if (!isset($this->subscriptions[$topicName])) {
            $this->subscriptions[$topicName] = [];
        }
        $this->subscriptions[$topicName][$channel] = $callback;

        // Store wildcard pattern for pattern matching
        if (str_contains($channel, '*')) {
            $this->wildcardSubscriptions[$channel] = $callback;
        }
    }

    /**
     * Start consuming messages from subscribed topics.
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

        $topics = array_keys($this->subscriptions);

        try {
            $this->client->subscribe($topics);
        } catch (\Throwable $e) {
            throw BrokerException::subscribeFailed('kafka', implode(',', $topics), $e->getMessage(), $e);
        }

        $this->client->consume(
            callback: fn(KafkaMessage $msg) => $this->processMessage($msg),
            timeoutMs: $timeoutMs,
            batchSize: $batchSize
        );
    }

    /**
     * Process a consumed Kafka message with memory management.
     *
     * @param KafkaMessage $kafkaMessage
     * @return bool True to continue consuming
     */
    private function processMessage(KafkaMessage $kafkaMessage): bool
    {
        // Memory management
        $this->memoryManager->tick();

        if ($kafkaMessage->hasError()) {
            return true; // Continue on error (handled by client)
        }

        $topicName = $kafkaMessage->topic;
        $payload = $kafkaMessage->payload;
        $messageKey = $kafkaMessage->key;

        if (empty($topicName) || empty($payload)) {
            return true;
        }

        $subscriptions = $this->subscriptions[$topicName] ?? null;
        if (!$subscriptions && empty($this->wildcardSubscriptions)) {
            return true;
        }

        $startTime = microtime(true);

        try {
            $message = Message::fromJson($payload);

            // Channel from message key (most reliable) or message data
            $channel = $messageKey ?? $message->getChannel() ?? $this->extractChannelFromTopic($topicName);

            // Find and execute callback with priority:
            // 1. Exact channel match in subscriptions
            // 2. Wildcard pattern match
            // 3. First available callback (fallback)
            $callback = $this->findCallback($subscriptions, $channel);

            if ($callback !== null) {
                $callback($message);
            }

            // Commit offset after successful processing
            if ($this->config['manual_commit'] ?? false) {
                $this->client->commit($kafkaMessage);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordConsume('kafka', 1, $duration);
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordConsume('kafka', 0, $duration);
            BrokerMetrics::recordError('kafka', 'process_message');

            error_log("Kafka message processing error on {$topicName}: {$e->getMessage()}");
            // Don't re-throw - let consumer continue
        }

        return true;
    }

    /**
     * Find callback for a channel with wildcard matching support.
     *
     * @param array<string, callable>|null $subscriptions Topic subscriptions
     * @param string $channel Channel name from message
     * @return callable|null
     */
    private function findCallback(?array $subscriptions, string $channel): ?callable
    {
        // 1. Exact match in topic subscriptions
        if ($subscriptions !== null && isset($subscriptions[$channel])) {
            return $subscriptions[$channel];
        }

        // 2. Wildcard pattern match
        foreach ($this->wildcardSubscriptions as $pattern => $callback) {
            if ($this->matchesWildcard($channel, $pattern)) {
                return $callback;
            }
        }

        // 3. Fallback: first available callback in subscriptions
        if ($subscriptions !== null) {
            foreach ($subscriptions as $cb) {
                if (is_callable($cb)) {
                    return $cb;
                }
            }
        }

        return null;
    }

    /**
     * Check if channel matches wildcard pattern.
     *
     * Supports patterns like:
     * - 'events.*' matches 'events.stream', 'events.login' (single segment)
     * - 'events.**' matches 'events.stream.nested' (any depth)
     * - 'user.*.notifications' matches 'user.123.notifications'
     *
     * @param string $channel Channel name
     * @param string $pattern Wildcard pattern
     * @return bool
     */
    private function matchesWildcard(string $channel, string $pattern): bool
    {
        // Handle '**' (match any depth) vs '*' (match single segment)
        $regex = preg_quote($pattern, '/');

        // '**' matches any characters including dots (any depth)
        $regex = str_replace('\\*\\*', '.*', $regex);

        // '*' matches only non-dot characters (single segment)
        $regex = str_replace('\\*', '[^.]+', $regex);

        return (bool) preg_match("/^{$regex}$/", $channel);
    }

    /**
     * Stop consuming messages.
     *
     * @return void
     */
    public function stopConsuming(): void
    {
        $this->client->stopConsuming();
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(string $channel): void
    {
        $topicName = $this->getTopicName($channel);

        if (isset($this->subscriptions[$topicName][$channel])) {
            unset($this->subscriptions[$topicName][$channel]);

            if (empty($this->subscriptions[$topicName])) {
                unset($this->subscriptions[$topicName]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriberCount(string $channel): int
    {
        $topicName = $this->getTopicName($channel);
        return isset($this->subscriptions[$topicName][$channel]) ? 1 : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->client->isConnected();
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        $this->client->disconnect();
        $this->connected = false;
        $this->subscriptions = [];
        $this->partitionCache = [];

        BrokerMetrics::recordConnectionEvent('kafka', 'disconnect');
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'kafka-improved';
    }

    /**
     * Get the underlying Kafka client.
     *
     * @return KafkaClientInterface
     */
    public function getClient(): KafkaClientInterface
    {
        return $this->client;
    }

    /**
     * Get Kafka topic name for a channel.
     *
     * @param string $channel Channel name
     * @return string Topic name
     */
    private function getTopicName(string $channel): string
    {
        return $this->topicStrategy->getTopicName($channel);
    }

    /**
     * Get partition number for a channel with TTL-based caching.
     *
     * Note: Currently unused - we let Kafka auto-assign partitions via message key.
     * Keeping this method for future use when manual partition control is needed
     * (e.g., when topic partition count is known and consistent).
     *
     * @param string $channel Channel name
     * @param string $topicName Topic name
     * @return int Partition number
     */
    private function getPartition(string $channel, string $topicName): int
    {
        $cacheKey = "{$topicName}:{$channel}";
        $now = time();

        // Check if cache exists and is not expired
        if (isset($this->partitionCache[$cacheKey])) {
            $cached = $this->partitionCache[$cacheKey];
            if ($now - $cached['cached_at'] < self::PARTITION_CACHE_TTL) {
                return $cached['partition'];
            }

            // Cache expired, remove it
            unset($this->partitionCache[$cacheKey]);
        }

        // Calculate partition
        $partitionCount = (int) ($this->config['default_partitions'] ?? 10);
        $partition = $this->topicStrategy->getPartition($channel, $partitionCount);

        // Store in cache with timestamp
        $this->partitionCache[$cacheKey] = [
            'partition' => $partition,
            'cached_at' => $now,
        ];

        return $partition;
    }

    /**
     * Get message key for partitioning.
     *
     * @param string $channel Channel name
     * @return string|null Message key
     */
    private function getMessageKey(string $channel): ?string
    {
        return $this->topicStrategy->getMessageKey($channel);
    }

    /**
     * Extract channel name from topic name.
     *
     * @param string $topicName Topic name
     * @return string Channel name
     */
    private function extractChannelFromTopic(string $topicName): string
    {
        $prefix = $this->config['topic_prefix'] ?? 'realtime';

        if (str_starts_with($topicName, $prefix . '_')) {
            return substr($topicName, strlen($prefix) + 1);
        }

        return $topicName;
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(): HealthCheckResult
    {
        if (!$this->connected) {
            return HealthCheckResult::unhealthy('Kafka broker not connected');
        }

        $start = microtime(true);

        try {
            if (!$this->client->isConnected()) {
                return HealthCheckResult::unhealthy('Kafka client connection lost');
            }

            $latencyMs = (microtime(true) - $start) * 1000;

            return HealthCheckResult::healthy(
                message: 'Kafka connection healthy',
                details: [
                    'client' => $this->client->getName(),
                    'subscriptions' => count($this->subscriptions),
                    'topics' => array_keys($this->subscriptions),
                    'circuit_breaker' => $this->circuitBreaker->getState()->value,
                    'memory_stats' => $this->memoryManager->getStats(),
                    'metrics' => BrokerMetrics::getMetrics('kafka'),
                ],
                latencyMs: $latencyMs
            );
        } catch (\Throwable $e) {
            return HealthCheckResult::unhealthy(
                message: "Kafka health check failed: {$e->getMessage()}",
                details: ['exception' => $e::class]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHealthCheckName(): string
    {
        return 'kafka-broker-improved';
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
