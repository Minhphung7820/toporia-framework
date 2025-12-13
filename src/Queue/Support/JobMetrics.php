<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Support;

use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class JobMetrics
 *
 * Collects and aggregates job execution metrics for monitoring.
 * Tracks execution time, memory usage, success/failure rates.
 *
 * Performance:
 * - O(1) metric recording
 * - Efficient aggregation
 * - Automatic cleanup
 *
 * Clean Architecture:
 * - Single Responsibility: Metrics collection only
 * - Dependency Inversion: Uses CacheInterface
 * - High Reusability: Can be used across different contexts
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Queue\Support
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class JobMetrics
{
    private const CACHE_PREFIX = 'job_metrics:';
    private const METRICS_TTL = 604800; // 7 days
    private const MAX_SAMPLES = 1000; // Max samples per job class

    public function __construct(
        private CacheInterface $cache
    ) {}

    /**
     * Record job execution metrics.
     *
     * Performance: O(1)
     *
     * @param string $jobClass Job class name
     * @param bool $success Whether job succeeded
     * @param float $duration Execution duration in seconds
     * @param int $memory Memory usage in bytes
     * @return void
     */
    public function record(string $jobClass, bool $success, float $duration, int $memory): void
    {
        $key = self::CACHE_PREFIX . $jobClass;
        $metrics = $this->cache->get($key, [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'total_duration' => 0.0,
            'total_memory' => 0,
            'min_duration' => PHP_FLOAT_MAX,
            'max_duration' => 0.0,
            'min_memory' => PHP_INT_MAX,
            'max_memory' => 0,
            'samples' => [],
        ]);

        $metrics['total']++;
        if ($success) {
            $metrics['success']++;
        } else {
            $metrics['failed']++;
        }

        $metrics['total_duration'] += $duration;
        $metrics['total_memory'] += $memory;
        $metrics['min_duration'] = min($metrics['min_duration'], $duration);
        $metrics['max_duration'] = max($metrics['max_duration'], $duration);
        $metrics['min_memory'] = min($metrics['min_memory'], $memory);
        $metrics['max_memory'] = max($metrics['max_memory'], $memory);

        // Keep last N samples for detailed analysis
        $metrics['samples'][] = [
            'success' => $success,
            'duration' => $duration,
            'memory' => $memory,
            'timestamp' => now()->getTimestamp(),
        ];

        if (count($metrics['samples']) > self::MAX_SAMPLES) {
            $metrics['samples'] = array_slice($metrics['samples'], -self::MAX_SAMPLES);
        }

        $this->cache->set($key, $metrics, self::METRICS_TTL);
    }

    /**
     * Get metrics for a job class.
     *
     * Performance: O(1)
     *
     * @param string $jobClass
     * @return array<string, mixed>
     */
    public function get(string $jobClass): array
    {
        $metrics = $this->cache->get(self::CACHE_PREFIX . $jobClass, [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'total_duration' => 0.0,
            'total_memory' => 0,
            'min_duration' => 0.0,
            'max_duration' => 0.0,
            'min_memory' => 0,
            'max_memory' => 0,
            'samples' => [],
        ]);

        $total = $metrics['total'];
        if ($total > 0) {
            $metrics['avg_duration'] = $metrics['total_duration'] / $total;
            $metrics['avg_memory'] = $metrics['total_memory'] / $total;
            $metrics['success_rate'] = ($metrics['success'] / $total) * 100;
            $metrics['failure_rate'] = ($metrics['failed'] / $total) * 100;
        } else {
            $metrics['avg_duration'] = 0.0;
            $metrics['avg_memory'] = 0;
            $metrics['success_rate'] = 0.0;
            $metrics['failure_rate'] = 0.0;
        }

        return $metrics;
    }

    /**
     * Clear metrics for a job class.
     *
     * Performance: O(1)
     *
     * @param string $jobClass
     * @return void
     */
    public function clear(string $jobClass): void
    {
        $this->cache->delete(self::CACHE_PREFIX . $jobClass);
    }
}
