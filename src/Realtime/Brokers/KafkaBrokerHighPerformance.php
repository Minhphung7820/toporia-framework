<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

use Toporia\Framework\Realtime\Brokers\CircuitBreaker\CircuitBreaker;
use Toporia\Framework\Realtime\Brokers\Kafka\AsyncProducerQueue;
use Toporia\Framework\Realtime\Brokers\Kafka\BatchProducer;
use Toporia\Framework\Realtime\Brokers\Kafka\Client\{KafkaClientInterface, KafkaMessage, RdKafkaClientImproved};
use Toporia\Framework\Realtime\Brokers\Kafka\DeadLetterQueue;
use Toporia\Framework\Realtime\Brokers\Kafka\ProducerPool;
use Toporia\Framework\Realtime\Brokers\Kafka\SharedMemoryQueue;
use Toporia\Framework\Realtime\Brokers\Kafka\TopicStrategy\TopicStrategyFactory;
use Toporia\Framework\Realtime\Consumer\Contracts\BatchConsumerHandlerInterface;
use Toporia\Framework\Realtime\Consumer\Contracts\ConsumerContext;
use Toporia\Framework\Realtime\Contracts\{BrokerInterface, HealthCheckableInterface, HealthCheckResult, MessageInterface, TopicStrategyInterface};
use Toporia\Framework\Realtime\Exceptions\BrokerException;
use Toporia\Framework\Realtime\Metrics\{BrokerMetrics, KafkaMetricsCollector};
use Toporia\Framework\Realtime\{Message, RealtimeManager};

/**
 * Class KafkaBrokerHighPerformance
 *
 * High-performance Kafka broker optimized for maximum throughput.
 * Integrates: ProducerPool, AsyncQueue, BatchConsumer, DLQ, Metrics.
 *
 * Performance targets:
 * - Producer: 100K-500K msg/s (async queue + pool)
 * - Consumer: 50K-200K msg/s (batch processing + parallel workers)
 * - Latency: <5ms p99 (async mode), <50ms p99 (sync mode)
 *
 * Features:
 * - Async producer queue for non-blocking HTTP
 * - Producer pool for parallel I/O
 * - Batch consumer support for high throughput
 * - Dead Letter Queue for failed messages
 * - Comprehensive metrics collection
 * - Circuit breaker for fault tolerance
 * - Memory management for long-running processes
 *
 * Usage modes:
 * - HTTP: Async queue (non-blocking, fire-and-forget)
 * - CLI: Direct publish with batching
 * - Worker: Background flush of async queue
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     3.0.0
 */
final class KafkaBrokerHighPerformance implements BrokerInterface, HealthCheckableInterface
{
    private KafkaClientInterface $client;
    private TopicStrategyInterface $topicStrategy;
    private bool $connected = false;
    private CircuitBreaker $circuitBreaker;
    private MemoryManager $memoryManager;

    // High-performance components
    private ?ProducerPool $producerPool = null;
    private ?AsyncProducerQueue $asyncQueue = null;
    private ?SharedMemoryQueue $sharedQueue = null;
    private ?BatchProducer $batchProducer = null;
    private ?DeadLetterQueue $dlq = null;
    private KafkaMetricsCollector $metrics;

    /**
     * @var array<string, array<string, callable>> Channel subscriptions [topic => [channel => callback]]
     */
    private array $subscriptions = [];

    /**
     * @var array<string, callable> Wildcard pattern subscriptions [pattern => callback]
     */
    private array $wildcardSubscriptions = [];

    /**
     * @var bool Whether to use async queue for publish
     */
    private bool $useAsyncQueue;

    /**
     * @var bool Whether to use producer pool
     */
    private bool $useProducerPool;

    /**
     * @var bool Whether to use shared memory queue (APCu)
     */
    private bool $useSharedMemory;

    /**
     * @var int Pool size for producers
     */
    private int $poolSize;

