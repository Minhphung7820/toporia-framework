<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\Client;

use Toporia\Framework\Realtime\Exceptions\BrokerException;
use Toporia\Framework\Realtime\Exceptions\BrokerTemporaryException;

/**
 * Class RdKafkaClient
 *
 * High-performance Kafka client using rdkafka extension (librdkafka).
 * Optimized for large-scale production with 100k+ requests/second.
 *
 * Performance optimizations:
 * - Singleton producer pattern (reuse across requests)
 * - Async produce with batched delivery
 * - Non-blocking poll with periodic background flush
 * - Connection pooling ready
 * - Graceful shutdown with pending message flush
 *
 * Throughput: 100k-500k+ msg/s
 * Latency: 1-10ms (async), 10-50ms (sync)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     3.0.0
 * @package     toporia/framework
 */
final class RdKafkaClient implements KafkaClientInterface
{
    /**
     * Singleton producer instance for connection reuse.
     */
    private static ?\RdKafka\Producer $sharedProducer = null;

    /**
     * Track if shutdown handler is registered.
     */
    private static bool $shutdownRegistered = false;

    /**
     * Pending messages counter for monitoring.
     */
    private static int $pendingMessages = 0;

    /**
     * Last poll timestamp for periodic polling.
     */
    private static float $lastPollTime = 0;

    /**
     * Delivery tracking for async reliability.
     *
     * @var array<string, array{status: string, error: string|null, timestamp: float}>
     */
    private static array $deliveryResults = [];

    /**
     * Instance-level producer (fallback if singleton disabled).
     */
    private ?\RdKafka\Producer $producer = null;

    private ?\RdKafka\KafkaConsumer $consumer = null;
    private bool $connected = false;
    private bool $consuming = false;

    /**
     * @var array<string, \RdKafka\ProducerTopic> Topic cache
     */
    private array $topicCache = [];

    /**
     * Error recovery state.
     */
    private int $consecutiveErrors = 0;

    /**
     * Track failed deliveries for monitoring.
     */
    private int $failedDeliveries = 0;

    /**
     * Track successful deliveries.
     */
    private static int $successfulDeliveries = 0;

    /**
     * Use singleton producer for connection reuse.
     */
    private readonly bool $useSingleton;

    /**
     * Poll interval in milliseconds.
     */
    private readonly int $pollIntervalMs;

    /**
     * Maximum pending messages before applying backpressure.
     */
    private const MAX_PENDING_MESSAGES = 10000;

    /**
     * Default wait timeout for delivery confirmation (ms).
     */
    private const DEFAULT_DELIVERY_WAIT_MS = 100;

    /**
     * @param array<string> $brokers Broker addresses
     * @param string $consumerGroup Consumer group ID
     * @param bool $manualCommit Enable manual offset commit
     * @param int $bufferSize Message buffer size (used for batch processing)
     * @param int $flushIntervalMs Flush interval in milliseconds
     * @param array<string, string> $producerConfig Additional producer config
     * @param array<string, string> $consumerConfig Additional consumer config
     */
    public function __construct(
        private readonly array $brokers,
        private readonly string $consumerGroup = 'realtime-servers',
        private readonly bool $manualCommit = false,
        private readonly int $bufferSize = 100,
        private readonly int $flushIntervalMs = 100,
        private readonly array $producerConfig = [],
        private readonly array $consumerConfig = []
    ) {
        if (empty($this->brokers)) {
            throw BrokerException::invalidConfiguration('kafka', 'Broker list is required');
        }

        // Enable singleton by default for HTTP requests (high performance)
        $this->useSingleton = (bool) ($producerConfig['singleton'] ?? true);
        $this->pollIntervalMs = (int) ($producerConfig['poll_interval_ms'] ?? 10);
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

        // Initialize producer (singleton or instance)
        $this->producer = $this->getOrCreateProducer($brokerList);

        // Initialize consumer
        $this->consumer = $this->createConsumer($brokerList);

        // Register shutdown handler for graceful flush
        $this->registerShutdownHandler();

        $this->connected = true;
    }

