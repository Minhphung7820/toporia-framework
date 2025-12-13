<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Concerns;


/**
 * Trait PerformanceAssertions
 *
 * Trait providing reusable functionality for PerformanceAssertions in the
 * Concerns layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait PerformanceAssertions
{
    /**
     * Assert that execution time is less than threshold.
     *
     * Performance: O(1) - Time measurement
     */
    protected function assertExecutionTimeLessThan(callable $callback, float $maxSeconds, string $message = ''): void
    {
        $start = microtime(true);
        $callback();
        $duration = microtime(true) - $start;

        $this->assertLessThan(
            $maxSeconds,
            $duration,
            $message ?: "Execution took {$duration}s, expected less than {$maxSeconds}s"
        );
    }

    /**
     * Assert that execution time is greater than threshold.
     *
     * Performance: O(1) - Time measurement
     */
    protected function assertExecutionTimeGreaterThan(callable $callback, float $minSeconds, string $message = ''): void
    {
        $start = microtime(true);
        $callback();
        $duration = microtime(true) - $start;

        $this->assertGreaterThan(
            $minSeconds,
            $duration,
            $message ?: "Execution took {$duration}s, expected greater than {$minSeconds}s"
        );
    }

    /**
     * Assert memory usage is less than threshold.
     *
     * Performance: O(1) - Memory check
     */
    protected function assertMemoryUsageLessThan(callable $callback, int $maxBytes, string $message = ''): void
    {
        $before = memory_get_usage();
        $callback();
        $after = memory_get_usage();
        $used = $after - $before;

        $this->assertLessThan(
            $maxBytes,
            $used,
            $message ?: "Memory usage was {$used} bytes, expected less than {$maxBytes} bytes"
        );
    }

    /**
     * Measure execution time.
     *
     * Performance: O(1)
     */
    protected function measureTime(callable $callback): float
    {
        $start = microtime(true);
        $callback();
        return microtime(true) - $start;
    }

    /**
     * Measure memory usage.
     *
     * Performance: O(1)
     */
    protected function measureMemory(callable $callback): int
    {
        $before = memory_get_usage();
        $callback();
        $after = memory_get_usage();
        return $after - $before;
    }
}