    /**
     * @param array<string, mixed> $config Kafka configuration
     * @param RealtimeManager|null $manager Realtime manager instance
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?RealtimeManager $manager = null
    ) {
        // Configuration
        $this->useAsyncQueue = (bool) ($config['async_queue'] ?? (PHP_SAPI !== 'cli'));
        $this->useProducerPool = (bool) ($config['producer_pool'] ?? false);
        $this->useSharedMemory = (bool) ($config['shared_memory'] ?? false);
        $this->poolSize = (int) ($config['pool_size'] ?? 4);

        // Core components
        $this->client = $this->createClient($config);
        $this->topicStrategy = TopicStrategyFactory::create($config);

        $this->circuitBreaker = new CircuitBreaker(
            name: 'kafka-hp',
            failureThreshold: $config['circuit_breaker_threshold'] ?? 5,
            timeout: $config['circuit_breaker_timeout'] ?? 60
        );

        $this->memoryManager = new MemoryManager();

        // Metrics collector
        $this->metrics = KafkaMetricsCollector::getInstance('toporia_kafka', [
            'broker' => 'kafka-hp',
            'host' => gethostname() ?: 'unknown',
        ]);

        // Initialize high-performance components
        $this->initializeComponents();
    }

    /**
     * Initialize high-performance components.
     */
    private function initializeComponents(): void
    {
        // Producer pool (optional, for ultra-high throughput)
        if ($this->useProducerPool) {
            $brokers = $this->normalizeBrokers($this->config['brokers'] ?? ['localhost:9092']);
            $this->producerPool = new ProducerPool(
                implode(',', $brokers),
                $this->poolSize,
                $this->config['producer_config'] ?? []
            );
        }

        // Async queue (for HTTP non-blocking)
        if ($this->useAsyncQueue) {
            $this->asyncQueue = new AsyncProducerQueue(
                maxSize: (int) ($this->config['queue_max_size'] ?? 131072),
                batchSize: (int) ($this->config['batch_size'] ?? 1000),
                flushIntervalMs: (int) ($this->config['flush_interval_ms'] ?? 50)
            );
        }

        // Shared memory queue (for true inter-process async with APCu)
        if ($this->useSharedMemory) {
            $this->sharedQueue = new SharedMemoryQueue(
                name: $this->config['shared_queue_name'] ?? 'kafka_hp_queue',
                maxSize: (int) ($this->config['shared_queue_max_size'] ?? 1000000),
                ttl: (int) ($this->config['shared_queue_ttl'] ?? 300)
            );

            // Batch producer for CLI workers
            if (PHP_SAPI === 'cli') {
                $brokers = $this->normalizeBrokers($this->config['brokers'] ?? ['localhost:9092']);
                $this->batchProducer = new BatchProducer(
                    implode(',', $brokers),
                    $this->config['producer_config'] ?? [],
                    (int) ($this->config['batch_size'] ?? 10000),
                    (int) ($this->config['flush_interval_ms'] ?? 100)
                );
                $this->batchProducer->setMetrics($this->metrics);
            }
        }

        // Dead Letter Queue
        $dlqEnabled = (bool) ($this->config['dlq_enabled'] ?? false);
        $this->dlq = new DeadLetterQueue(
            prefix: $this->config['dlq_prefix'] ?? 'dlq',
            maxRetries: (int) ($this->config['dlq_max_retries'] ?? 3),
            enabled: $dlqEnabled
        );

        // Connect
        $this->connect();
    }

    /**
     * Connect to Kafka.
     */
    private function connect(): void
    {
        try {
            $this->circuitBreaker->call(function () {
                $this->client->connect();
            });

            // Initialize producer pool if enabled
            if ($this->producerPool !== null) {
                $this->producerPool->initialize();
            }

            // Set DLQ client
            if ($this->dlq !== null) {
                $this->dlq->setClient($this->client);
            }

            $this->connected = true;
            $this->metrics->recordConnection('connect');
            BrokerMetrics::recordConnectionEvent('kafka', 'connect');
        } catch (\Throwable $e) {
            $this->metrics->recordConnection('connect_failed');
            BrokerMetrics::recordConnectionEvent('kafka', 'connect_failed');
            throw BrokerException::connectionFailed('kafka', $e->getMessage(), $e);
        }
    }

