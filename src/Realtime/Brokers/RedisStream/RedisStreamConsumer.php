<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\RedisStream;

use Redis;
use Toporia\Framework\Realtime\Exceptions\BrokerException;
use Toporia\Framework\Realtime\Metrics\BrokerMetrics;
use Toporia\Framework\Realtime\Message;

/**
 * RedisStreamConsumer
 *
 * Pull-based consumer using Redis Streams with CORRECT batch semantics and blocking strategy.
 *
 * Redis Streams batch mechanism (NOT like Kafka):
 * - Pull batch: XREADGROUP COUNT N (consumer decides batch size)
 * - Process batch: in-memory loop
 * - Batch ACK: XACK id1 id2 ... idN (single Redis call)
 * - PEL tracking: failed messages stay in pending list
 * - Recovery: XAUTOCLAIM to reclaim from dead consumers
 *
 * CRITICAL - Blocking strategy to prevent Redis overload:
 * - ALWAYS use BLOCK > 0 (never BLOCK 0)
 * - BLOCK 0 causes busy polling → thousands of QPS → connection timeouts
 * - BLOCK 50-100ms: Redis holds connection when idle, returns immediately when data arrives
 * - Result: Zero QPS when no messages = healthy Redis
 *
 * Key differences from Kafka:
 * - NO broker-side batching (Redis just appends to log)
 * - NO partitions (single stream, pull-based load balance)
 * - NO disk persistence by default (RAM-first)
 * - NO commit offsets (PEL tracks pending per consumer)
 *
 * Performance characteristics:
 * - Pull throughput: 50K-200K msg/s (depends on batch size + network)
 * - ACK overhead: O(1) per batch (not per message)
 * - PEL lookup: O(1) by message ID
 * - XAUTOCLAIM: O(N) for idle messages
 * - QPS when idle: ~0 (BLOCK holds connection)
 *
 * When Redis Streams is NOT suitable:
 * - Need hundreds of thousands msg/s sustained
 * - Large payloads (>1KB per message)
 * - Replay days of history
 * - Exactly-once semantics
 * → Use Kafka instead
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.1.0
 */
final class RedisStreamConsumer
{
    private Redis $redis;
    private string $consumerGroup;
    private string $consumerName;
    private bool $consuming = false;

    /**
     * @var int Block time in milliseconds for XREADGROUP
     */
    private int $blockMs;

    /**
     * @var int Batch size (COUNT parameter)
     */
    private int $batchSize;

    /**
     * @var int Idle time threshold for XCLAIM (ms)
     */
    private int $idleTimeMs;

    /**
     * @param Redis $redis Redis connection
     * @param string $consumerGroup Consumer group name
     * @param string|null $consumerName Consumer name (default: hostname + pid)
     * @param int $blockMs Block timeout (default: 1000ms)
     * @param int $batchSize Messages per batch (default: 100)
     * @param int $idleTimeMs Idle threshold for reclaim (default: 60000ms = 1min)
     */
    public function __construct(
        Redis $redis,
        string $consumerGroup,
        ?string $consumerName = null,
        int $blockMs = 1000,
        int $batchSize = 100,
        int $idleTimeMs = 60000
    ) {
        $this->redis = $redis;
        $this->consumerGroup = $consumerGroup;
        $this->consumerName = $consumerName ?? $this->generateConsumerName();
        $this->blockMs = $blockMs;
        $this->batchSize = $batchSize;
        $this->idleTimeMs = $idleTimeMs;
    }

