<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Middleware;

use Toporia\Framework\Queue\Contracts\JobInterface;
use Toporia\Framework\Cache\Contracts\CacheInterface;
use Toporia\Framework\Queue\Exceptions\RateLimitExceededException;

/**
 * Class Throttle
 *
 * Throttles job execution per time window (e.g., max 10 jobs per minute).
 * Different from RateLimited: Throttle is simpler, per-job-class based.
 *
 * Performance:
 * - O(1) throttle check
 * - Efficient cache storage
 * - Atomic operations
 *
 * Clean Architecture:
 * - Single Responsibility: Throttling only
 * - Dependency Inversion: Uses CacheInterface
 * - High Reusability: Can be used across different contexts
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
final class Throttle implements JobMiddleware
{
    private const CACHE_PREFIX = 'job_throttle:';

    public function __construct(
        private CacheInterface $cache,
        private int $maxJobs = 10,
        private int $decaySeconds = 60,
        private ?string $key = null
    ) {}

    /**
     * {@inheritdoc}
     */
    public function handle(JobInterface $job, callable $next): mixed
    {
        $key = $this->getKey($job);
        $cacheKey = self::CACHE_PREFIX . $key;

        // Get current count
        $count = $this->cache->get($cacheKey, 0);

        // Check if throttle limit exceeded
        if ($count >= $this->maxJobs) {
            // Use decaySeconds as retry delay (simplified approach)
            // In a more advanced implementation, we could track timestamps
            // to calculate exact remaining time
            throw new RateLimitExceededException(
                "Job throttle exceeded for {$key}. Max {$this->maxJobs} jobs per {$this->decaySeconds}s. Retry in {$this->decaySeconds}s.",
                $this->decaySeconds
            );
        }

        // Increment counter
        $this->cache->set($cacheKey, $count + 1, $this->decaySeconds);

        try {
            return $next($job);
        } finally {
            // Decrement on completion (optional - depends on use case)
            // For most cases, we want to keep the count until TTL expires
        }
    }

    /**
     * Get throttle key for job.
     *
     * @param JobInterface $job
     * @return string
     */
    private function getKey(JobInterface $job): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        // Use job class name as default key
        return get_class($job);
    }

    /**
     * Create throttle middleware with fluent API.
     *
     * @param CacheInterface $cache
     * @param int $maxJobs
     * @param int $decaySeconds
     * @return self
     */
    public static function make(CacheInterface $cache, int $maxJobs = 10, int $decaySeconds = 60): self
    {
        return new self($cache, $maxJobs, $decaySeconds);
    }

    /**
     * Set custom throttle key.
     *
     * @param string $key
     * @return self
     */
    public function by(string $key): self
    {
        $this->key = $key;
        return $this;
    }
}