    /**
     * Create Kafka client instance.
     *
     * @param array<string, mixed> $config
     * @return KafkaClientInterface
     */
    private function createClient(array $config): KafkaClientInterface
    {
        if (!extension_loaded('rdkafka')) {
            throw BrokerException::invalidConfiguration(
                'kafka',
                'rdkafka extension is required for KafkaBrokerHighPerformance. Install: pecl install rdkafka'
            );
        }

        $brokers = $this->normalizeBrokers($config['brokers'] ?? ['localhost:9092']);
        $consumerGroup = (string) ($config['consumer_group'] ?? 'realtime-hp');
        $manualCommit = (bool) ($config['manual_commit'] ?? true);
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
     * {@inheritdoc}
     *
     * High-performance publish with async queue support.
     */
    public function publish(string $channel, MessageInterface $message): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('kafka');
        }

        $topicName = $this->topicStrategy->getTopicName($channel);
        $key = $this->topicStrategy->getMessageKey($channel);
        $payload = $message->toJson();

        $startTime = microtime(true);

        try {
            // Route based on mode (priority order)
            if ($this->sharedQueue !== null && $this->sharedQueue->isAvailable() && PHP_SAPI !== 'cli') {
                // HTTP: Use shared memory queue (fastest, true async via APCu)
                $this->publishViaSharedMemory($topicName, $payload, $key);
            } elseif ($this->asyncQueue !== null && PHP_SAPI !== 'cli') {
                // HTTP: Use in-process async queue (non-blocking)
                $this->publishAsync($topicName, $payload, $key);
            } elseif ($this->producerPool !== null) {
                // CLI with pool: Use producer pool
                $this->publishViaPool($topicName, $payload, $key);
            } elseif ($this->batchProducer !== null) {
                // CLI with batch producer
                $this->publishViaBatch($topicName, $payload, $key);
            } else {
                // Default: Direct publish
                $this->publishDirect($topicName, $payload, $key);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->metrics->recordPublish($topicName, $duration, true);
            BrokerMetrics::recordPublish('kafka', $channel, $duration, true);

        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->metrics->recordPublish($topicName, $duration, false);
            $this->metrics->recordError('publish');
            BrokerMetrics::recordPublish('kafka', $channel, $duration, false);
            throw BrokerException::publishFailed('kafka', $channel, $e->getMessage(), $e);
        }
    }

    /**
     * Publish via async queue (non-blocking).
     */
    private function publishAsync(string $topic, string $payload, ?string $key): void
    {
        if ($this->asyncQueue === null) {
            throw new \RuntimeException('Async queue not initialized');
        }

        $success = $this->asyncQueue->enqueue($topic, $payload, $key);

        if (!$success) {
            // Queue full - fallback to direct publish
            $this->publishDirect($topic, $payload, $key);
        }

        // Check if we should flush
        if ($this->asyncQueue->shouldFlush()) {
            $this->flushAsyncQueue();
        }
    }

    /**
     * Publish via shared memory queue (APCu).
     *
     * Fastest option for HTTP - true inter-process async.
     * Messages are picked up by kafka:flush-worker daemon.
     */
    private function publishViaSharedMemory(string $topic, string $payload, ?string $key): void
    {
        if ($this->sharedQueue === null) {
            throw new \RuntimeException('Shared memory queue not initialized');
        }

        $success = $this->sharedQueue->enqueue($topic, $payload, $key);

        if (!$success) {
            // Queue full - fallback to async queue or direct
            if ($this->asyncQueue !== null) {
                $this->publishAsync($topic, $payload, $key);
            } else {
                $this->publishDirect($topic, $payload, $key);
            }
        }
    }

