<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Middleware;

use Toporia\Framework\Queue\Contracts\JobInterface;
use Toporia\Framework\Queue\Job;
use Toporia\Framework\Cache\Contracts\CacheInterface;
use Toporia\Framework\Queue\Exceptions\JobAlreadyRunningException;

/**
 * Class EnsureUnique
 *
 * Ensures only one job with the same unique ID is queued at a time.
 * Prevents duplicate jobs from being processed.
 *
 * Performance: O(1) - Cache lock check
 *
 * Clean Architecture:
 * - Single Responsibility: Unique job enforcement only
 * - Dependency Inversion: Uses CacheInterface
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Queue\Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class EnsureUnique implements JobMiddleware
{
    public function __construct(
        private CacheInterface $cache
    ) {}

    /**
     * {@inheritdoc}
     */
    public function handle(JobInterface $job, callable $next): mixed
    {
        // Only Job class supports unique constraints
        if (!$job instanceof Job) {
            return $next($job);
        }

        $uniqueId = $job->getUniqueId();

        if ($uniqueId === null) {
            // No unique constraint, proceed normally
            return $next($job);
        }

        $lockKey = "job_unique:{$uniqueId}";
        $uniqueFor = $job->getUniqueFor();

        // Try to acquire lock atomically using add() (SETNX in Redis)
        // This prevents race conditions where two workers both pass has() check
        // and then both set the lock - with add() only one will succeed
        if (!$this->cache->add($lockKey, now()->getTimestamp(), $uniqueFor)) {
            // Lock already exists - job is already queued/running
            throw new JobAlreadyRunningException(
                "Job with unique ID '{$uniqueId}' is already queued"
            );
        }

        try {
            $result = $next($job);
            return $result;
        } finally {
            // Release lock after job completes
            $this->cache->delete($lockKey);
        }
    }
}