    /**
     * Get or create singleton producer for connection reuse.
     */
    private function getOrCreateProducer(string $brokerList): \RdKafka\Producer
    {
        // Use singleton for better performance
        if ($this->useSingleton && self::$sharedProducer !== null) {
            return self::$sharedProducer;
        }

        $producerConf = new \RdKafka\Conf();
        $producerConf->set('bootstrap.servers', $brokerList);
        $producerConf->set('log_level', '3');

        // ========== HIGH THROUGHPUT CONFIG ==========
        // Acks: 1 = leader only (fast), 'all' = all replicas (durable)
        $producerConf->set('acks', '1');

        // Batching - maximize throughput
        $producerConf->set('queue.buffering.max.ms', '10');        // 10ms batching window
        $producerConf->set('queue.buffering.max.messages', '100000'); // 100k message buffer
        $producerConf->set('batch.num.messages', '10000');         // Batch up to 10k msgs
        $producerConf->set('batch.size', '1048576');               // 1MB batch size
        $producerConf->set('linger.ms', '5');                      // Wait 5ms to batch

        // Compression - lz4 is fastest
        $producerConf->set('compression.type', 'lz4');
        $producerConf->set('compression.level', '1');              // Fast compression

        // Reliability - retries without idempotence for faster startup
        // Idempotence requires PID from broker which can cause "Coordinator load in progress" errors
        // Delivery callbacks already ensure reliability
        $producerConf->set('retries', '3');
        $producerConf->set('retry.backoff.ms', '100');
        $producerConf->set('request.timeout.ms', '5000');          // 5s timeout
        $producerConf->set('message.timeout.ms', '30000');         // 30s total timeout
        $producerConf->set('enable.idempotence', 'false');         // Disable for speed & avoid PID issues

        // Socket optimization
        $producerConf->set('socket.keepalive.enable', 'true');
        $producerConf->set('socket.nagle.disable', 'true');
        $producerConf->set('socket.send.buffer.bytes', '1048576'); // 1MB send buffer
        $producerConf->set('socket.receive.buffer.bytes', '1048576');

        // Connection pooling
        $producerConf->set('connections.max.idle.ms', '540000');   // 9 min idle timeout

        // Error callback - minimal logging
        $producerConf->setErrorCb(function ($kafka, $err, $reason): void {
            if ($err !== RD_KAFKA_RESP_ERR__TRANSPORT && $err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                error_log("[Kafka] Error {$err}: {$reason}");
            }
        });

        // Delivery report - track success/failure with message ID
        $producerConf->setDrMsgCb(function ($_kafka, $message): void {
            self::$pendingMessages = max(0, self::$pendingMessages - 1);

            // Get message ID from opaque data if available
            $messageId = null;
            if (property_exists($message, 'opaque') && $message->opaque !== null) {
                $messageId = $message->opaque;
            }

            if ($message->err) {
                $this->failedDeliveries++;
                $errorMsg = rd_kafka_err2str($message->err);

                // Update delivery tracking
                if ($messageId !== null && isset(self::$deliveryResults[$messageId])) {
                    self::$deliveryResults[$messageId]['status'] = 'failed';
                    self::$deliveryResults[$messageId]['error'] = $errorMsg;
                }

                error_log("[Kafka] Delivery failed: {$errorMsg}");
            } else {
                self::$successfulDeliveries++;

                // Update delivery tracking
                if ($messageId !== null && isset(self::$deliveryResults[$messageId])) {
                    self::$deliveryResults[$messageId]['status'] = 'delivered';
                }
            }
        });

        // Apply custom overrides
        foreach ($this->producerConfig as $key => $value) {
            if ($key === 'singleton' || $key === 'poll_interval_ms') {
                continue;
            }
            $producerConf->set($key, (string) $value);
        }

        $producer = new \RdKafka\Producer($producerConf);
        $producer->addBrokers($brokerList);

        // Store as singleton if enabled
        if ($this->useSingleton) {
            self::$sharedProducer = $producer;
        }

        return $producer;
    }

