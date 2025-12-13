<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Support;

use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class JobCancellation
 *
 * Manages job cancellation requests for queued and running jobs.
 * Uses cache for efficient cancellation tracking.
 *
 * Performance:
 * - O(1) cancellation check
 * - Efficient cache storage
 * - Atomic operations
 *
 * Clean Architecture:
 * - Single Responsibility: Cancellation management only
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
final class JobCancellation
{
    private const CACHE_PREFIX = 'job_cancelled:';
    private const DEFAULT_TTL = 86400; // 24 hours

    public function __construct(
        private CacheInterface $cache
    ) {}

    /**
     * Cancel a job.
     *
     * Performance: O(1)
     *
     * @param string $jobId
     * @return void
     */
    public function cancel(string $jobId): void
    {
        $this->cache->set(
            self::CACHE_PREFIX . $jobId,
            now()->getTimestamp(),
            self::DEFAULT_TTL
        );
    }

    /**
     * Check if job is cancelled.
     *
     * Performance: O(1)
     *
     * @param string $jobId
     * @return bool
     */
    public function isCancelled(string $jobId): bool
    {
        return $this->cache->has(self::CACHE_PREFIX . $jobId);
    }

    /**
     * Get cancellation timestamp.
     *
     * Performance: O(1)
     *
     * @param string $jobId
     * @return int|null
     */
    public function getCancelledAt(string $jobId): ?int
    {
        return $this->cache->get(self::CACHE_PREFIX . $jobId);
    }

    /**
     * Remove cancellation (undo cancel).
     *
     * Performance: O(1)
     *
     * @param string $jobId
     * @return void
     */
    public function remove(string $jobId): void
    {
        $this->cache->delete(self::CACHE_PREFIX . $jobId);
    }
}
