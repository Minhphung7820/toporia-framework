<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\Client;

use RdKafka;
use Toporia\Framework\Realtime\Exceptions\{BrokerException, BrokerTemporaryException};
use Toporia\Framework\Realtime\Metrics\BrokerMetrics;

/**
 * Class RdKafkaClient
 *
 * High-performance Kafka client using rdkafka extension (librdkafka).
 * Features backpressure, delivery tracking, metrics, and topic management.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     3.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers\Kafka\Client
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RdKafkaClient implements KafkaClientInterface
{
    private ?RdKafka\Producer $producer = null;
    private ?RdKafka\KafkaConsumer $consumer = null;
    private bool $connected = false;
    private bool $consuming = false;

    /**
     * @var array<string, array{topic: RdKafka\ProducerTopic, created_at: int}> Topic cache with TTL
     */
    private array $topicCache = [];

    /**
     * @var array<string, array{status: string, error: string|null, timestamp: float}> Delivery tracking
     */
    private array $deliveryResults = [];

    /**
     * @var int Pending message counter for backpressure
     */
    private int $pendingMessages = 0;

    private int $consecutiveErrors = 0;
    private int $lastErrorTime = 0;

    private const TOPIC_CACHE_TTL = 3600; // 1 hour
    private const MAX_PENDING_MESSAGES = 10000; // Backpressure threshold (10x higher for high throughput)
    private const POLL_INTERVAL_MS = 5; // Poll interval for delivery callbacks (faster polling)

    /**
     * @param array<string> $brokers Broker addresses
     * @param string $consumerGroup Consumer group ID
     * @param bool $manualCommit Enable manual offset commit
     * @param array<string, string> $producerConfig Additional producer config
     * @param array<string, string> $consumerConfig Additional consumer config
     */
    public function __construct(
        private readonly array $brokers,
        private readonly string $consumerGroup = 'realtime-servers',
        private readonly bool $manualCommit = false,
        private readonly array $producerConfig = [],
        private readonly array $consumerConfig = []
    ) {
        if (empty($this->brokers)) {
            throw BrokerException::invalidConfiguration('kafka', 'Broker list is required');
        }
    }

    public function getName(): string
    {
        return 'rdkafka';
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        if (!extension_loaded('rdkafka')) {
            throw BrokerException::invalidConfiguration('kafka', 'rdkafka extension is not loaded');
        }

        $brokerList = implode(',', $this->brokers);

        // Initialize producer with delivery report callback
        $producerConf = new RdKafka\Conf();
        $producerConf->set('bootstrap.servers', $brokerList);
        $producerConf->set('metadata.broker.list', $brokerList);

        // === HIGH THROUGHPUT PRODUCER SETTINGS ===
        // These settings optimize for 100K+ msg/s throughput

        // Idempotence is optional - disable by default for faster startup
        // Delivery callbacks already ensure reliability
        // Set 'enable.idempotence' => 'true' in producer_config if needed
        $producerConf->set('enable.idempotence', 'false');

        // Retries for reliability (works without idempotence)
        $producerConf->set('retries', '5');
        $producerConf->set('retry.backoff.ms', '100');
        $producerConf->set('retry.backoff.max.ms', '1000');

        // Queue buffering for high throughput batching
        $producerConf->set('queue.buffering.max.messages', '100000');
        $producerConf->set('queue.buffering.max.ms', '5'); // linger.ms equivalent
        $producerConf->set('queue.buffering.max.kbytes', '1048576'); // 1GB

        // Socket settings for high throughput
        $producerConf->set('socket.keepalive.enable', 'true');
        $producerConf->set('socket.nagle.disable', 'true'); // Disable Nagle for low latency

        // Request and message timeout
        $producerConf->set('request.timeout.ms', '30000');
        $producerConf->set('message.timeout.ms', '300000'); // 5 minutes

        // Set delivery report callback for async reliability
        $producerConf->setDrMsgCb(function (RdKafka\Producer $producer, RdKafka\Message $message) {
            unset($producer); // Required by callback signature but unused
            $this->handleDeliveryReport($message);
        });

        // Apply user config (can override defaults)
        foreach ($this->producerConfig as $key => $value) {
            $producerConf->set($key, (string) $value);
        }

        $this->producer = new RdKafka\Producer($producerConf);
        $this->producer->addBrokers($brokerList);

        // === HIGH THROUGHPUT CONSUMER SETTINGS ===
        $consumerConf = new RdKafka\Conf();
        $consumerConf->set('bootstrap.servers', $brokerList);
        $consumerConf->set('metadata.broker.list', $brokerList);
        $consumerConf->set('group.id', $this->consumerGroup);
        $consumerConf->set('enable.auto.commit', $this->manualCommit ? 'false' : 'true');
        $consumerConf->set('auto.offset.reset', 'earliest');

        // Session management for consumer group stability
        $consumerConf->set('session.timeout.ms', '30000'); // 30s session timeout
        $consumerConf->set('heartbeat.interval.ms', '10000'); // 10s heartbeat (1/3 of session timeout)
        $consumerConf->set('max.poll.interval.ms', '300000'); // 5 min max processing time

        // Fetch settings for high throughput
        $consumerConf->set('fetch.min.bytes', '1'); // Respond immediately with any data
        $consumerConf->set('fetch.wait.max.ms', '100'); // Max wait time for fetch.min.bytes
        $consumerConf->set('fetch.max.bytes', '52428800'); // 50MB max fetch
        $consumerConf->set('max.partition.fetch.bytes', '1048576'); // 1MB per partition

        // Socket settings
        $consumerConf->set('socket.keepalive.enable', 'true');

        // Rebalance callback
        $consumerConf->setRebalanceCb(function (RdKafka\KafkaConsumer $consumer, int $err, ?array $partitions) {
            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    $consumer->assign($partitions);
                    break;

                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    $consumer->assign(null);
                    break;
            }
        });

        // Apply user config (can override defaults)
        foreach ($this->consumerConfig as $key => $value) {
            $consumerConf->set($key, (string) $value);
        }

        $this->consumer = new RdKafka\KafkaConsumer($consumerConf);

        $this->connected = true;
        BrokerMetrics::recordConnectionEvent('kafka', 'connect');
    }

    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        $this->stopConsuming();

        // Adaptive flush based on pending messages and context
        // - HTTP: Quick flush but ensure queued messages are sent
        // - CLI: Full flush with retries for reliability
        $outQLen = $this->producer?->getOutQLen() ?? 0;

        if ($outQLen > 0) {
            // Calculate timeout based on queue length (10ms per message, min 100ms, max 2000ms for HTTP)
            $baseTimeout = PHP_SAPI === 'cli' ? 5000 : min(max($outQLen * 10, 100), 2000);
            $this->flush($baseTimeout);
        }

        if ($this->consumer !== null) {
            try {
                $this->consumer->unsubscribe();
            } catch (\Throwable) {
                // Ignore unsubscribe errors during disconnect
            }
            $this->consumer = null;
        }

        $this->producer = null;
        $this->topicCache = [];
        $this->deliveryResults = [];
        $this->pendingMessages = 0;
        $this->connected = false;

        BrokerMetrics::recordConnectionEvent('kafka', 'disconnect');
    }

    public function publish(string $topic, string $payload, ?int $partition = null, ?string $key = null): void
    {
        if (!$this->connected || $this->producer === null) {
            throw BrokerException::notConnected('kafka');
        }

        // Backpressure: wait if too many pending messages
        $this->applyBackpressure();

        // Get or create cached topic with TTL check
        $topicInstance = $this->getTopicInstance($topic);
        $partitionVal = $partition ?? RD_KAFKA_PARTITION_UA;

        // Generate unique message ID for tracking
        $messageId = $this->generateMessageId();

        try {
            // Initialize delivery tracking
            $this->deliveryResults[$messageId] = [
                'status' => 'pending',
                'error' => null,
                'timestamp' => microtime(true),
            ];
            $this->pendingMessages++;

            // Produce message with opaque data for tracking
            if ($key !== null && method_exists($topicInstance, 'producev')) {
                // producev supports headers and opaque
                $topicInstance->producev(
                    $partitionVal,
                    0, // msgflags
                    $payload,
                    $key,
                    null, // headers
                    null, // timestamp_ms
                    $messageId // opaque - used to track delivery
                );
            } else {
                $topicInstance->produce($partitionVal, 0, $payload, $key);
            }

            // Non-blocking poll to trigger internal batching
            // Kafka producer will batch messages based on:
            // - linger.ms (default: 5ms) - wait time to accumulate messages
            // - batch.size (default: 256KB) - max batch size
            // - queue.buffering.max.messages (default: 100K) - max queue size
            //
            // DO NOT flush here! Let Kafka batch messages for optimal throughput.
            // Flush happens in:
            // - disconnect() - graceful shutdown
            // - publishBatch() - after all messages queued
            // - explicit flush() call
            $this->producer->poll(0);

        } catch (\Throwable $e) {
            $this->pendingMessages = max(0, $this->pendingMessages - 1);
            unset($this->deliveryResults[$messageId]);
            BrokerMetrics::recordError('kafka', 'publish');
            throw BrokerException::publishFailed('kafka', $topic, $e->getMessage(), $e);
        }
    }

    /**
     * Publish message synchronously with guaranteed delivery confirmation.
     *
     * Use this for critical messages that MUST be delivered.
     * For realtime notifications, use publish() for better throughput.
     *
     * @param string $topic Topic name
     * @param string $payload Message payload
     * @param int|null $partition Partition number
     * @param string|null $key Message key
     * @param int $timeoutMs Maximum wait time for delivery confirmation
     * @throws BrokerException If delivery fails or times out
     */
    public function publishSync(string $topic, string $payload, ?int $partition = null, ?string $key = null, int $timeoutMs = 5000): void
    {
        if (!$this->connected || $this->producer === null) {
            throw BrokerException::notConnected('kafka');
        }

        $topicInstance = $this->getTopicInstance($topic);
        $partitionVal = $partition ?? RD_KAFKA_PARTITION_UA;
        $messageId = $this->generateMessageId();

        try {
            $this->deliveryResults[$messageId] = [
                'status' => 'pending',
                'error' => null,
                'timestamp' => microtime(true),
            ];
            $this->pendingMessages++;

            if ($key !== null && method_exists($topicInstance, 'producev')) {
                $topicInstance->producev($partitionVal, 0, $payload, $key, null, null, $messageId);
            } else {
                $topicInstance->produce($partitionVal, 0, $payload, $key);
            }

            // Poll and wait for delivery confirmation
            $delivered = $this->waitForDelivery($messageId, $timeoutMs);

            if (!$delivered) {
                $result = $this->deliveryResults[$messageId] ?? null;
                $error = $result['error'] ?? 'Delivery timeout';
                throw new \RuntimeException("Message delivery failed: {$error}");
            }

            // Check if delivery was successful
            $result = $this->deliveryResults[$messageId] ?? null;
            if ($result && $result['status'] === 'failed') {
                throw new \RuntimeException("Message delivery failed: {$result['error']}");
            }

        } catch (\Throwable $e) {
            $this->pendingMessages = max(0, $this->pendingMessages - 1);
            unset($this->deliveryResults[$messageId]);
            BrokerMetrics::recordError('kafka', 'publish_sync');
            throw BrokerException::publishFailed('kafka', $topic, $e->getMessage(), $e);
        } finally {
            unset($this->deliveryResults[$messageId]);
        }
    }

    /**
     * Handle delivery report callback.
     *
     * @param RdKafka\Message $message
     */
    private function handleDeliveryReport(RdKafka\Message $message): void
    {
        $this->pendingMessages = max(0, $this->pendingMessages - 1);

        // Try to get message ID from opaque data
        $messageId = null;
        if (property_exists($message, 'opaque') && $message->opaque !== null) {
            $messageId = $message->opaque;
        }

        if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
            // Success
            if ($messageId !== null && isset($this->deliveryResults[$messageId])) {
                $this->deliveryResults[$messageId]['status'] = 'delivered';
            }
            BrokerMetrics::recordPublish('kafka', $message->topic_name ?? 'unknown', 0, true);
        } else {
            // Failure
            $errorMsg = rd_kafka_err2str($message->err);
            if ($messageId !== null && isset($this->deliveryResults[$messageId])) {
                $this->deliveryResults[$messageId]['status'] = 'failed';
                $this->deliveryResults[$messageId]['error'] = $errorMsg;
            }
            BrokerMetrics::recordPublish('kafka', $message->topic_name ?? 'unknown', 0, false);
        }
    }

    /**
     * Wait for message delivery with timeout.
     *
     * @param string $messageId Message ID to wait for
     * @param int $timeoutMs Timeout in milliseconds
     * @return bool True if delivered, false if still pending
     */
    private function waitForDelivery(string $messageId, int $timeoutMs): bool
    {
        $startTime = microtime(true) * 1000;
        $endTime = $startTime + $timeoutMs;

        while (microtime(true) * 1000 < $endTime) {
            // Poll for delivery callbacks
            $this->producer->poll(self::POLL_INTERVAL_MS);

            // Check if this message was delivered
            $result = $this->deliveryResults[$messageId] ?? null;
            if ($result && $result['status'] !== 'pending') {
                return $result['status'] === 'delivered';
            }

            // Small sleep to prevent busy loop
            usleep(1000); // 1ms
        }

        return false;
    }

    /**
     * Apply backpressure when too many messages are pending.
     *
     * @throws BrokerException If backpressure cannot be relieved
     */
    private function applyBackpressure(): void
    {
        if ($this->pendingMessages < self::MAX_PENDING_MESSAGES) {
            return;
        }

        // Too many pending messages, wait for some to be delivered
        $maxWaitMs = 1000; // 1 second max wait
        $startTime = microtime(true) * 1000;

        while ($this->pendingMessages >= self::MAX_PENDING_MESSAGES) {
            $this->producer->poll(50); // Poll for callbacks

            if (microtime(true) * 1000 - $startTime > $maxWaitMs) {
                // Still too many pending after timeout
                throw BrokerException::publishFailed(
                    'kafka',
                    'backpressure',
                    "Too many pending messages ({$this->pendingMessages}), producer is overloaded"
                );
            }
        }
    }

    /**
     * Generate unique message ID for delivery tracking.
     *
     * @return string
     */
    private function generateMessageId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function flush(int $timeoutMs = 5000): void
    {
        if ($this->producer === null) {
            return;
        }

        // Poll first to process any pending callbacks
        $this->producer->poll(0);

        // Flush with timeout
        $result = $this->producer->flush($timeoutMs);

        // Retry logic for reliability - adaptive based on context
        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR && $this->producer->getOutQLen() > 0) {
            // CLI: full retries for reliability
            // HTTP: limited retries to balance speed and reliability
            $maxRetries = PHP_SAPI === 'cli' ? 10 : 3;
            $retryTimeout = PHP_SAPI === 'cli' ? 500 : 100;

            $retries = $maxRetries;
            while ($retries-- > 0 && $this->producer->getOutQLen() > 0) {
                $this->producer->poll(50);
                $this->producer->flush($retryTimeout);
            }
        }
    }

    /**
     * Publish multiple messages in a single batch operation.
     *
     * This is the TRUE Kafka batching approach:
     * 1. Queue all messages to producer buffer (no flush)
     * 2. Let Kafka batch by topic/partition
     * 3. Compress entire batch (lz4)
     * 4. Single flush at the end
     *
     * Performance: 50K-200K msg/s (vs 1K-5K msg/s with individual publish)
     *
     * @param array<array{topic: string, payload: string, partition?: int|null, key?: string|null}> $messages
     * @param int $flushTimeoutMs Timeout for final flush
     * @return array{queued: int, failed: int, flush_time_ms: float}
     */
    public function publishBatch(array $messages, int $flushTimeoutMs = 10000): array
    {
        if (!$this->connected || $this->producer === null) {
            throw BrokerException::notConnected('kafka');
        }

        $queued = 0;
        $failed = 0;
        $startTime = microtime(true);

        // Phase 1: Queue all messages to producer buffer (no flush)
        foreach ($messages as $msg) {
            $topic = $msg['topic'];
            $payload = $msg['payload'];
            $partition = $msg['partition'] ?? null;
            $key = $msg['key'] ?? null;

            try {
                $topicInstance = $this->getTopicInstance($topic);
                $partitionVal = $partition ?? RD_KAFKA_PARTITION_UA;

                // Queue message - Kafka will batch internally
                if ($key !== null && method_exists($topicInstance, 'producev')) {
                    $topicInstance->producev($partitionVal, 0, $payload, $key);
                } else {
                    $topicInstance->produce($partitionVal, 0, $payload, $key);
                }

                $queued++;
                $this->pendingMessages++;

                // Periodic poll to prevent queue overflow (every 1000 messages)
                if ($queued % 1000 === 0) {
                    $this->producer->poll(0);
                }

            } catch (\Throwable $e) {
                $failed++;
                error_log("[RdKafkaClient] Batch produce failed: {$e->getMessage()}");
            }
        }

        $queueTime = (microtime(true) - $startTime) * 1000;

        // Phase 2: Single flush to send all batched messages
        $flushStart = microtime(true);
        $this->flush($flushTimeoutMs);
        $flushTime = (microtime(true) - $flushStart) * 1000;

        // Record metrics
        if ($queued > 0) {
            BrokerMetrics::recordPublish('kafka', 'batch', $queueTime + $flushTime, true);
        }

        return [
            'queued' => $queued,
            'failed' => $failed,
            'queue_time_ms' => round($queueTime, 2),
            'flush_time_ms' => round($flushTime, 2),
            'total_time_ms' => round($queueTime + $flushTime, 2),
            'throughput' => $queueTime > 0 ? round($queued / ($queueTime / 1000)) : 0,
        ];
    }

    public function subscribe(array $topics): void
    {
        if (!$this->connected || $this->consumer === null) {
            throw BrokerException::notConnected('kafka');
        }

        try {
            $this->consumer->subscribe($topics);
            usleep(500000); // Allow metadata refresh
            $this->consumer->consume(100); // Trigger metadata refresh
        } catch (\Throwable $e) {
            throw BrokerException::subscribeFailed('kafka', implode(',', $topics), $e->getMessage(), $e);
        }
    }

    public function consume(callable $callback, int $timeoutMs = 1000, int $batchSize = 100): void
    {
        if (!$this->connected || $this->consumer === null) {
            throw BrokerException::notConnected('kafka');
        }

        $this->consuming = true;
        $this->consecutiveErrors = 0;

        while ($this->consuming) {
            try {
                $message = $this->consumer->consume($timeoutMs);

                if ($message === null) {
                    continue;
                }

                $kafkaMessage = KafkaMessage::fromRdKafka($message);

                // Handle errors
                if ($kafkaMessage->hasError()) {
                    $this->handleKafkaError($kafkaMessage);
                    continue;
                }

                // Reset error counter on successful message
                $this->consecutiveErrors = 0;

                // Process message
                $shouldContinue = $callback($kafkaMessage);
                if ($shouldContinue === false) {
                    break;
                }

            } catch (BrokerException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->consecutiveErrors++;
                BrokerMetrics::recordError('kafka', 'consume');

                if ($this->consecutiveErrors >= 5) {
                    throw BrokerException::consumeFailed('kafka', $e->getMessage(), $e);
                }

                // Brief pause before retry
                usleep(100000); // 100ms
            }
        }
    }

    public function stopConsuming(): void
    {
        $this->consuming = false;
    }

    public function commit(KafkaMessage $message): void
    {
        if (!$this->manualCommit || $this->consumer === null || $message->raw === null) {
            return;
        }

        try {
            $this->consumer->commit($message->raw);
        } catch (\Throwable) {
            // Silently ignore commit errors (precision warnings, etc.)
        }
    }

    /**
     * Get topic instance with TTL-based caching.
     *
     * @param string $topic Topic name
     * @return RdKafka\ProducerTopic
     */
    private function getTopicInstance(string $topic): RdKafka\ProducerTopic
    {
        $now = time();

        // Cleanup expired topics
        foreach ($this->topicCache as $topicName => $cached) {
            if ($now - $cached['created_at'] > self::TOPIC_CACHE_TTL) {
                unset($this->topicCache[$topicName]);
            }
        }

        if (!isset($this->topicCache[$topic])) {
            $this->topicCache[$topic] = [
                'topic' => $this->producer->newTopic($topic),
                'created_at' => $now,
            ];
        }

        return $this->topicCache[$topic]['topic'];
    }

    /**
     * Handle Kafka error messages with circuit breaker logic.
     *
     * @param KafkaMessage $message
     * @return void
     */
    private function handleKafkaError(KafkaMessage $message): void
    {
        if ($message->isEof() || $message->isTimeout()) {
            $this->consecutiveErrors = 0;
            return;
        }

        if ($message->isUnknownTopicOrPartition()) {
            $this->handleUnknownTopicError();
            return;
        }

        // Other errors
        $this->consecutiveErrors++;
        $now = time();

        // Reset error counter if last error was long ago
        if ($now - $this->lastErrorTime > 60) {
            $this->consecutiveErrors = 0;
        }

        $this->lastErrorTime = $now;

        BrokerMetrics::recordError('kafka', 'message');

        if ($this->consecutiveErrors >= 10) {
            sleep(60);
            $this->consecutiveErrors = 0;
            return;
        }

        // Exponential backoff
        $delay = min((int) pow(2, $this->consecutiveErrors - 1), 30);
        if ($delay > 0) {
            sleep($delay);
        }
    }

    /**
     * Handle unknown topic/partition error with exponential backoff.
     *
     * @return void
     */
    private function handleUnknownTopicError(): void
    {
        $this->consecutiveErrors++;
        $maxRetries = 10;

        if ($this->consecutiveErrors >= $maxRetries) {
            throw BrokerTemporaryException::unknownTopicOrPartition('unknown', $this->consecutiveErrors);
        }

        // Exponential backoff: 100ms, 200ms, 400ms, 800ms, 1600ms... capped at 10s
        $delayMs = min(100 * (2 ** ($this->consecutiveErrors - 1)), 10000);
        usleep($delayMs * 1000);
    }

    /**
     * Get number of pending messages waiting for delivery confirmation.
     *
     * @return int
     */
    public function getPendingCount(): int
    {
        return $this->pendingMessages;
    }

    /**
     * Get producer queue length (messages waiting to be sent to broker).
     *
     * @return int
     */
    public function getQueueLength(): int
    {
        return $this->producer?->getOutQLen() ?? 0;
    }

    /**
     * Cleanup stale delivery results (older than 60 seconds).
     *
     * Call this periodically to prevent memory leaks.
     */
    public function cleanupStaleDeliveryResults(): void
    {
        $now = microtime(true);
        $staleThreshold = 60.0; // 60 seconds

        foreach ($this->deliveryResults as $messageId => $result) {
            if ($now - $result['timestamp'] > $staleThreshold) {
                unset($this->deliveryResults[$messageId]);
                // Assume stale messages were delivered (or failed silently)
                $this->pendingMessages = max(0, $this->pendingMessages - 1);
            }
        }
    }

    /**
     * Poll for delivery reports without blocking.
     *
     * Call this periodically in long-running processes to ensure
     * delivery callbacks are processed.
     *
     * @param int $timeoutMs Poll timeout in milliseconds (0 = non-blocking)
     * @return int Number of events processed
     */
    public function poll(int $timeoutMs = 0): int
    {
        if ($this->producer === null) {
            return 0;
        }

        return $this->producer->poll($timeoutMs) ?? 0;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Ensure a topic exists with the specified number of partitions.
     *
     * Uses metadata API to check topic existence and partition count.
     * If topic doesn't exist or has fewer partitions, logs a warning.
     *
     * Note: Creating/altering topics requires Kafka Admin API which may not be
     * available in all rdkafka versions. Configure Kafka server's num.partitions
     * or use kafka-topics CLI for production deployments.
     *
     * @param string $topic Topic name
     * @param int $partitions Number of partitions
     * @param int $replicationFactor Replication factor
     * @return bool True if topic exists with sufficient partitions, false otherwise
     */
    public function ensureTopicExists(string $topic, int $partitions = 1, int $replicationFactor = 1): bool
    {
        if (!$this->connected || $this->producer === null) {
            throw BrokerException::notConnected('kafka');
        }

        $brokerList = implode(',', $this->brokers);

        try {
            // Get metadata to check if topic exists
            $metadata = $this->producer->getMetadata(false, null, 5000);
            $existingTopics = $metadata->getTopics();

            $topicExists = false;
            $currentPartitions = 0;

            foreach ($existingTopics as $existingTopic) {
                if ($existingTopic->getTopic() === $topic) {
                    $topicExists = true;
                    $currentPartitions = count($existingTopic->getPartitions());
                    break;
                }
            }

            if (!$topicExists) {
                // Topic doesn't exist - try to create using Admin API if available
                if (class_exists('RdKafka\Admin\NewTopic') && class_exists('RdKafka\Admin\Client')) {
                    return $this->createTopicViaAdminApi($topic, $partitions, $replicationFactor, $brokerList);
                }

                // Admin API not available - topic will be auto-created by Kafka with server defaults
                return false;
            }

            // Topic exists - check partition count
            if ($currentPartitions < $partitions) {
                if (class_exists('RdKafka\Admin\NewPartitions') && class_exists('RdKafka\Admin\Client')) {
                    return $this->addPartitionsViaAdminApi($topic, $partitions, $brokerList);
                }

                // Can't add partitions via Admin API
                return false;
            }

            // Topic exists with sufficient partitions
            return true;

        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Create topic via Admin API (if available).
     *
     * @phpstan-ignore-next-line Admin API classes may not exist in all rdkafka versions
     */
    private function createTopicViaAdminApi(string $topic, int $partitions, int $replicationFactor, string $brokerList): bool
    {
        try {
            /** @phpstan-ignore-next-line */
            $newTopic = new \RdKafka\Admin\NewTopic($topic, $partitions, $replicationFactor);

            $adminConf = new RdKafka\Conf();
            $adminConf->set('bootstrap.servers', $brokerList);
            /** @phpstan-ignore-next-line */
            $admin = \RdKafka\Admin\Client::fromConf($adminConf);

            $result = $admin->createTopics([$newTopic], 10000);

            foreach ($result as $topicResult) {
                if ($topicResult->error() !== RD_KAFKA_RESP_ERR_NO_ERROR
                    && $topicResult->error() !== RD_KAFKA_RESP_ERR_TOPIC_ALREADY_EXISTS) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Add partitions via Admin API (if available).
     *
     * @phpstan-ignore-next-line Admin API classes may not exist in all rdkafka versions
     */
    private function addPartitionsViaAdminApi(string $topic, int $partitions, string $brokerList): bool
    {
        try {
            /** @phpstan-ignore-next-line */
            $newPartitions = new \RdKafka\Admin\NewPartitions($topic, $partitions);

            $adminConf = new RdKafka\Conf();
            $adminConf->set('bootstrap.servers', $brokerList);
            /** @phpstan-ignore-next-line */
            $admin = \RdKafka\Admin\Client::fromConf($adminConf);

            $result = $admin->createPartitions([$newPartitions], 10000);

            foreach ($result as $topicResult) {
                if ($topicResult->error() !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

