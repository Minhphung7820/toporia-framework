<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Support;

use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class QueueMetrics
 *
 * Collects queue-level metrics: size, throughput, latency.
 * Provides insights into queue performance and health.
 *
 * Performance:
 * - O(1) metric recording
 * - Efficient aggregation
 * - Automatic cleanup
 *
 * Clean Architecture:
 * - Single Responsibility: Queue metrics only
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
final class QueueMetrics
{
    private const CACHE_PREFIX = 'queue_metrics:';
    private const METRICS_TTL = 604800; // 7 days

    public function __construct(
        private CacheInterface $cache
    ) {}

    /**
     * Record queue operation.
     *
     * Performance: O(1)
     *
     * @param string $queueName
     * @param string $operation 'push' | 'pop' | 'process'
     * @param float $duration Duration in seconds (for process operations)
     * @return void
     */
    public function record(string $queueName, string $operation, float $duration = 0.0): void
    {
        $key = self::CACHE_PREFIX . $queueName;
        $metrics = $this->cache->get($key, [
            'pushes' => 0,
            'pops' => 0,
            'processes' => 0,
            'total_duration' => 0.0,
            'min_duration' => PHP_FLOAT_MAX,
            'max_duration' => 0.0,
            'last_updated' => now()->getTimestamp(),
        ]);

        switch ($operation) {
            case 'push':
                $metrics['pushes']++;
                break;
            case 'pop':
                $metrics['pops']++;
                break;
            case 'process':
                $metrics['processes']++;
                $metrics['total_duration'] += $duration;
                $metrics['min_duration'] = min($metrics['min_duration'], $duration);
                $metrics['max_duration'] = max($metrics['max_duration'], $duration);
                break;
        }

        $metrics['last_updated'] = now()->getTimestamp();
        $this->cache->set($key, $metrics, self::METRICS_TTL);
    }

    /**
     * Get metrics for a queue.
     *
     * Performance: O(1)
     *
     * @param string $queueName
     * @return array<string, mixed>
     */
    public function get(string $queueName): array
    {
        $metrics = $this->cache->get(self::CACHE_PREFIX . $queueName, [
            'pushes' => 0,
            'pops' => 0,
            'processes' => 0,
            'total_duration' => 0.0,
            'min_duration' => 0.0,
            'max_duration' => 0.0,
            'last_updated' => 0,
        ]);

        $processes = $metrics['processes'];
        if ($processes > 0) {
            $metrics['avg_duration'] = $metrics['total_duration'] / $processes;
            $metrics['throughput'] = $processes / max(1, (now()->getTimestamp() - $metrics['last_updated']) / 3600); // per hour
        } else {
            $metrics['avg_duration'] = 0.0;
            $metrics['throughput'] = 0.0;
        }

        return $metrics;
    }

    /**
     * Clear metrics for a queue.
     *
     * Performance: O(1)
     *
     * @param string $queueName
     * @return void
     */
    public function clear(string $queueName): void
    {
        $this->cache->delete(self::CACHE_PREFIX . $queueName);
    }
}
