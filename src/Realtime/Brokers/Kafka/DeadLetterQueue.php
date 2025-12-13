<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka;

use Toporia\Framework\Realtime\Brokers\Kafka\Client\KafkaClientInterface;
use Toporia\Framework\Realtime\Contracts\MessageInterface;
use Toporia\Framework\Realtime\Metrics\BrokerMetrics;

/**
 * Class DeadLetterQueue
 *
 * Dead Letter Queue (DLQ) for failed Kafka messages.
 * Stores messages that failed processing for later retry or manual intervention.
 *
 * Features:
 * - Automatic routing of failed messages to DLQ topic
 * - Original message metadata preservation
 * - Retry count tracking
 * - Configurable DLQ topic naming
 * - Support for message replay from DLQ
 *
 * Architecture:
 * - Failed messages → DLQ topic with error metadata
 * - Separate consumer for DLQ processing
 * - Manual or automated retry with exponential backoff
 *
 * Topic naming convention:
 * - Original topic: events.stream
 * - DLQ topic: dlq.events.stream
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class DeadLetterQueue
{
    /**
     * @var string DLQ topic prefix
     */
    private string $prefix;

    /**
     * @var int Maximum retry attempts before permanent failure
     */
    private int $maxRetries;

    /**
     * @var bool Whether DLQ is enabled
     */
    private bool $enabled;

    /**
     * @var KafkaClientInterface|null Kafka client for publishing to DLQ
     */
    private ?KafkaClientInterface $client = null;

    /**
     * @var array<string, int> Counter for messages per topic
     */
    private array $counters = [];

    /**
     * @param string $prefix DLQ topic prefix (default: 'dlq')
     * @param int $maxRetries Max retries before permanent failure (default: 3)
     * @param bool $enabled Whether DLQ is enabled (default: true)
     */
    public function __construct(
        string $prefix = 'dlq',
        int $maxRetries = 3,
        bool $enabled = true
    ) {
        $this->prefix = $prefix;
        $this->maxRetries = $maxRetries;
        $this->enabled = $enabled;
    }

    /**
     * Set the Kafka client for publishing.
     *
     * @param KafkaClientInterface $client
     * @return self
     */
    public function setClient(KafkaClientInterface $client): self
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Check if DLQ is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable or disable DLQ.
     *
     * @param bool $enabled
     * @return self
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Send a failed message to DLQ.
     *
     * @param string $originalTopic Original topic name
     * @param MessageInterface $message Failed message
     * @param \Throwable $exception Exception that caused failure
     * @param int $retryCount Current retry count
     * @param array<string, mixed> $metadata Additional metadata
     * @return bool True if sent successfully
     */
    public function send(
        string $originalTopic,
        MessageInterface $message,
        \Throwable $exception,
        int $retryCount = 0,
        array $metadata = []
    ): bool {
        if (!$this->enabled) {
            return false;
        }

        if ($this->client === null) {
            error_log('[DLQ] Cannot send to DLQ: Kafka client not set');
            return false;
        }

        $dlqTopic = $this->getDlqTopic($originalTopic);

        // Build DLQ message with error metadata
        $dlqPayload = [
            'original_topic' => $originalTopic,
            'original_message' => [
                'id' => $message->getId(),
                'channel' => $message->getChannel(),
                'event' => $message->getEvent(),
                'data' => $message->getData(),
                'timestamp' => $message->getTimestamp(),
            ],
            'error' => [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->formatTrace($exception),
            ],
            'dlq_metadata' => array_merge($metadata, [
                'retry_count' => $retryCount,
                'max_retries' => $this->maxRetries,
                'can_retry' => $retryCount < $this->maxRetries,
                'failed_at' => date('Y-m-d H:i:s'),
                'failed_timestamp' => microtime(true),
                'consumer_id' => gethostname() . ':' . getmypid(),
            ]),
        ];

        try {
            $this->client->publish(
                $dlqTopic,
                json_encode($dlqPayload, JSON_THROW_ON_ERROR),
                null, // Auto partition
                $message->getId() // Use message ID as key
            );

            // Track counter
            if (!isset($this->counters[$originalTopic])) {
                $this->counters[$originalTopic] = 0;
            }
            $this->counters[$originalTopic]++;

            BrokerMetrics::recordPublish('kafka', $dlqTopic, 0, true);

            return true;
        } catch (\Throwable $e) {
            error_log("[DLQ] Failed to send to DLQ: {$e->getMessage()}");
            BrokerMetrics::recordError('kafka', 'dlq_publish');
            return false;
        }
    }

    /**
     * Check if message should be retried or sent to permanent DLQ.
     *
     * @param int $currentRetryCount Current retry count
     * @return bool True if should retry, false if should go to permanent DLQ
     */
    public function shouldRetry(int $currentRetryCount): bool
    {
        return $currentRetryCount < $this->maxRetries;
    }

    /**
     * Get retry delay based on retry count (exponential backoff).
     *
     * @param int $retryCount Current retry count
     * @param int $baseDelayMs Base delay in milliseconds
     * @return int Delay in milliseconds
     */
    public function getRetryDelay(int $retryCount, int $baseDelayMs = 1000): int
    {
        // Exponential backoff with jitter
        // Retry 1: 1s, Retry 2: 2s, Retry 3: 4s, etc.
        $delay = $baseDelayMs * (2 ** $retryCount);

        // Add jitter (±10%)
        $jitter = $delay * 0.1;
        $delay += random_int((int) -$jitter, (int) $jitter);

        // Cap at 60 seconds
        return min($delay, 60000);
    }

    /**
     * Get DLQ topic name for an original topic.
     *
     * @param string $originalTopic Original topic name
     * @return string DLQ topic name
     */
    public function getDlqTopic(string $originalTopic): string
    {
        return "{$this->prefix}.{$originalTopic}";
    }

    /**
     * Get original topic from DLQ topic name.
     *
     * @param string $dlqTopic DLQ topic name
     * @return string|null Original topic name or null if not a DLQ topic
     */
    public function getOriginalTopic(string $dlqTopic): ?string
    {
        $prefix = $this->prefix . '.';

        if (str_starts_with($dlqTopic, $prefix)) {
            return substr($dlqTopic, strlen($prefix));
        }

        return null;
    }

    /**
     * Check if a topic is a DLQ topic.
     *
     * @param string $topic Topic name
     * @return bool
     */
    public function isDlqTopic(string $topic): bool
    {
        return str_starts_with($topic, $this->prefix . '.');
    }

    /**
     * Parse a DLQ message payload.
     *
     * @param string $payload JSON payload from DLQ
     * @return array{original_topic: string, original_message: array, error: array, dlq_metadata: array}|null
     */
    public function parsePayload(string $payload): ?array
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['original_topic'], $data['original_message'], $data['error'], $data['dlq_metadata'])) {
                return null;
            }

            return $data;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get DLQ statistics.
     *
     * @return array{enabled: bool, prefix: string, max_retries: int, counters: array<string, int>, total: int}
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'prefix' => $this->prefix,
            'max_retries' => $this->maxRetries,
            'counters' => $this->counters,
            'total' => array_sum($this->counters),
        ];
    }

    /**
     * Reset counters.
     *
     * @return void
     */
    public function resetCounters(): void
    {
        $this->counters = [];
    }

    /**
     * Format exception trace for storage.
     *
     * @param \Throwable $exception
     * @return array<array{file: string, line: int, function: string, class: string|null}>
     */
    private function formatTrace(\Throwable $exception): array
    {
        $trace = [];
        $frames = array_slice($exception->getTrace(), 0, 10); // Limit to 10 frames

        foreach ($frames as $frame) {
            $trace[] = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
                'class' => $frame['class'] ?? null,
            ];
        }

        return $trace;
    }

    /**
     * Create a retry message from DLQ payload.
     *
     * Reconstructs the original message for retry.
     *
     * @param array $dlqPayload Parsed DLQ payload
     * @return array{topic: string, payload: string, retry_count: int}|null
     */
    public function createRetryMessage(array $dlqPayload): ?array
    {
        if (!isset($dlqPayload['original_topic'], $dlqPayload['original_message'], $dlqPayload['dlq_metadata'])) {
            return null;
        }

        $retryCount = ($dlqPayload['dlq_metadata']['retry_count'] ?? 0) + 1;

        if (!$this->shouldRetry($retryCount - 1)) {
            return null; // Exceeded max retries
        }

        return [
            'topic' => $dlqPayload['original_topic'],
            'payload' => json_encode($dlqPayload['original_message']),
            'retry_count' => $retryCount,
        ];
    }
}
