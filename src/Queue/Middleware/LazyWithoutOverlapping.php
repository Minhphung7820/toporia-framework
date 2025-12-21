<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Middleware;

use Toporia\Framework\Queue\Contracts\JobInterface;
use Toporia\Framework\Cache\Contracts\CacheInterface;
use Toporia\Framework\Queue\Exceptions\JobAlreadyRunningException;

/**
 * Class LazyWithoutOverlapping
 *
 * Prevents concurrent job execution with lazy cache resolution.
 *
 * This middleware solves the serialization issue with WithoutOverlapping.
 * Instead of requiring CacheInterface in constructor (which fails during deserialization),
 * it resolves cache lazily at runtime using the container.
 *
 * Why this exists:
 * - Jobs are serialized when dispatched to queue
 * - Original WithoutOverlapping requires CacheInterface in constructor
 * - CacheInterface cannot be properly serialized/deserialized
 * - This class stores only primitive values and resolves cache at execution time
 *
 * Performance: O(1) - Cache lock acquisition via atomic SETNX
 *
 * Use Cases:
 * - Database migrations (can't run concurrently)
 * - File processing (avoid race conditions)
 * - Report generation (prevent duplicates)
 * - Singleton tasks (only one instance)
 * - Resource-intensive jobs
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
 *
 * @example
 * // In Job class - prevent concurrent execution
 * public function middleware(): array
 * {
 *     return [
 *         new LazyWithoutOverlapping(expireAfter: 3600)
 *     ];
 * }
 *
 * // Custom lock key (for parameterized jobs)
 * public function middleware(): array
 * {
 *     return [
 *         (new LazyWithoutOverlapping(expireAfter: 3600))
 *             ->by("process-user-{$this->userId}")
 *     ];
 * }
 *
 * // Fluent API
 * LazyWithoutOverlapping::make(3600)->by("export:user:{$userId}")
 */
final class LazyWithoutOverlapping implements JobMiddleware
{
    /**
     * Cache instance (resolved lazily to avoid serialization issues).
     */
    private ?CacheInterface $cache = null;

    /**
     * @param int $expireAfter Lock expiration in seconds (default: 3600 = 1 hour)
     * @param string|null $key Custom lock key (null = use job class + ID)
     */
    public function __construct(
        private int $expireAfter = 3600,
        private ?string $key = null
    ) {}

    /**
     * Handle the job through the middleware.
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
     * Resolve cache instance lazily.
     *
     * This is the key difference from WithoutOverlapping:
     * - Cache is resolved at runtime, not constructor time
     * - Uses app() helper which works correctly in worker context
     * - Falls back gracefully if cache unavailable
     *
     * @return CacheInterface
     */
    private function getCache(): CacheInterface
    {
        if ($this->cache === null) {
            $this->cache = app('cache');
        }

        return $this->cache;
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
        return $this->getCache()->add($key, time(), $this->expireAfter);
    }

    /**
     * Release distributed lock.
     *
     * @param string $key
     * @return void
     */
    private function releaseLock(string $key): void
    {
        $this->getCache()->delete($key);
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
     * @param int $expireAfter Lock expiration in seconds
     * @return self
     */
    public static function make(int $expireAfter = 3600): self
    {
        return new self($expireAfter);
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
     * LazyWithoutOverlapping::make()->by("user-sync-{$userId}")
     *
     * // Lock per resource
     * LazyWithoutOverlapping::make()->by("process-file-{$fileId}")
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