    /**
     * Create consumer instance.
     */
    private function createConsumer(string $brokerList): \RdKafka\KafkaConsumer
    {
        $consumerConf = new \RdKafka\Conf();
        $consumerConf->set('bootstrap.servers', $brokerList);
        $consumerConf->set('group.id', $this->consumerGroup);
        $consumerConf->set('log_level', '3');

        // Auto-commit for simplicity
        $consumerConf->set('enable.auto.commit', $this->manualCommit ? 'false' : 'true');
        $consumerConf->set('auto.offset.reset', 'earliest');
        $consumerConf->set('auto.commit.interval.ms', '1000');

        // Low latency fetching
        $consumerConf->set('fetch.wait.max.ms', '50');
        $consumerConf->set('fetch.min.bytes', '1');
        $consumerConf->set('fetch.max.bytes', '1048576');

        // Session management
        $consumerConf->set('session.timeout.ms', '30000');
        $consumerConf->set('heartbeat.interval.ms', '10000');
        $consumerConf->set('max.poll.interval.ms', '300000');

        // Error callback
        $consumerConf->setErrorCb(function ($kafka, $err, $reason): void {
            if ($err !== RD_KAFKA_RESP_ERR__TRANSPORT && $err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                error_log("[Kafka] Consumer error {$err}: {$reason}");
            }
        });

        foreach ($this->consumerConfig as $key => $value) {
            $consumerConf->set($key, (string) $value);
        }

        return new \RdKafka\KafkaConsumer($consumerConf);
    }

    /**
     * Register shutdown handler for graceful message flush.
     */
    private function registerShutdownHandler(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        register_shutdown_function(function (): void {
            if (self::$sharedProducer !== null && self::$pendingMessages > 0) {
                // Flush remaining messages on shutdown
                self::$sharedProducer->flush(5000);
            }
        });

        self::$shutdownRegistered = true;
    }

    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        $this->stopConsuming();

        // Flush pending messages
        if ($this->producer !== null && !$this->useSingleton) {
            $this->producer->flush(2000);
            $this->producer = null;
        }

        if ($this->consumer !== null) {
            try {
                $this->consumer->unsubscribe();
            } catch (\Throwable) {
                // Ignore
            }
            $this->consumer = null;
        }

