<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\Client;

use RdKafka;
use Toporia\Framework\Realtime\Exceptions\{BrokerException, BrokerTemporaryException};
use Toporia\Framework\Realtime\Metrics\BrokerMetrics;

/**
 * Class RdKafkaClientImproved
 *
 * Improved Kafka client with backpressure, better error handling, and metrics.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers\Kafka\Client
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RdKafkaClientImproved implements KafkaClientInterface
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
    private const MAX_PENDING_MESSAGES = 1000; // Backpressure threshold
    private const POLL_INTERVAL_MS = 10; // Poll interval for delivery callbacks

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
        return 'rdkafka-improved';
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

        // Idempotence is optional - disable by default for faster startup
        // Delivery callbacks already ensure reliability
        // Set 'enable.idempotence' => 'true' in producer_config if needed
        $producerConf->set('enable.idempotence', 'false');

        // Retries for reliability (works without idempotence)
        $producerConf->set('retries', '3');
        $producerConf->set('retry.backoff.ms', '100');

        // Set delivery report callback for async reliability
        $producerConf->setDrMsgCb(function (RdKafka\Producer $kafka, RdKafka\Message $message) {
            $this->handleDeliveryReport($message);
        });

        foreach ($this->producerConfig as $key => $value) {
            $producerConf->set($key, (string) $value);
        }

        $this->producer = new RdKafka\Producer($producerConf);
        $this->producer->addBrokers($brokerList);

        // Initialize consumer
        $consumerConf = new RdKafka\Conf();
        $consumerConf->set('bootstrap.servers', $brokerList);
        $consumerConf->set('metadata.broker.list', $brokerList);
        $consumerConf->set('group.id', $this->consumerGroup);
        $consumerConf->set('enable.auto.commit', $this->manualCommit ? 'false' : 'true');
        $consumerConf->set('auto.offset.reset', 'earliest');

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
        $this->flush(5000);

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

            // Non-blocking poll to trigger delivery callbacks
            $this->producer->poll(0);

            // Wait for this specific message with short timeout
            // This ensures reliability while allowing async batching
            $delivered = $this->waitForDelivery($messageId, 100); // 100ms max wait

            if (!$delivered) {
                // Message queued but not yet confirmed - that's OK for realtime
                // The delivery callback will handle success/failure
                // For critical messages, caller can use publishSync()
            }

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
            error_log("Kafka delivery failed: {$errorMsg} (topic: {$message->topic_name})");
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

        // If flush didn't complete, keep trying
        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            $retries = 10;
            while ($retries-- > 0 && $this->producer->getOutQLen() > 0) {
                $this->producer->poll(100);
                $this->producer->flush(500);
            }
        }
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
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'precision')) {
                error_log("Failed to commit offset: {$e->getMessage()}");
            }
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
            error_log("CRITICAL: Too many consecutive Kafka errors ({$this->consecutiveErrors}). Waiting 60s...");
            sleep(60);
            $this->consecutiveErrors = 0;
            return;
        }

        // Exponential backoff
        $delay = min((int) pow(2, $this->consecutiveErrors - 1), 30);
        if ($delay > 0) {
            error_log("Kafka error #{$this->consecutiveErrors}. Backoff {$delay}s");
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
}