    /**
     * Ensure consumer group exists.
     *
     * @param string $stream Stream key
     * @return void
     */
    public function ensureGroupExists(string $stream): void
    {
        try {
            // XGROUP CREATE stream group 0 MKSTREAM
            // 0 = start from beginning, $ = start from now
            $this->redis->xGroup('CREATE', $stream, $this->consumerGroup, '0', true);
        } catch (\Throwable $e) {
            // Group already exists - ignore BUSYGROUP error
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                error_log("Redis Stream: Failed to create consumer group: {$e->getMessage()}");
            }
        }
    }

    /**
     * Consume messages from stream with batch processing.
     *
     * @param string $stream Stream key
     * @param callable $callback Message handler (MessageInterface $msg): void
     * @return void
     */
    public function consume(string $stream, callable $callback): void
    {
        $this->consumeMultiple([$stream => $callback]);
    }

    /**
     * Consume from multiple streams simultaneously (preferred method).
     *
     * Uses single XREADGROUP call for all streams for better performance.
     *
     * IMPORTANT: In non-blocking mode, this method polls CONTINUOUSLY until
     * no more messages are available, ensuring all pending messages are processed
     * before returning to the command loop.
     *
     * @param array<string, callable> $streamCallbacks Map of stream => callback
     * @param bool $blocking Enable blocking mode (infinite loop). Default: false for command loop.
     * @return int Number of messages processed in this cycle
     */
    public function consumeMultiple(array $streamCallbacks, bool $blocking = false): int
    {
        if (empty($streamCallbacks)) {
            return 0;
        }

        $this->consuming = true;

        // Ensure all groups exist
        foreach (array_keys($streamCallbacks) as $stream) {
            $this->ensureGroupExists($stream);
        }

        // Build streams array for XREADGROUP: [stream1 => '>', stream2 => '>']
        $streams = array_fill_keys(array_keys($streamCallbacks), '>');

        // Debug: Log subscribed streams (only first time)
        static $logged = false;
        if (!$logged) {
            error_log("Redis Streams Consumer: Subscribed to " . count($streams) . " streams: " . implode(', ', array_keys($streams)));
            $logged = true;
        }

        $pollCount = 0;
        $totalProcessed = 0;
        $consecutiveEmptyPolls = 0;

        // Non-blocking mode: poll until no more messages (exhaust available messages)
        // Blocking mode: infinite loop (for standalone consumer)
        do {
            try {
                // XREADGROUP GROUP group consumer BLOCK ms COUNT batch STREAMS stream1 > stream2 >
                //
                // CRITICAL: ALWAYS use BLOCK > 0 to prevent Redis overload
                //
                // BLOCK 0 causes busy polling:
                // - Creates thousands of empty polls per second
                // - Overwhelms Redis with QPS
                // - Causes "read error on connection" timeouts
                //
                // BLOCK > 0 (e.g., 50ms, 100ms, 200ms) is CORRECT:
                // - When no messages: Redis holds connection, waits for data
                // - When messages arrive: Returns immediately
                // - No QPS when idle = Redis stays healthy
                // - Lower latency than sleep-based backoff
                //
                // Strategy:
                // - Non-blocking mode: BLOCK 50ms (low latency, efficient)
                // - Blocking mode: use configured blockMs (typically 100-1000ms)
                $blockTime = $blocking ? $this->blockMs : 50;

                $messages = $this->redis->xReadGroup(
                    $this->consumerGroup,
                    $this->consumerName,
                    $streams,
                    $this->batchSize,
                    $blockTime
                );

                $messagesThisPoll = 0;

                // Process messages from each stream
                if (is_array($messages) && !empty($messages)) {
                    foreach ($messages as $streamKey => $streamMessages) {
                        if (!empty($streamMessages) && isset($streamCallbacks[$streamKey])) {
                            $processed = $this->processBatch(
                                $streamKey,
                                $streamMessages,
                                $streamCallbacks[$streamKey]
                            );
                            $totalProcessed += $processed;
                            $messagesThisPoll += $processed;
                        }
                    }
                }

                // Track consecutive empty polls
                if ($messagesThisPoll === 0) {
                    $consecutiveEmptyPolls++;
                    // No sleep needed - BLOCK handles waiting efficiently
                } else {
                    $consecutiveEmptyPolls = 0;
                }

                // Periodically check for pending messages (every 10 polls)
                $pollCount++;
                if ($pollCount % 10 === 0) {
                    foreach (array_keys($streamCallbacks) as $stream) {
                        $this->reclaimIdleMessages($stream, $streamCallbacks[$stream]);
                    }
                }

                // In non-blocking mode: keep polling until 2 consecutive empty results
                // This is CORRECT for Redis Streams:
                // - 1st empty: might be between batches
                // - 2nd empty: stream is truly exhausted
                if (!$blocking && $consecutiveEmptyPolls >= 2) {
                    return $totalProcessed;
                }
            } catch (\Throwable $e) {
                error_log("Redis Stream consumer error: {$e->getMessage()}");
                BrokerMetrics::recordError('redis_stream', 'consume');

                // Check if connection error - try to reconnect
                if (str_contains($e->getMessage(), 'read error') || str_contains($e->getMessage(), 'connection')) {
                    error_log("Redis Stream: Connection lost, stopping consumption for this cycle");
                    // Return what we've processed so far, command loop will reconnect on next iteration
                    return $totalProcessed;
                }

                // Brief sleep before retry for other errors
                usleep(100000); // 100ms

                // In non-blocking mode, return on error after logging
                if (!$blocking) {
                    return $totalProcessed;
                }
            }
        } while ($this->consuming && $blocking);

        return $totalProcessed;
    }

    /**
     * Process batch of messages with batch ACK optimization.
     *
     * Redis Streams batch processing strategy:
     * 1. Pull batch via XREADGROUP COUNT N
     * 2. Process all messages in memory
     * 3. Batch ACK successful ones (single XACK call)
     * 4. Leave failed ones in PEL for retry
     *
     * @param string $stream Stream key
     * @param array $messages Messages from XREADGROUP
     * @param callable $callback Message handler
     * @return int Number of messages processed successfully
     */
    private function processBatch(string $stream, array $messages, callable $callback): int
    {
        $startTime = microtime(true);
        $processed = 0;
        $failed = 0;
        $successfulIds = []; // Collect IDs for batch ACK
        $failedIds = [];

        // Process all messages first
        foreach ($messages as $messageId => $fields) {
            try {
                // Extract payload from message fields
                $payload = $fields['payload'] ?? null;

                if ($payload === null) {
                    error_log("Redis Stream: Missing payload in message {$messageId}");
                    $successfulIds[] = $messageId; // ACK invalid messages to remove from PEL
                    continue;
                }

                // Parse message
                $message = Message::fromJson($payload);

                // Call handler
                $callback($message);

                // Mark for ACK (don't ACK immediately)
                $successfulIds[] = $messageId;
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $failedIds[] = $messageId;
                error_log("Redis Stream: Message {$messageId} failed: {$e->getMessage()}");

                // Don't ACK - message will remain in PEL for retry
                // Will be reclaimed by reclaimIdleMessages() after idle threshold
            }
        }

        // Batch ACK all successful messages (single Redis call)
        if (!empty($successfulIds)) {
            $this->ackBatch($stream, $successfulIds);
        }

        $duration = (microtime(true) - $startTime) * 1000;
        BrokerMetrics::recordConsume('redis_stream', $processed, $duration);

        if ($failed > 0) {
            error_log("Redis Stream: Batch processed {$processed} OK, {$failed} failed (kept in PEL)");
        }

        return $processed;
    }

    /**
     * Batch acknowledge multiple messages (single Redis call).
     *
     * This is the CORRECT way to ACK in Redis Streams for high throughput:
     * - Single XACK call for all successful messages
     * - Reduces network round-trips
     * - Clears PEL efficiently
     *
     * @param string $stream Stream key
     * @param array<string> $messageIds Array of message IDs
     * @return void
     */
    private function ackBatch(string $stream, array $messageIds): void
    {
        if (empty($messageIds)) {
            return;
        }

        try {
            // XACK stream group id1 id2 id3 ... idN
            $acked = $this->redis->xAck($stream, $this->consumerGroup, $messageIds);

            if ($acked !== count($messageIds)) {
                error_log("Redis Stream: ACK mismatch - expected " . count($messageIds) . ", got {$acked}");
            }
        } catch (\Throwable $e) {
            error_log("Redis Stream: Failed to batch ACK " . count($messageIds) . " messages: {$e->getMessage()}");
        }
    }

    /**
     * Acknowledge single message (for backward compatibility).
     *
     * @param string $stream Stream key
     * @param string $messageId Message ID
     * @return void
     */
    private function ackMessage(string $stream, string $messageId): void
    {
        $this->ackBatch($stream, [$messageId]);
    }

    /**
     * Reclaim idle pending messages from failed consumers.
     *
     * This handles the case where a consumer crashes before ACKing messages.
     *
     * @param string $stream Stream key
     * @param callable $callback Message handler
     * @return void
     */
    private function reclaimIdleMessages(string $stream, callable $callback): void
    {
        try {
            // XPENDING stream group - + 10 consumer-name
            // Get pending messages for current consumer
            $pending = $this->redis->xPending(
                $stream,
                $this->consumerGroup,
                '-',
                '+',
                10,
                $this->consumerName
            );

            if (!is_array($pending) || empty($pending)) {
                // Check for ANY pending messages from other consumers
                $allPending = $this->redis->xPending($stream, $this->consumerGroup);

                if (is_array($allPending) && isset($allPending[0]) && $allPending[0] > 0) {
                    // There are pending messages - try to claim them
                    $this->claimIdleMessagesFromOthers($stream, $callback);
                }
                return;
            }

            foreach ($pending as $entry) {
                if (!is_array($entry) || count($entry) < 4) {
                    continue;
                }

                [$messageId, , $idleTime,] = $entry;

                // Skip if not idle enough
                if ($idleTime < $this->idleTimeMs) {
                    continue;
                }

                // XCLAIM to take ownership and retry
                $claimed = $this->redis->xClaim(
                    $stream,
                    $this->consumerGroup,
                    $this->consumerName,
                    $this->idleTimeMs,
                    [$messageId],
                    ['JUSTID' => true]
                );

                if (is_array($claimed) && !empty($claimed)) {
                    // Re-read and process claimed messages
                    $messages = $this->redis->xRange($stream, $messageId, $messageId);
                    if (is_array($messages) && !empty($messages)) {
                        $this->processBatch($stream, $messages, $callback);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("Redis Stream: Failed to reclaim idle messages: {$e->getMessage()}");
        }
    }

    /**
     * Claim idle messages from other consumers.
     *
     * @param string $stream Stream key
     * @param callable $callback Message handler
     * @return void
     */
    private function claimIdleMessagesFromOthers(string $stream, callable $callback): void
    {
        try {
            // XAUTOCLAIM stream group consumer min-idle-time start [COUNT count]
            // Available in Redis 6.2+
            if (method_exists($this->redis, 'xAutoClaim')) {
                $result = $this->redis->xAutoClaim(
                    $stream,
                    $this->consumerGroup,
                    $this->consumerName,
                    $this->idleTimeMs,
                    '0-0',
                    10
                );

                if (is_array($result) && isset($result[1]) && is_array($result[1])) {
                    $this->processBatch($stream, $result[1], $callback);
                }
            }
        } catch (\Throwable $e) {
            // XAUTOCLAIM not available or failed - skip
        }
    }

    /**
     * Stop consuming.
     *
     * @return void
     */
    public function stopConsuming(): void
    {
        $this->consuming = false;
    }

    /**
     * Get pending message count.
     *
     * @param string $stream Stream key
     * @return int Number of pending messages
     */
    public function getPendingCount(string $stream): int
    {
        try {
            $pending = $this->redis->xPending($stream, $this->consumerGroup);
            return is_array($pending) && isset($pending[0]) ? (int) $pending[0] : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Get consumer info.
     *
     * @param string $stream Stream key
     * @return array Consumer information
     */
    public function getConsumerInfo(string $stream): array
    {
        try {
            $groups = $this->redis->xInfo('GROUPS', $stream);

            if (!is_array($groups)) {
                return [];
            }

            foreach ($groups as $group) {
                if (is_array($group) && ($group['name'] ?? null) === $this->consumerGroup) {
                    return $group;
                }
            }

            return [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Generate unique consumer name.
     *
     * @return string
     */
    private function generateConsumerName(): string
    {
        $hostname = gethostname() ?: 'unknown';
        $pid = getmypid() ?: rand(1000, 9999);
        return "{$hostname}-{$pid}";
    }

    /**
     * Delete consumer from group.
     *
     * @param string $stream Stream key
     * @return bool
     */
    public function deleteConsumer(string $stream): bool
    {
        try {
            return (bool) $this->redis->xGroup(
                'DELCONSUMER',
                $stream,
                $this->consumerGroup,
                $this->consumerName
            );
        } catch (\Throwable) {
            return false;
        }
    }
}
