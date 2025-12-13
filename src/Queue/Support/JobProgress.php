<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Support;

use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class JobProgress
 *
 * Tracks job execution progress (0-100%) for long-running jobs.
 * Uses cache for efficient storage and retrieval.
 *
 * Performance:
 * - O(1) set/get operations
 * - Efficient cache storage
 * - Automatic cleanup
 *
 * Clean Architecture:
 * - Single Responsibility: Progress tracking only
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
final class JobProgress
{
    private const CACHE_PREFIX = 'job_progress:';
    private const DEFAULT_TTL = 86400; // 24 hours

    public function __construct(
        private CacheInterface $cache
    ) {}

    /**
     * Set job progress (0-100).
     *
     * Performance: O(1)
     *
     * @param string $jobId
     * @param int $progress Progress percentage (0-100)
     * @param string|null $message Optional progress message
     * @return void
     */
    public function set(string $jobId, int $progress, ?string $message = null): void
    {
        $progress = max(0, min(100, $progress)); // Clamp to 0-100

        $data = [
            'progress' => $progress,
            'message' => $message,
            'updated_at' => now()->getTimestamp(),
        ];

        $this->cache->set(
            self::CACHE_PREFIX . $jobId,
            $data,
            self::DEFAULT_TTL
        );
    }

    /**
     * Get job progress.
     *
     * Performance: O(1)
     *
     * @param string $jobId
     * @return array{progress: int, message: string|null, updated_at: int}|null
     */
    public function get(string $jobId): ?array
    {
        return $this->cache->get(self::CACHE_PREFIX . $jobId);
    }

    /**
     * Increment job progress.
     *
     * Performance: O(1)
     *
     * @param string $jobId
     * @param int $by Amount to increment by
     * @param string|null $message Optional progress message
     * @return int New progress value
     */
    public function increment(string $jobId, int $by = 1, ?string $message = null): int
    {
        $current = $this->get($jobId);
        $newProgress = ($current['progress'] ?? 0) + $by;
        $this->set($jobId, $newProgress, $message);
        return $newProgress;
    }

    /**
     * Clear job progress.
     *
     * Performance: O(1)
     *
     * @param string $jobId
     * @return void
     */
    public function clear(string $jobId): void
    {
        $this->cache->delete(self::CACHE_PREFIX . $jobId);
    }

    /**
     * Check if job has progress tracking.
     *
     * Performance: O(1)
     *
     * @param string $jobId
     * @return bool
     */
    public function has(string $jobId): bool
    {
        return $this->cache->has(self::CACHE_PREFIX . $jobId);
    }
}
