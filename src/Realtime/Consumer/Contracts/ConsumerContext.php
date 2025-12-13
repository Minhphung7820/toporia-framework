<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Consumer\Contracts;

/**
 * Class ConsumerContext
 *
 * Immutable context object passed to consumer handlers.
 * Contains metadata about the current consumer execution.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Consumer\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ConsumerContext
{
    /**
     * @param string $driver Broker driver name (redis, rabbitmq, kafka)
     * @param string $handlerName Handler name
     * @param string $channel Channel/topic being consumed
     * @param int $processId Process ID of the consumer
     * @param float $startedAt Unix timestamp when consumer started
     * @param int $messageCount Total messages processed so far
     * @param int $errorCount Total errors encountered
     * @param int $attempt Current retry attempt (1-based, 0 if not retrying)
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        public readonly string $driver,
        public readonly string $handlerName,
        public readonly string $channel,
        public readonly int $processId,
        public readonly float $startedAt,
        public readonly int $messageCount = 0,
        public readonly int $errorCount = 0,
        public readonly int $attempt = 0,
        public readonly array $metadata = []
    ) {}

    /**
     * Create a new context with updated message count.
     *
     * @param int $messageCount New message count
     * @return self
     */
    public function withMessageCount(int $messageCount): self
    {
        return new self(
            driver: $this->driver,
            handlerName: $this->handlerName,
            channel: $this->channel,
            processId: $this->processId,
            startedAt: $this->startedAt,
            messageCount: $messageCount,
            errorCount: $this->errorCount,
            attempt: $this->attempt,
            metadata: $this->metadata
        );
    }

    /**
     * Create a new context with updated error count.
     *
     * @param int $errorCount New error count
     * @return self
     */
    public function withErrorCount(int $errorCount): self
    {
        return new self(
            driver: $this->driver,
            handlerName: $this->handlerName,
            channel: $this->channel,
            processId: $this->processId,
            startedAt: $this->startedAt,
            messageCount: $this->messageCount,
            errorCount: $errorCount,
            attempt: $this->attempt,
            metadata: $this->metadata
        );
    }

    /**
     * Create a new context with retry attempt number.
     *
     * @param int $attempt Attempt number
     * @return self
     */
    public function withAttempt(int $attempt): self
    {
        return new self(
            driver: $this->driver,
            handlerName: $this->handlerName,
            channel: $this->channel,
            processId: $this->processId,
            startedAt: $this->startedAt,
            messageCount: $this->messageCount,
            errorCount: $this->errorCount,
            attempt: $attempt,
            metadata: $this->metadata
        );
    }

    /**
     * Create a new context with additional metadata.
     *
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return self
     */
    public function withMetadata(string $key, mixed $value): self
    {
        $newMetadata = $this->metadata;
        $newMetadata[$key] = $value;

        return new self(
            driver: $this->driver,
            handlerName: $this->handlerName,
            channel: $this->channel,
            processId: $this->processId,
            startedAt: $this->startedAt,
            messageCount: $this->messageCount,
            errorCount: $this->errorCount,
            attempt: $this->attempt,
            metadata: $newMetadata
        );
    }

    /**
     * Get the running duration in seconds.
     *
     * @return float Duration in seconds
     */
    public function getDuration(): float
    {
        return microtime(true) - $this->startedAt;
    }

    /**
     * Get messages per second throughput.
     *
     * @return float Messages per second
     */
    public function getThroughput(): float
    {
        $duration = $this->getDuration();
        return $duration > 0 ? $this->messageCount / $duration : 0.0;
    }

    /**
     * Get error rate as percentage.
     *
     * @return float Error rate (0-100)
     */
    public function getErrorRate(): float
    {
        if ($this->messageCount === 0) {
            return 0.0;
        }

        return ($this->errorCount / $this->messageCount) * 100;
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'handler_name' => $this->handlerName,
            'channel' => $this->channel,
            'process_id' => $this->processId,
            'started_at' => $this->startedAt,
            'message_count' => $this->messageCount,
            'error_count' => $this->errorCount,
            'attempt' => $this->attempt,
            'duration' => $this->getDuration(),
            'throughput' => $this->getThroughput(),
            'error_rate' => $this->getErrorRate(),
            'metadata' => $this->metadata,
        ];
    }
}