    /**
     * Publish via producer pool.
     */
    private function publishViaPool(string $topic, string $payload, ?string $key): void
    {
        if ($this->producerPool === null) {
            throw new \RuntimeException('Producer pool not initialized');
        }

        $this->producerPool->publish($topic, $payload, null, $key);
    }

    /**
     * Publish via batch producer.
     */
    private function publishViaBatch(string $topic, string $payload, ?string $key): void
    {
        if ($this->batchProducer === null) {
            throw new \RuntimeException('Batch producer not initialized');
        }

        $this->batchProducer->produce($topic, $payload, $key);
    }

    /**
     * Direct publish via client.
     */
    private function publishDirect(string $topic, string $payload, ?string $key): void
    {
        $this->circuitBreaker->call(function () use ($topic, $payload, $key) {
            $this->client->publish($topic, $payload, null, $key);
        });
    }

    /**
     * Flush async queue.
     *
     * Call this in shutdown handler or background worker.
     *
     * @param bool $force Force flush regardless of batch size
     * @return int Number of messages flushed
     */
    public function flushAsyncQueue(bool $force = false): int
    {
        if ($this->asyncQueue === null) {
            return 0;
        }

        return $this->asyncQueue->flush($this->client, $force);
    }

    /**
     * Drain all pending messages.
     *
     * Call this on shutdown to ensure all messages are delivered.
     *
     * @param int $timeoutMs Maximum wait time
     * @return int Total messages drained
     */
    public function drain(int $timeoutMs = 30000): int
    {
        $total = 0;

        // Drain async queue
        if ($this->asyncQueue !== null) {
            $total += $this->asyncQueue->drain($this->client, $timeoutMs);
        }

        // Flush producer pool
        if ($this->producerPool !== null) {
            $this->producerPool->flushAll($timeoutMs);
        }

        // Flush client
        $this->client->flush($timeoutMs);

        return $total;
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(string $channel, callable $callback): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('kafka');
        }

        $topicName = $this->topicStrategy->getTopicName($channel);

        if (!isset($this->subscriptions[$topicName])) {
            $this->subscriptions[$topicName] = [];
        }
        $this->subscriptions[$topicName][$channel] = $callback;

