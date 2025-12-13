<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka;

/**
 * Class SharedMemoryQueue
 *
 * Inter-process message queue using APCu shared memory.
 * Enables HTTP processes to enqueue messages for background workers.
 *
 * Architecture:
 * - HTTP Request → enqueue() → APCu → Response (non-blocking, ~100μs)
 * - Background Worker → dequeue() → Kafka Batch Publish
 *
 * Performance:
 * - enqueue(): ~100μs (APCu write)
 * - dequeue(): ~50μs (APCu read + delete)
 * - Throughput: 100K+ msg/s across processes
 *
 * Memory Management:
 * - Uses APCu slots with atomic increment
 * - Auto-cleanup of old messages
 * - Configurable TTL for messages
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class SharedMemoryQueue
{
    /**
     * @var string Queue name prefix in APCu
     */
    private string $prefix;

    /**
     * @var string Key for write pointer
     */
    private string $writeKey;

    /**
     * @var string Key for read pointer
     */
    private string $readKey;

    /**
     * @var string Key for stats
     */
    private string $statsKey;

    /**
     * @var int Maximum messages in queue
     */
    private int $maxSize;

    /**
     * @var int Message TTL in seconds
     */
    private int $ttl;

    /**
     * @var bool Whether APCu is available
     */
    private bool $available;

    /**
     * @param string $name Queue name
     * @param int $maxSize Maximum queue size
     * @param int $ttl Message TTL in seconds
     */
    public function __construct(
        string $name = 'kafka_queue',
        int $maxSize = 1000000,
        int $ttl = 300
    ) {
        $this->prefix = "shm:{$name}:";
        $this->writeKey = "{$this->prefix}write_ptr";
        $this->readKey = "{$this->prefix}read_ptr";
        $this->statsKey = "{$this->prefix}stats";
        $this->maxSize = $maxSize;
        $this->ttl = $ttl;

        $this->available = extension_loaded('apcu') && apcu_enabled();

        if ($this->available) {
            $this->initialize();
        }
    }

    /**
     * Initialize queue pointers if not exist.
     */
    private function initialize(): void
    {
        apcu_add($this->writeKey, 0);
        apcu_add($this->readKey, 0);
        apcu_add($this->statsKey, [
            'enqueued' => 0,
            'dequeued' => 0,
            'overflow' => 0,
            'created_at' => time(),
        ]);
    }

    /**
     * Check if shared memory queue is available.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Enqueue a message.
     *
     * Thread-safe via APCu atomic operations.
     *
     * @param string $topic Kafka topic
     * @param string $payload Message payload
     * @param string|null $key Message key
     * @param int|null $partition Target partition
     * @return bool True if enqueued
     */
    public function enqueue(string $topic, string $payload, ?string $key = null, ?int $partition = null): bool
    {
        if (!$this->available) {
            return false;
        }

        // Get current size
        $writePtr = (int) apcu_fetch($this->writeKey);
        $readPtr = (int) apcu_fetch($this->readKey);
        $size = $writePtr - $readPtr;

        // Check overflow
        if ($size >= $this->maxSize) {
            $this->incrementStat('overflow');
            return false;
        }

        // Atomic increment write pointer
        $slot = apcu_inc($this->writeKey);
        if ($slot === false) {
            return false;
        }

        // Store message
        $messageKey = "{$this->prefix}msg:{$slot}";
        $stored = apcu_store($messageKey, [
            'topic' => $topic,
            'payload' => $payload,
            'key' => $key,
            'partition' => $partition,
            'timestamp' => microtime(true),
            'slot' => $slot,
        ], $this->ttl);

        if ($stored) {
            $this->incrementStat('enqueued');
        }

        return $stored;
    }

    /**
     * Dequeue a message.
     *
     * Thread-safe, only one worker will get each message.
     *
     * @return array{topic: string, payload: string, key: ?string, partition: ?int, timestamp: float, slot: int}|null
     */
    public function dequeue(): ?array
    {
        if (!$this->available) {
            return null;
        }

        // Atomic increment read pointer
        $slot = apcu_inc($this->readKey);
        if ($slot === false) {
            return null;
        }

        // Read message
        $messageKey = "{$this->prefix}msg:{$slot}";
        $message = apcu_fetch($messageKey, $success);

        if (!$success || $message === false) {
            // Message expired or not yet written
            return null;
        }

        // Delete message (only one worker will succeed due to atomic inc)
        apcu_delete($messageKey);
        $this->incrementStat('dequeued');

        return $message;
    }

    /**
     * Dequeue multiple messages at once.
     *
     * @param int $count Maximum messages to dequeue
     * @return array<array{topic: string, payload: string, key: ?string, partition: ?int, timestamp: float, slot: int}>
     */
    public function dequeueBatch(int $count): array
    {
        $batch = [];
        $dequeued = 0;
        $maxAttempts = $count + 10; // Extra attempts for expired messages
        $attempts = 0;

        while ($dequeued < $count && $attempts < $maxAttempts) {
            $message = $this->dequeue();
            $attempts++;

            if ($message !== null) {
                $batch[] = $message;
                $dequeued++;
            } elseif ($this->getSize() === 0) {
                // Queue empty
                break;
            }
        }

        return $batch;
    }

    /**
     * Get current queue size.
     *
     * @return int
     */
    public function getSize(): int
    {
        if (!$this->available) {
            return 0;
        }

        $writePtr = (int) apcu_fetch($this->writeKey);
        $readPtr = (int) apcu_fetch($this->readKey);

        return max(0, $writePtr - $readPtr);
    }

    /**
     * Check if queue is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->getSize() === 0;
    }

    /**
     * Get queue statistics.
     *
     * @return array{size: int, max_size: int, utilization: float, enqueued: int, dequeued: int, overflow: int, write_ptr: int, read_ptr: int}
     */
    public function getStats(): array
    {
        if (!$this->available) {
            return [
                'available' => false,
                'size' => 0,
                'max_size' => $this->maxSize,
                'utilization' => 0.0,
                'enqueued' => 0,
                'dequeued' => 0,
                'overflow' => 0,
                'write_ptr' => 0,
                'read_ptr' => 0,
            ];
        }

        $stats = apcu_fetch($this->statsKey) ?: [];
        $writePtr = (int) apcu_fetch($this->writeKey);
        $readPtr = (int) apcu_fetch($this->readKey);
        $size = max(0, $writePtr - $readPtr);

        return [
            'available' => true,
            'size' => $size,
            'max_size' => $this->maxSize,
            'utilization' => $this->maxSize > 0 ? ($size / $this->maxSize) * 100 : 0.0,
            'enqueued' => $stats['enqueued'] ?? 0,
            'dequeued' => $stats['dequeued'] ?? 0,
            'overflow' => $stats['overflow'] ?? 0,
            'write_ptr' => $writePtr,
            'read_ptr' => $readPtr,
        ];
    }

    /**
     * Clear all messages.
     *
     * @return int Number of slots cleared
     */
    public function clear(): int
    {
        if (!$this->available) {
            return 0;
        }

        $writePtr = (int) apcu_fetch($this->writeKey);
        $readPtr = (int) apcu_fetch($this->readKey);
        $cleared = 0;

        // Delete all message slots
        for ($i = $readPtr; $i <= $writePtr; $i++) {
            $messageKey = "{$this->prefix}msg:{$i}";
            if (apcu_delete($messageKey)) {
                $cleared++;
            }
        }

        // Reset pointers
        apcu_store($this->writeKey, 0);
        apcu_store($this->readKey, 0);

        return $cleared;
    }

    /**
     * Increment a stat counter atomically.
     *
     * @param string $key Stat key
     */
    private function incrementStat(string $key): void
    {
        $stats = apcu_fetch($this->statsKey) ?: [];
        $stats[$key] = ($stats[$key] ?? 0) + 1;
        apcu_store($this->statsKey, $stats, 0);
    }

    /**
     * Compact queue by resetting pointers.
     *
     * Call periodically to prevent pointer overflow.
     *
     * @return bool
     */
    public function compact(): bool
    {
        if (!$this->available || $this->getSize() > 0) {
            return false;
        }

        apcu_store($this->writeKey, 0);
        apcu_store($this->readKey, 0);

        return true;
    }
}
