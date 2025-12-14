<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime;

/**
 * Class BatchResult
 *
 * Immutable result object for batch publish operations.
 * Contains statistics and status information about the batch.
 *
 * Usage:
 *   $result = Broadcast::batch('kafka')
 *       ->channel('events')
 *       ->event('user.action')
 *       ->messages($data)
 *       ->publish();
 *
 *   if ($result->successful()) {
 *       echo "Published {$result->queued} messages";
 *       echo "Throughput: {$result->throughput} msg/s";
 *   }
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class BatchResult
{
    /**
     * @param int $total Total messages attempted
     * @param int $queued Messages successfully queued
     * @param int $failed Messages that failed
     * @param float $durationMs Total operation time in milliseconds
     * @param int $throughput Messages per second
     * @param float $queueTimeMs Time spent queuing messages
     * @param float $flushTimeMs Time spent flushing to broker
     * @param array $details Additional details (batch breakdown, etc.)
     */
    public function __construct(
        public readonly int $total,
        public readonly int $queued,
        public readonly int $failed,
        public readonly float $durationMs,
        public readonly int $throughput,
        public readonly float $queueTimeMs = 0,
        public readonly float $flushTimeMs = 0,
        public readonly array $details = []
    ) {}

    /**
     * Check if all messages were successfully queued.
     *
     * @return bool
     */
    public function successful(): bool
    {
        return $this->failed === 0 && $this->queued === $this->total;
    }

    /**
     * Check if any messages failed.
     *
     * @return bool
     */
    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    /**
     * Get success rate as percentage.
     *
     * @return float 0-100
     */
    public function successRate(): float
    {
        if ($this->total === 0) {
            return 100.0;
        }
        return round(($this->queued / $this->total) * 100, 2);
    }

    /**
     * Get average latency per message in milliseconds.
     *
     * @return float
     */
    public function avgLatencyMs(): float
    {
        if ($this->total === 0) {
            return 0;
        }
        return round($this->durationMs / $this->total, 4);
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->successful(),
            'total' => $this->total,
            'queued' => $this->queued,
            'failed' => $this->failed,
            'duration_ms' => round($this->durationMs, 2),
            'queue_time_ms' => round($this->queueTimeMs, 2),
            'flush_time_ms' => round($this->flushTimeMs, 2),
            'throughput_per_second' => $this->throughput,
            'avg_latency_ms' => $this->avgLatencyMs(),
            'success_rate' => $this->successRate(),
            'details' => $this->details,
        ];
    }

    /**
     * Create from broker publishBatch() result.
     *
     * @param array $brokerResult Result from broker->publishBatch()
     * @param int $total Total messages attempted
     * @return self
     */
    public static function fromBrokerResult(array $brokerResult, int $total): self
    {
        return new self(
            total: $total,
            queued: $brokerResult['queued'] ?? 0,
            failed: $brokerResult['failed'] ?? 0,
            durationMs: $brokerResult['total_time_ms'] ?? 0,
            throughput: $brokerResult['throughput'] ?? 0,
            queueTimeMs: $brokerResult['queue_time_ms'] ?? 0,
            flushTimeMs: $brokerResult['flush_time_ms'] ?? 0,
            details: $brokerResult
        );
    }

    /**
     * Merge multiple BatchResults into one.
     *
     * @param array<BatchResult> $results
     * @return self
     */
    public static function merge(array $results): self
    {
        $total = 0;
        $queued = 0;
        $failed = 0;
        $durationMs = 0;
        $queueTimeMs = 0;
        $flushTimeMs = 0;
        $details = [];

        foreach ($results as $index => $result) {
            $total += $result->total;
            $queued += $result->queued;
            $failed += $result->failed;
            $durationMs += $result->durationMs;
            $queueTimeMs += $result->queueTimeMs;
            $flushTimeMs += $result->flushTimeMs;
            $details["batch_" . ($index + 1)] = $result->toArray();
        }

        $throughput = $durationMs > 0 ? (int) round($queued / ($durationMs / 1000)) : 0;

        return new self(
            total: $total,
            queued: $queued,
            failed: $failed,
            durationMs: $durationMs,
            throughput: $throughput,
            queueTimeMs: $queueTimeMs,
            flushTimeMs: $flushTimeMs,
            details: $details
        );
    }
}
