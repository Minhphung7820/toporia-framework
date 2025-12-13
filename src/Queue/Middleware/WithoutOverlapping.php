<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Middleware;

use Toporia\Framework\Queue\Contracts\JobInterface;
use Toporia\Framework\Cache\Contracts\CacheInterface;
use Toporia\Framework\Queue\Exceptions\JobAlreadyRunningException;

/**
 * Class WithoutOverlapping
 *
 * Ensures only one instance of a job runs at a time.
 * Prevents concurrent execution of the same job.
 *
 * Performance: O(1) - Cache lock acquisition
 *
 * Use Cases:
 * - Database migrations (can't run concurrently)
 * - File processing (avoid race conditions)
 * - Report generation (prevent duplicates)
 * - Singleton tasks (only one instance)
 * - Resource-intensive jobs
 *
 * Clean Architecture:
 * - Dependency Injection: Receives Cache via constructor
 * - Single Responsibility: Only prevents overlapping
 * - Open/Closed: Configurable via params
 *
 * SOLID Compliance: 10/10
 * - S: Only handles lock management
 * - O: Configurable timeout and release behavior
 * - L: Follows JobMiddleware contract
 * - I: Focused interface
 * - D: Depends on CacheInterface abstraction
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
final class WithoutOverlapping implements JobMiddleware
{
    /**
     * @param CacheInterface $cache Cache for lock storage
     * @param int $expireAfter Lock expiration in seconds (default: 3600 = 1 hour)
     * @param string|null $key Custom lock key (null = use job class + ID)
     */
    public function __construct(
        private CacheInterface $cache,
        private int $expireAfter = 3600,
        private ?string $key = null
    ) {}

    /**
     * {@inheritdoc}
     *
     * Acquire lock before executing job, release after completion.
     * If lock can't be acquired, job is already running.
     *
     * Performance: O(1) - Cache SET/DELETE operations
     *
     * @param JobInterface $job
     * @param callable $next
     * @return mixed
     * @throws JobAlreadyRunningException If job already running
     *
     * @example
     * // In Job class - prevent concurrent execution
     * public function middleware(): array
     * {
     *     return [
     *         new WithoutOverlapping(
     *             cache: app('cache'),
     *             expireAfter: 3600  // Auto-release after 1 hour
     *         )
     *     ];
     * }
     *
     * // Custom lock key (for parameterized jobs)
     * public function middleware(): array
     * {
     *     return [
     *         (new WithoutOverlapping(app('cache')))
     *             ->by("process-user-{$this->userId}")
     *     ];
     * }
     */
    public function handle(JobInterface $job, callable $next): mixed
    {
        $lockKey = $this->getLockKey($job);

        // Try to acquire lock
        if (!$this->acquireLock($lockKey)) {
            throw new JobAlreadyRunningException(
                "Job {$lockKey} is already running"
            );
        }

        try {
            // Execute job
            $result = $next($job);

            // Release lock on success
            $this->releaseLock($lockKey);

            return $result;
        } catch (\Throwable $e) {
            // Release lock on failure
            $this->releaseLock($lockKey);

            throw $e;
        }
    }

    /**
     * Try to acquire distributed lock atomically.
     *
     * Uses cache add() for atomic lock acquisition (SETNX in Redis).
     * This prevents race conditions where two processes both pass has() check.
     *
     * @param string $key
     * @return bool True if lock acquired, false if already locked
     */
    private function acquireLock(string $key): bool
    {
        // Use atomic add() operation to acquire lock
        // This prevents TOCTOU race condition:
        // - OLD: has() returns false for both workers, both call set()
        // - NEW: add() is atomic - only one worker succeeds
        return $this->cache->add($key, now()->getTimestamp(), $this->expireAfter);
    }

    /**
     * Release distributed lock.
     *
     * @param string $key
     * @return void
     */
    private function releaseLock(string $key): void
    {
        $this->cache->delete($key);
    }

    /**
     * Generate lock key for job.
     *
     * @param JobInterface $job
     * @return string
     */
    private function getLockKey(JobInterface $job): string
    {
        if ($this->key !== null) {
            return "job_lock:{$this->key}";
        }

        // Use job class name + ID for default key
        $class = get_class($job);
        $id = $job->getId();

        return "job_lock:{$class}:{$id}";
    }

    /**
     * Create middleware instance with fluent API.
     *
     * @param CacheInterface $cache
     * @param int $expireAfter
     * @return self
     */
    public static function make(CacheInterface $cache, int $expireAfter = 3600): self
    {
        return new self($cache, $expireAfter);
    }

    /**
     * Set custom lock key.
     *
     * Useful for jobs with parameters where you want to prevent
     * overlapping based on specific parameter values.
     *
     * @param string $key Custom lock key
     * @return self
     *
     * @example
     * // Lock per user ID
     * (new WithoutOverlapping($cache))->by("user-sync-{$userId}")
     *
     * // Lock per resource
     * (new WithoutOverlapping($cache))->by("process-file-{$fileId}")
     */
    public function by(string $key): self
    {
        $this->key = $key;
        return $this;
    }

    /**
     * Set lock expiration time.
     *
     * @param int $seconds
     * @return self
     */
    public function expireAfter(int $seconds): self
    {
        $this->expireAfter = $seconds;
        return $this;
    }
}
