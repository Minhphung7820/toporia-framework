<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka;

use Toporia\Framework\Realtime\Brokers\Kafka\Client\KafkaClientInterface;
use Toporia\Framework\Realtime\Metrics\BrokerMetrics;

/**
 * Class AsyncProducerQueue
 *
 * Non-blocking async queue for Kafka messages using RingBuffer.
 * Decouples HTTP requests from Kafka flush for maximum throughput.
 *
 * Architecture:
 * - HTTP Request -> enqueue() -> RingBuffer -> Response (fast, ~50ns)
 * - Background Worker -> dequeue() -> Kafka Batch Publish
 *
 * Features:
 * - Lock-free O(1) enqueue/dequeue via RingBuffer
 * - Zero allocation after initialization
 * - Configurable batch size and flush interval
 * - Overflow protection with backpressure
 * - Graceful shutdown with drain
 *
 * Performance:
 * - enqueue(): O(1), ~50ns (no memory allocation)
 * - dequeue(): O(1), ~50ns
 * - Batch flush: 100K-500K msg/s to Kafka
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 */
final class AsyncProducerQueue
{
    /**
     * @var RingBuffer Lock-free circular buffer
     */
    private RingBuffer $buffer;

    /**
     * @var int Batch size for flush operations
     */
    private int $batchSize;

    /**
     * @var int Flush interval in milliseconds
     */
    private int $flushIntervalMs;

    /**
     * @var float Last flush timestamp
     */
    private float $lastFlushTime;

    /**
     * @var bool Whether queue is draining (shutdown)
     */
    private bool $draining = false;

    /**
     * @var int Total messages flushed
     */
    private int $totalFlushed = 0;

    /**
     * @var int Total flush operations
     */
    private int $flushCount = 0;

    /**
     * @param int $maxSize Maximum queue size (default: 131072 = 128K, power of 2)
     * @param int $batchSize Messages per batch (default: 1,000)
     * @param int $flushIntervalMs Max time between flushes (default: 50ms)
     */
    public function __construct(
        int $maxSize = 131072,
        int $batchSize = 1000,
        int $flushIntervalMs = 50
    ) {
        $this->buffer = new RingBuffer($maxSize);
        $this->batchSize = $batchSize;
        $this->flushIntervalMs = $flushIntervalMs;
        $this->lastFlushTime = microtime(true);
    }

    /**
     * Enqueue a message for async delivery.
     *
     * Non-blocking, O(1) operation with zero allocation.
     * Returns immediately, message will be batched and sent to Kafka.
     *
     * @param string $topic Kafka topic
     * @param string $payload Message payload (JSON)
     * @param string|null $key Message key for partitioning
     * @param int|null $partition Target partition (null = auto)
     * @return bool True if enqueued, false if queue full (backpressure)
     */
    public function enqueue(string $topic, string $payload, ?string $key = null, ?int $partition = null): bool
    {
        // Reject during drain
        if ($this->draining) {
            return false;
        }

        $success = $this->buffer->enqueue($topic, $payload, $key, $partition);

        if (!$success) {
            BrokerMetrics::recordError('kafka', 'queue_overflow');
        }

        return $success;
    }

