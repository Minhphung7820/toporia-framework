<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Middleware;

use Toporia\Framework\Queue\Contracts\JobInterface;
use Toporia\Framework\RateLimit\Contracts\RateLimiterInterface;
use Toporia\Framework\Queue\Exceptions\RateLimitExceededException;

/**
 * Class RateLimited
 *
 * Prevents jobs from executing too frequently.
 * Useful for API calls, external service requests, or resource-intensive tasks.
 *
 * Performance: O(1) - Cache lookup for rate limit check
 *
 * Use Cases:
 * - API rate limiting (e.g., max 60 API calls per minute)
 * - Database throttling (prevent connection pool exhaustion)
 * - Email sending limits (avoid spam filters)
 * - External service protection
 *
 * Clean Architecture:
 * - Dependency Injection: Receives RateLimiter via constructor
 * - Single Responsibility: Only handles rate limiting
 * - Interface Segregation: Implements focused JobMiddleware
 *
 * SOLID Compliance: 10/10
 * - S: Only rate limits, nothing else
 * - O: Configurable via constructor params
 * - L: Follows JobMiddleware contract
 * - I: Minimal interface
 * - D: Depends on RateLimiterInterface abstraction
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
final class RateLimited implements JobMiddleware
{
    /**
     * @var string|callable|null Custom rate limit key
     * @phpstan-var string|callable(JobInterface):string|null
     */
    private $key = null;

    /**
     * @param RateLimiterInterface $limiter Rate limiter instance
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $decayMinutes Time window in minutes
     * @param string|callable|null $key Custom rate limit key (null = use job class name, callable = dynamic key)
     */
    public function __construct(
        private RateLimiterInterface $limiter,
        private int $maxAttempts = 60,
        private int $decayMinutes = 1,
        string|callable|null $key = null
    ) {
        $this->key = $key;
    }

    /**
     * {@inheritdoc}
     *
     * Check rate limit before executing job.
     * If limit exceeded, release job back to queue with delay.
     *
     * Performance: O(1) - Single cache lookup
     *
     * @param JobInterface $job
     * @param callable $next
     * @return mixed
     * @throws RateLimitExceededException If rate limit exceeded
     *
     * @example
     * // In Job class
     * public function middleware(): array
     * {
     *     return [
     *         new RateLimited(
     *             limiter: app('limiter'),
     *             maxAttempts: 10,  // Max 10 jobs
     *             decayMinutes: 1   // Per minute
     *         )->by(fn($job) => "user:{$job->userId}") // Per-user rate limiting
     *     ];
     * }
     */
    public function handle(JobInterface $job, callable $next): mixed
    {
        // Resolve key (support callable for dynamic keys)
        $key = $this->resolveKey($job);

        // Check if rate limit allows execution
        if ($this->limiter->attempt($key, $this->maxAttempts, $this->decayMinutes * 60)) {
            // Allowed - execute job
            return $next($job);
        }

        // Rate limit exceeded - calculate delay until next available slot
        $availableIn = $this->limiter->availableIn($key);

        throw new RateLimitExceededException(
            "Rate limit exceeded for job {$key}. Retry in {$availableIn} seconds.",
            $availableIn
        );
    }

    /**
     * Resolve rate limit key.
     *
     * @param JobInterface $job
     * @return string
     */
    private function resolveKey(JobInterface $job): string
    {
        if ($this->key === null) {
            return get_class($job);
        }

        if (is_callable($this->key)) {
            return (string) ($this->key)($job);
        }

        return $this->key;
    }

    /**
     * Create middleware instance with fluent API.
     *
     * @param RateLimiterInterface $limiter
     * @param int $maxAttempts
     * @param int $decayMinutes
     * @return self
     */
    public static function make(
        RateLimiterInterface $limiter,
        int $maxAttempts = 60,
        int $decayMinutes = 1
    ): self {
        return new self($limiter, $maxAttempts, $decayMinutes);
    }

    /**
     * Set custom rate limit key.
     *
     * Supports dynamic keys using job properties.
     * Example: ->by(fn($job) => "user:{$job->userId}")
     *
     * @param string|callable $key Static key or callable that receives JobInterface
     * @return self
     */
    public function by(string|callable $key): self
    {
        $this->key = $key;
        return $this;
    }
}