        if (str_contains($channel, '*')) {
            $this->wildcardSubscriptions[$channel] = $callback;
        }
    }

    /**
     * Start consuming with batch support.
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
     * Consume messages in batches.
     *
     * @param BatchConsumerHandlerInterface $handler Batch handler
     * @param int $timeoutMs Poll timeout
     * @return void
     */
    public function consumeBatch(BatchConsumerHandlerInterface $handler, int $timeoutMs = 1000): void
    {
        $topics = $this->topicStrategy->getTopicsForChannels($handler->getChannels());

        try {
            $this->client->subscribe($topics);
        } catch (\Throwable $e) {
            throw BrokerException::subscribeFailed('kafka', implode(',', $topics), $e->getMessage(), $e);
        }

        $batch = [];
        $batchSize = $handler->getBatchSize();
        $batchTimeout = $handler->getBatchTimeout();
        $lastBatchTime = microtime(true);

        $this->client->consume(
            callback: function (KafkaMessage $kafkaMessage) use ($handler, &$batch, $batchSize, $batchTimeout, &$lastBatchTime) {
                $this->memoryManager->tick();

                if ($kafkaMessage->hasError()) {
                    return true;
                }

                // Parse message
                $message = $this->parseKafkaMessage($kafkaMessage);
                if ($message !== null) {
                    $batch[] = $message;
                }

                // Check if we should process batch
                $elapsed = (microtime(true) - $lastBatchTime) * 1000;
                $shouldProcess = count($batch) >= $batchSize || $elapsed >= $batchTimeout;

                if ($shouldProcess && !empty($batch)) {
                    $this->processBatch($handler, $batch, $kafkaMessage);
                    $batch = [];
                    $lastBatchTime = microtime(true);
                }

                return true;
            },
            timeoutMs: $timeoutMs,
            batchSize: $batchSize
        );
    }

    /**
     * Process a batch of messages.
     */
    private function processBatch(BatchConsumerHandlerInterface $handler, array $messages, KafkaMessage $lastKafkaMessage): void
    {
        $startTime = microtime(true);
        $topic = $lastKafkaMessage->topic ?? 'unknown';

        try {
            $context = new ConsumerContext(
                driver: 'kafka-hp',
                handlerName: $handler->getName(),
                channel: $topic,
                processId: getmypid() ?: 0,
                startedAt: microtime(true)
            );

            $failed = $handler->handleBatch($messages, $context);

            // Send failed messages to DLQ
            if (!empty($failed) && $this->dlq !== null && $this->dlq->isEnabled()) {
                foreach ($failed as $index) {
                    if (isset($messages[$index])) {
                        $this->dlq->send(
                            $topic,
                            $messages[$index],
                            new \RuntimeException('Batch processing failed'),
                            0
                        );
                    }
                }
            }

            // Commit if per-batch commit
            if ($handler->shouldCommitPerBatch() && ($this->config['manual_commit'] ?? false)) {
                $this->client->commit($lastKafkaMessage);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->metrics->recordConsume($topic, count($messages), $duration, count($failed));

            $handler->onBatchSuccess($messages, $context, $duration);

        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->metrics->recordConsume($topic, 0, $duration, count($messages));
            $this->metrics->recordError('batch_consume');

            // Send all to DLQ
            if ($this->dlq !== null && $this->dlq->isEnabled()) {
                foreach ($messages as $msg) {
                    $this->dlq->send($topic, $msg, $e, 0);
                }
            }

            error_log("[KafkaBrokerHP] Batch processing failed: {$e->getMessage()}");
        }
    }

    /**
     * Process a single message.
     */
    private function processMessage(KafkaMessage $kafkaMessage): bool
    {
        $this->memoryManager->tick();

        if ($kafkaMessage->hasError()) {
            return true;
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
            $channel = $messageKey ?? $message->getChannel() ?? $topicName;

            $callback = $this->findCallback($subscriptions, $channel);

            if ($callback !== null) {
                $callback($message);
            }

            if ($this->config['manual_commit'] ?? false) {
                $this->client->commit($kafkaMessage);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->metrics->recordConsume($topicName, 1, $duration);
            BrokerMetrics::recordConsume('kafka', 1, $duration);

        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->metrics->recordConsume($topicName, 0, $duration, 1);
            $this->metrics->recordError('process_message');
            BrokerMetrics::recordError('kafka', 'process_message');

            // Send to DLQ
            if ($this->dlq !== null && $this->dlq->isEnabled()) {
                $message = Message::fromJson($payload);
                $this->dlq->send($topicName, $message, $e, 0);
            }

            error_log("[KafkaBrokerHP] Message processing error: {$e->getMessage()}");
        }

        return true;
    }

    /**
     * Parse Kafka message to MessageInterface.
     */
    private function parseKafkaMessage(KafkaMessage $kafkaMessage): ?MessageInterface
    {
        if (empty($kafkaMessage->payload)) {
            return null;
        }

        try {
            return Message::fromJson($kafkaMessage->payload);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Find callback for a channel.
     */
    private function findCallback(?array $subscriptions, string $channel): ?callable
    {
        if ($subscriptions !== null && isset($subscriptions[$channel])) {
            return $subscriptions[$channel];
        }

        foreach ($this->wildcardSubscriptions as $pattern => $callback) {
            if ($this->matchesWildcard($channel, $pattern)) {
                return $callback;
            }
        }

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
     */
    private function matchesWildcard(string $channel, string $pattern): bool
    {
        $regex = preg_quote($pattern, '/');
        $regex = str_replace('\\*\\*', '.*', $regex);
        $regex = str_replace('\\*', '[^.]+', $regex);
        return (bool) preg_match("/^{$regex}$/", $channel);
    }

    /**
     * {@inheritdoc}
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
        $topicName = $this->topicStrategy->getTopicName($channel);

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
        $topicName = $this->topicStrategy->getTopicName($channel);
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

        // Drain all pending
        $this->drain(5000);

        // Shutdown pool
        if ($this->producerPool !== null) {
            $this->producerPool->shutdown(2000);
        }

        $this->client->disconnect();
        $this->connected = false;
        $this->subscriptions = [];

        $this->metrics->recordConnection('disconnect');
        BrokerMetrics::recordConnectionEvent('kafka', 'disconnect');
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'kafka-hp';
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
                message: 'Kafka HP connection healthy',
                details: [
                    'mode' => $this->getPublishMode(),
                    'async_queue' => $this->asyncQueue?->getStats(),
                    'shared_queue' => $this->sharedQueue?->getStats(),
                    'producer_pool' => $this->producerPool?->getStats(),
                    'batch_producer' => $this->batchProducer?->getStats(),
                    'dlq' => $this->dlq?->getStats(),
                    'subscriptions' => count($this->subscriptions),
                    'circuit_breaker' => $this->circuitBreaker->getState()->value,
                    'memory' => $this->memoryManager->getStats(),
                    'metrics' => $this->metrics->getAll(),
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
        return 'kafka-broker-hp';
    }

    /**
     * Get metrics in Prometheus format.
     *
     * @return string
     */
    public function getPrometheusMetrics(): string
    {
        return $this->metrics->toPrometheus();
    }

    /**
     * Get metrics as JSON.
     *
     * @return string
     */
    public function getJsonMetrics(): string
    {
        return $this->metrics->toJson();
    }

    /**
     * Get the async queue instance.
     *
     * @return AsyncProducerQueue|null
     */
    public function getAsyncQueue(): ?AsyncProducerQueue
    {
        return $this->asyncQueue;
    }

    /**
     * Get the producer pool instance.
     *
     * @return ProducerPool|null
     */
    public function getProducerPool(): ?ProducerPool
    {
        return $this->producerPool;
    }

    /**
     * Get the DLQ instance.
     *
     * @return DeadLetterQueue|null
     */
    public function getDlq(): ?DeadLetterQueue
    {
        return $this->dlq;
    }

    /**
     * Get the shared memory queue instance.
     *
     * @return SharedMemoryQueue|null
     */
    public function getSharedQueue(): ?SharedMemoryQueue
    {
        return $this->sharedQueue;
    }

    /**
     * Get the batch producer instance.
     *
     * @return BatchProducer|null
     */
    public function getBatchProducer(): ?BatchProducer
    {
        return $this->batchProducer;
    }

    /**
     * Get current publish mode description.
     *
     * @return string
     */
    public function getPublishMode(): string
    {
        if (PHP_SAPI !== 'cli') {
            if ($this->sharedQueue?->isAvailable()) {
                return 'shared_memory';
            }
            if ($this->asyncQueue !== null) {
                return 'async_queue';
            }
        } else {
            if ($this->producerPool !== null) {
                return 'producer_pool';
            }
            if ($this->batchProducer !== null) {
                return 'batch_producer';
            }
        }

        return 'direct';
    }

    /**
     * Normalize broker list.
     */
    private function normalizeBrokers(mixed $brokers): array
    {
        if (is_string($brokers)) {
            $brokers = explode(',', $brokers);
        }

        if (!is_array($brokers)) {
            $brokers = ['localhost:9092'];
        }

        return array_filter(array_map('trim', $brokers), fn($b) => !empty($b));
    }

    /**
     * Sanitize Kafka configuration.
     */
    private function sanitizeConfig(array $config): array
    {
        $sanitized = [];

        foreach ($config as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $strValue = (string) $value;

            if (in_array($key, ['compression.type', 'compression.codec'])) {
                if ($strValue === '' || strcasecmp($strValue, 'none') === 0) {
                    continue;
                }
            }

            $sanitized[$key] = $strValue;
        }

        return $sanitized;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