    /**
     * Flush queued messages to Kafka.
     *
     * Should be called by a background worker or at request end.
     * Processes messages in batches for optimal throughput.
     *
     * @param KafkaClientInterface $client Kafka client
     * @param bool $force Force flush regardless of batch size/interval
     * @return int Number of messages flushed
     */
    public function flush(KafkaClientInterface $client, bool $force = false): int
    {
        $now = microtime(true);
        $elapsed = ($now - $this->lastFlushTime) * 1000; // ms
        $size = $this->buffer->getSize();

        // Skip if not enough messages and not enough time passed (unless forced)
        if (!$force && $size < $this->batchSize && $elapsed < $this->flushIntervalMs) {
            return 0;
        }

        if ($size === 0) {
            return 0;
        }

        $startTime = microtime(true);

        // Dequeue batch from RingBuffer (O(1) per message)
        $messages = $this->buffer->dequeueBatch($this->batchSize);
        $flushed = 0;

        foreach ($messages as $message) {
            try {
                $client->publish(
                    $message['topic'],
                    $message['payload'],
                    $message['partition'],
                    $message['key']
                );
                $flushed++;
            } catch (\Throwable $e) {
                // Log error but continue with other messages
                error_log("[AsyncProducerQueue] Failed to publish: {$e->getMessage()}");
                BrokerMetrics::recordError('kafka', 'async_publish');
            }
        }

        // Flush Kafka producer buffer
        if ($flushed > 0) {
            $client->flush(1000); // 1 second timeout
        }

        $this->lastFlushTime = microtime(true);
        $this->totalFlushed += $flushed;
        $this->flushCount++;

        $duration = (microtime(true) - $startTime) * 1000;
        if ($flushed > 0) {
            BrokerMetrics::recordPublish('kafka', 'batch', $duration, true);
        }

        return $flushed;
    }

    /**
     * Drain all remaining messages (graceful shutdown).
     *
     * Blocks until all messages are flushed or timeout.
     *
     * @param KafkaClientInterface $client Kafka client
     * @param int $timeoutMs Maximum time to wait
     * @return int Total messages drained
     */
    public function drain(KafkaClientInterface $client, int $timeoutMs = 30000): int
    {
        $this->draining = true;
        $startTime = microtime(true);
        $endTime = $startTime + ($timeoutMs / 1000);
        $totalDrained = 0;

        while (!$this->buffer->isEmpty() && microtime(true) < $endTime) {
            $flushed = $this->flush($client, true);
            $totalDrained += $flushed;

            if ($flushed === 0) {
                usleep(1000); // 1ms pause
            }
        }

        $this->draining = false;
        return $totalDrained;
    }

    /**
     * Check if flush is needed based on batch size or time.
     *
     * @return bool
     */
    public function shouldFlush(): bool
    {
        $size = $this->buffer->getSize();

        if ($size === 0) {
            return false;
        }

        if ($size >= $this->batchSize) {
            return true;
        }

        $elapsed = (microtime(true) - $this->lastFlushTime) * 1000;
        return $elapsed >= $this->flushIntervalMs;
    }

    /**
     * Get current queue size.
     *
     * @return int
     */
    public function getSize(): int
    {
        return $this->buffer->getSize();
    }

    /**
     * Get queue statistics.
     *
     * @return array{size: int, capacity: int, total_enqueued: int, total_flushed: int, flush_count: int, utilization: float, overflow_count: int}
     */
    public function getStats(): array
    {
        $bufferStats = $this->buffer->getStats();

        return [
            'size' => $bufferStats['size'],
            'capacity' => $bufferStats['capacity'],
            'total_enqueued' => $bufferStats['total_enqueued'],
            'total_flushed' => $this->totalFlushed,
            'flush_count' => $this->flushCount,
            'utilization' => $bufferStats['utilization'],
            'overflow_count' => $bufferStats['overflow_count'],
            'avg_batch_size' => $this->flushCount > 0 ? round($this->totalFlushed / $this->flushCount, 2) : 0,
        ];
    }

    /**
     * Check if queue is full (backpressure active).
     *
     * @return bool
     */
    public function isFull(): bool
    {
        return $this->buffer->isFull();
    }

    /**
     * Check if queue is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->buffer->isEmpty();
    }

    /**
     * Clear all queued messages (for testing/reset).
     *
     * @return int Number of messages cleared
     */
    public function clear(): int
    {
        return $this->buffer->clear();
    }

    /**
     * Get the underlying RingBuffer.
     *
     * @return RingBuffer
     */
    public function getBuffer(): RingBuffer
    {
        return $this->buffer;
    }
}
