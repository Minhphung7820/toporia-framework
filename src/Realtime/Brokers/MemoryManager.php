<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

/**
 * Class MemoryManager
 *
 * Memory management and leak prevention for long-running broker consumers.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MemoryManager
{
    private int $messageCount = 0;
    private int $lastCleanup;
    private int $startMemory;

    private const CLEANUP_INTERVAL = 10000; // Every 10k messages
    private const GC_INTERVAL = 1000; // Every 1k messages
    private const MEMORY_LIMIT_PERCENT = 0.8; // 80% of memory_limit

    public function __construct()
    {
        $this->startMemory = memory_get_usage(true);
        $this->lastCleanup = time();
    }

    /**
     * Tick for each processed message.
     *
     * @return void
     */
    public function tick(): void
    {
        $this->messageCount++;

        // Periodic garbage collection
        if ($this->messageCount % self::GC_INTERVAL === 0) {
            gc_collect_cycles();
        }

        // Periodic cleanup and memory check
        if ($this->messageCount % self::CLEANUP_INTERVAL === 0) {
            $this->performCleanup();
        }
    }

    /**
     * Perform cleanup and memory check.
     *
     * @return void
     */
    private function performCleanup(): void
    {
        $now = time();
        $currentMemory = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimit();
        $memoryUsagePercent = $currentMemory / $memoryLimit * 100;

        error_log(sprintf(
            "[MemoryManager] Messages: %d, Memory: %.2f MB / %.2f MB (%.1f%%), Uptime: %ds",
            $this->messageCount,
            $currentMemory / 1024 / 1024,
            $memoryLimit / 1024 / 1024,
            $memoryUsagePercent,
            $now - $this->lastCleanup
        ));

        // Check if memory usage is too high
        if ($currentMemory > $memoryLimit * self::MEMORY_LIMIT_PERCENT) {
            error_log(sprintf(
                "[MemoryManager] WARNING: Memory usage is high (%.1f%%). " .
                "Consider restarting consumer or increasing memory_limit.",
                $memoryUsagePercent
            ));
        }

        // Force garbage collection
        gc_collect_cycles();

        $this->lastCleanup = $now;
    }

    /**
     * Get memory limit in bytes.
     *
     * @return int
     */
    private function getMemoryLimit(): int
    {
        $memoryLimit = ini_get('memory_limit');

        if ($memoryLimit === '-1') {
            return PHP_INT_MAX; // No limit
        }

        // Parse memory limit (e.g., "128M", "1G")
        $unit = strtoupper(substr($memoryLimit, -1));
        $value = (int) substr($memoryLimit, 0, -1);

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $memoryLimit,
        };
    }

    /**
     * Get memory statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryLimit = $this->getMemoryLimit();

        return [
            'message_count' => $this->messageCount,
            'current_memory_mb' => round($currentMemory / 1024 / 1024, 2),
            'peak_memory_mb' => round($peakMemory / 1024 / 1024, 2),
            'start_memory_mb' => round($this->startMemory / 1024 / 1024, 2),
            'memory_limit_mb' => round($memoryLimit / 1024 / 1024, 2),
            'memory_usage_percent' => round($currentMemory / $memoryLimit * 100, 1),
            'uptime_seconds' => time() - $this->lastCleanup,
        ];
    }

    /**
     * Check if memory usage is critical.
     *
     * @return bool
     */
    public function isMemoryCritical(): bool
    {
        $currentMemory = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimit();

        return $currentMemory > $memoryLimit * self::MEMORY_LIMIT_PERCENT;
    }

    /**
     * Force garbage collection.
     *
     * @return void
     */
    public function forceGarbageCollection(): void
    {
        gc_collect_cycles();
    }
}