        $this->topicCache = [];
        $this->connected = false;
    }

    /**
     * High-performance async publish with delivery tracking.
     *
     * Strategy:
     * - Fire-and-forget to librdkafka internal queue
     * - Track delivery via callbacks with message ID
     * - Apply backpressure when queue is full
     * - Short wait for delivery confirmation (default 100ms)
     *
     * Performance:
     * - Latency: 1-100ms (with delivery wait)
     * - Throughput: 10k-100k+ msg/s
     */
    public function publish(string $topic, string $payload, ?int $partition = null, ?string $key = null): void
    {
        if (!$this->connected || $this->producer === null) {
            throw BrokerException::notConnected('kafka');
        }

        // Apply backpressure if too many pending messages
        $this->applyBackpressure();

        // Get or create cached topic
        if (!isset($this->topicCache[$topic])) {
            $this->topicCache[$topic] = $this->producer->newTopic($topic);
        }

        $topicInstance = $this->topicCache[$topic];
        $partitionValue = $partition ?? RD_KAFKA_PARTITION_UA;

        // Generate message ID for tracking
        $messageId = $this->generateMessageId();

        // Initialize delivery tracking
        self::$deliveryResults[$messageId] = [
            'status' => 'pending',
            'error' => null,
            'timestamp' => microtime(true),
        ];

        // Produce to internal queue with message ID as opaque
        if ($key !== null && method_exists($topicInstance, 'producev')) {
            $topicInstance->producev(
                $partitionValue,
                0,
                $payload,
                $key,
                null,  // headers
                null,  // timestamp_ms
                $messageId // opaque for tracking
            );
        } else {
            $topicInstance->produce($partitionValue, 0, $payload, $key);
        }

        self::$pendingMessages++;

        // Non-blocking poll to trigger delivery callbacks
        $this->producer->poll(0);

        // Wait for delivery confirmation with short timeout
        // This ensures reliability for realtime notifications
        if (!$this->consuming) {
            $this->waitForDelivery($messageId, self::DEFAULT_DELIVERY_WAIT_MS);
        }

        // Periodic cleanup of stale delivery results
        $now = microtime(true) * 1000;
        if ($now - self::$lastPollTime >= 5000) { // Every 5 seconds
            $this->cleanupStaleDeliveryResults();
            self::$lastPollTime = $now;
        }
    }

    /**
     * Synchronous publish with guaranteed delivery confirmation.
     * Use this when you MUST guarantee delivery before returning.
     *
     * @param string $topic Topic name
     * @param string $payload Message payload
     * @param int|null $partition Partition number
     * @param string|null $key Message key
     * @param int $timeoutMs Maximum wait time for delivery confirmation
     * @return bool True if delivered successfully
     * @throws BrokerException If delivery fails
     */
    public function publishSync(string $topic, string $payload, ?int $partition = null, ?string $key = null, int $timeoutMs = 5000): bool
    {
        if (!$this->connected || $this->producer === null) {
            throw BrokerException::notConnected('kafka');
        }

        // Get or create cached topic
        if (!isset($this->topicCache[$topic])) {
            $this->topicCache[$topic] = $this->producer->newTopic($topic);
        }

        $topicInstance = $this->topicCache[$topic];
        $partitionValue = $partition ?? RD_KAFKA_PARTITION_UA;

        // Generate message ID for tracking
        $messageId = $this->generateMessageId();

        // Initialize delivery tracking
        self::$deliveryResults[$messageId] = [
            'status' => 'pending',
            'error' => null,
            'timestamp' => microtime(true),
        ];

        // Produce with message ID as opaque
        if ($key !== null && method_exists($topicInstance, 'producev')) {
            $topicInstance->producev(
                $partitionValue,
                0,
                $payload,
                $key,
                null,
                null,
                $messageId
            );
        } else {
            $topicInstance->produce($partitionValue, 0, $payload, $key);
        }

        self::$pendingMessages++;

        // Wait for delivery confirmation
        $delivered = $this->waitForDelivery($messageId, $timeoutMs);

        // Cleanup tracking
        $result = self::$deliveryResults[$messageId] ?? null;
        unset(self::$deliveryResults[$messageId]);

        if (!$delivered) {
            $error = $result['error'] ?? 'Delivery timeout';
            throw BrokerException::publishFailed('kafka', $topic, "Sync delivery failed: {$error}");
        }

        if ($result && $result['status'] === 'failed') {
            throw BrokerException::publishFailed('kafka', $topic, "Delivery failed: {$result['error']}");
        }

        return true;
    }

    /**
     * Flush pending messages.
     * Call this periodically in long-running processes or before shutdown.
     */
    public function flush(int $timeoutMs = 5000): void
    {
        if ($this->producer !== null) {
            $this->producer->flush($timeoutMs);
        }
    }

    /**
     * Poll for delivery callbacks.
     * Call this periodically in long-running processes.
     *
     * @param int $timeoutMs Poll timeout (0 = non-blocking)
     * @return int Number of events processed
     */
    public function poll(int $timeoutMs = 0): int
    {
        if ($this->producer === null) {
            return 0;
        }

        return $this->producer->poll($timeoutMs) ?? 0;
    }

    public function subscribe(array $topics): void
    {
        if (!$this->connected || $this->consumer === null) {
            throw BrokerException::notConnected('kafka');
        }

        try {
            $this->consumer->subscribe($topics);
            // Brief wait for partition assignment
            $this->consumer->consume(200);
        } catch (\Throwable $e) {
            throw BrokerException::subscribeFailed('kafka', implode(',', $topics), $e->getMessage(), $e);
        }
    }

    /**
     * Consume messages with optimized batch processing.
     */
    public function consume(callable $callback, int $timeoutMs = 1000, int $batchSize = 100): void
    {
        if (!$this->connected || $this->consumer === null) {
            throw BrokerException::notConnected('kafka');
        }

        $this->consuming = true;
        $this->consecutiveErrors = 0;
        $messagesProcessed = 0;

        while ($this->consuming && $messagesProcessed < $batchSize) {
            try {
                $message = $this->consumer->consume($timeoutMs);

                if ($message === null) {
                    return;
                }

                $kafkaMessage = KafkaMessage::fromRdKafka($message);

                if ($kafkaMessage->hasError()) {
                    if ($kafkaMessage->isEof() || $kafkaMessage->isTimeout()) {
                        $this->consecutiveErrors = 0;
                        return;
                    }

                    if ($kafkaMessage->isUnknownTopicOrPartition()) {
                        $this->handleUnknownTopicError();
                        return;
                    }

                    $this->consecutiveErrors++;
                    if ($this->consecutiveErrors > 3) {
                        throw BrokerException::consumeFailed('kafka', $kafkaMessage->errorMessage);
                    }
                    continue;
                }

                $this->consecutiveErrors = 0;

                if ($callback($kafkaMessage) === false) {
                    break;
                }

                $messagesProcessed++;

                // Periodic producer poll during consumption
                if ($messagesProcessed % 10 === 0) {
                    $this->producer?->poll(0);
                }

            } catch (BrokerException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->consecutiveErrors++;
                if ($this->consecutiveErrors >= 5) {
                    throw BrokerException::consumeFailed('kafka', $e->getMessage(), $e);
                }
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
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'precision')) {
                error_log("Commit failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Get failed delivery count for monitoring.
     */
    public function getFailedDeliveries(): int
    {
        return $this->failedDeliveries;
    }

    /**
     * Get successful delivery count for monitoring.
     */
    public static function getSuccessfulDeliveries(): int
    {
        return self::$successfulDeliveries;
    }

    /**
     * Get pending messages count.
     */
    public static function getPendingMessages(): int
    {
        return self::$pendingMessages;
    }

    /**
     * Reset failed delivery counter.
     */
    public function resetFailedDeliveries(): void
    {
        $this->failedDeliveries = 0;
    }

    /**
     * Reset all static counters (for testing).
     */
    public static function resetCounters(): void
    {
        self::$pendingMessages = 0;
        self::$successfulDeliveries = 0;
        self::$lastPollTime = 0;
        self::$deliveryResults = [];
    }

    /**
     * Force flush shared producer (for testing/monitoring).
     */
    public static function flushShared(int $timeoutMs = 5000): void
    {
        if (self::$sharedProducer !== null) {
            self::$sharedProducer->flush($timeoutMs);
        }
    }

    /**
     * Wait for message delivery with timeout.
     *
     * @param string $messageId Message ID to wait for
     * @param int $timeoutMs Timeout in milliseconds
     * @return bool True if delivered, false if still pending/failed
     */
    private function waitForDelivery(string $messageId, int $timeoutMs): bool
    {
        if ($this->producer === null) {
            return false;
        }

        $startTime = microtime(true) * 1000;
        $endTime = $startTime + $timeoutMs;
        $pollInterval = min(10, $this->pollIntervalMs); // 10ms max poll interval

        while (microtime(true) * 1000 < $endTime) {
            // Poll for delivery callbacks
            $this->producer->poll($pollInterval);

            // Check if this message was delivered
            $result = self::$deliveryResults[$messageId] ?? null;
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
        if (self::$pendingMessages < self::MAX_PENDING_MESSAGES) {
            return;
        }

        if ($this->producer === null) {
            return;
        }

        // Too many pending messages, wait for some to be delivered
        $maxWaitMs = 2000; // 2 seconds max wait
        $startTime = microtime(true) * 1000;

        while (self::$pendingMessages >= self::MAX_PENDING_MESSAGES) {
            $this->producer->poll(50); // Poll for callbacks

            if (microtime(true) * 1000 - $startTime > $maxWaitMs) {
                // Still too many pending after timeout
                throw BrokerException::publishFailed(
                    'kafka',
                    'backpressure',
                    "Too many pending messages (" . self::$pendingMessages . "), producer is overloaded"
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

    /**
     * Cleanup stale delivery results (older than 60 seconds).
     *
     * Prevents memory leaks in long-running processes.
     */
    public function cleanupStaleDeliveryResults(): void
    {
        $now = microtime(true);
        $staleThreshold = 60.0; // 60 seconds

        foreach (self::$deliveryResults as $messageId => $result) {
            if ($now - $result['timestamp'] > $staleThreshold) {
                unset(self::$deliveryResults[$messageId]);
                // Assume stale messages were delivered (or failed silently)
                self::$pendingMessages = max(0, self::$pendingMessages - 1);
            }
        }
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
     * Get delivery results for monitoring.
     *
     * @return array<string, array{status: string, error: string|null, timestamp: float}>
     */
    public static function getDeliveryResults(): array
    {
        return self::$deliveryResults;
    }

    private function handleUnknownTopicError(): void
    {
        $this->consecutiveErrors++;

        if ($this->consecutiveErrors >= 10) {
            throw BrokerTemporaryException::unknownTopicOrPartition('unknown', $this->consecutiveErrors);
        }

        $delayMs = min(100 * (2 ** ($this->consecutiveErrors - 1)), 5000);
        usleep($delayMs * 1000);
    }

    public function __destruct()
    {
        // Don't disconnect singleton producer on instance destruction
        if (!$this->useSingleton) {
            $this->disconnect();
        } else {
            // Just cleanup instance-specific resources
            if ($this->consumer !== null) {
                try {
                    $this->consumer->unsubscribe();
                } catch (\Throwable) {
                    // Ignore
                }
                $this->consumer = null;
            }
            $this->topicCache = [];
            $this->connected = false;
        }
    }
}
