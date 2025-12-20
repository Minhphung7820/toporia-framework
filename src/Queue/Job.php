<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue;

use Toporia\Framework\Bus\Contracts\QueueableInterface;
use Toporia\Framework\Queue\Contracts\JobInterface;
use Toporia\Framework\Queue\Backoff\{BackoffStrategy, ConstantBackoff};
use Toporia\Framework\Queue\Middleware\JobMiddleware;
use Toporia\Framework\Queue\Support\JobProgress;


/**
 * Abstract Class Job
 *
 * Base job class for queued background tasks with retry logic, exponential
 * backoff, failure handling, and middleware support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Queue
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class Job implements JobInterface, QueueableInterface
{
    protected string $id;
    protected string $queue = 'default';
    protected int $attempts = 0;
    protected int $delay = 0;

    /**
     * Maximum number of retry attempts.
     * Can be set via property or tries() method.
     *
     * @var int
     */
    protected int $maxAttempts = 3;

    /**
     * Number of seconds to wait before retrying (simple backoff).
     * Alternative to backoff() method for simple constant delays.
     * If set, overrides backoff strategy.
     *
     * @var int|null
     */
    protected ?int $retryAfter = null;

    /**
     * Backoff strategy for calculating retry delays.
     * More flexible than $retryAfter.
     *
     * @var BackoffStrategy|null
     */
    protected ?BackoffStrategy $backoff = null;

    /**
     * Middleware to run before job execution.
     * Can be set via property or middleware() method.
     *
     * @var array<JobMiddleware>
     */
    protected array $middleware = [];

    /**
     * Job execution timeout in seconds.
     * 0 means no timeout.
     *
     * @var int
     */
    protected int $timeout = 0;

    /**
     * Job priority (higher = processed first).
     * Default: 0 (normal priority)
     *
     * @var int
     */
    protected int $priority = 0;

    /**
     * Job tags for filtering and monitoring.
     *
     * @var array<string>
     */
    protected array $tags = [];

    /**
     * Unique job identifier (prevents duplicate jobs).
     * If set, only one job with this unique ID can be queued at a time.
     *
     * @var string|null
     */
    protected ?string $uniqueId = null;

    /**
     * Unique job lock expiration in seconds.
     * Default: 3600 (1 hour)
     *
     * @var int
     */
    protected int $uniqueFor = 3600;

    /**
     * Track progress for this job.
     * If true, progress can be tracked via JobProgress.
     *
     * @var bool
     */
    protected bool $trackProgress = false;

    public function __construct()
    {
        $this->id = uniqid('job_', true);
    }

    /**
     * Handle the job execution
     *
     * This method must be implemented in concrete job classes.
     * The signature can vary to accept dependencies via type-hinted parameters.
     * The Worker will use the container to inject dependencies automatically.
     *
     * Examples:
     *   public function handle(): void { ... }
     *   public function handle(MailerInterface $mailer): void { ... }
     *   public function handle(Repository $repo, Logger $logger): void { ... }
     *
     * Note: PHP doesn't support covariant method signatures in abstract classes,
     * so we can't enforce this signature. Child classes MUST implement handle().
     */

    public function getId(): string
    {
        return $this->id;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    /**
     * Decrement the number of attempts.
     *
     * Used when job needs to be retried without counting as a failed attempt.
     * Examples: RateLimitException, JobAlreadyRunning
     *
     * @return void
     */
    public function decrementAttempts(): void
    {
        if ($this->attempts > 0) {
            $this->attempts--;
        }
    }

    /**
     * Handle job failure
     * Override to implement custom failure handling
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        // Log the failure
        error_log(sprintf(
            'Job %s failed: %s',
            $this->getId(),
            $exception->getMessage()
        ));
    }

    /**
     * Called when job execution times out.
     * Override to implement custom cleanup logic.
     *
     * @return void
     */
    public function timeout(): void
    {
        // Default: no cleanup
    }

    /**
     * Get the timeout value in seconds.
     *
     * @return int Timeout in seconds (0 = no timeout)
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Set job execution timeout.
     *
     * @param int $seconds Timeout in seconds
     * @return self
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Set the queue name
     *
     * @param string $queue
     * @return self
     */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Set the maximum number of attempts
     *
     * @param int $maxAttempts
     * @return self
     */
    public function tries(int $maxAttempts): self
    {
        $this->maxAttempts = $maxAttempts;
        return $this;
    }

    /**
     * Set backoff strategy for retries.
     *
     * Controls delay between retry attempts.
     *
     * @param BackoffStrategy $strategy Backoff strategy
     * @return self
     *
     * @example
     * // Constant backoff (10 seconds between retries)
     * $job->backoff(new ConstantBackoff(10));
     *
     * // Exponential backoff (2, 4, 8, 16... seconds)
     * $job->backoff(new ExponentialBackoff(base: 2, max: 300));
     *
     * // Custom backoff
     * $job->backoff(new CustomBackoff([5, 10, 30, 60]));
     */
    public function backoff(BackoffStrategy $strategy): self
    {
        $this->backoff = $strategy;
        return $this;
    }

    /**
     * Get backoff delay for next retry.
     *
     * Priority order:
     * 1. $retryAfter property (simple constant delay)
     * 2. BackoffStrategy (flexible delay calculation)
     * 3. 0 (immediate retry)
     *
     * Performance: O(1) - Backoff calculation is constant time
     *
     * CRITICAL: Pass current attempt count (not attempts - 1) to backoff strategy.
     * The backoff strategy is responsible for calculating delay based on attempt number.
     * For exponential backoff with base=5:
     * - Attempt 1: 5^1 = 5s
     * - Attempt 2: 5^2 = 25s
     * - Attempt 3: 5^3 = 125s (capped at max)
     *
     * @return int Delay in seconds
     *
     * @example
     * // In Job class - simple constant delay
     * protected int $retryAfter = 60; // Wait 60s between retries
     *
     * // In Job class - exponential backoff
     * public function __construct() {
     *     parent::__construct();
     *     $this->backoff = new ExponentialBackoff(base: 2, max: 300);
     * }
     */
    public function getBackoffDelay(): int
    {
        // Priority 1: Simple retryAfter property
        if ($this->retryAfter !== null) {
            return $this->retryAfter;
        }

        // Priority 2: Backoff strategy
        // CRITICAL FIX: Ensure we have valid attempts count
        // Pass current attempts (after increment) for accurate backoff calculation
        if ($this->backoff !== null) {
            // Ensure attempts is at least 1 for first retry
            $attemptCount = max(1, $this->attempts);
            return $this->backoff->calculate($attemptCount);
        }

        // Priority 3: No delay (immediate retry)
        return 0;
    }

    /**
     * Get middleware for this job.
     *
     * Override in child classes to define job-specific middleware.
     *
     * @return array<JobMiddleware> Array of middleware instances
     *
     * @example
     * // In SendEmailJob class
     * public function middleware(): array
     * {
     *     return [
     *         new RateLimited(app('limiter'), maxAttempts: 10, decayMinutes: 1),
     *         new WithoutOverlapping(app('cache'), expireAfter: 300)
     *     ];
     * }
     */
    public function middleware(): array
    {
        return $this->middleware;
    }

    /**
     * Get the delay in seconds.
     *
     * @return int
     */
    public function getDelay(): int
    {
        return $this->delay;
    }

    /**
     * Delay the job execution
     *
     * @param int $seconds
     * @return self
     */
    public function delay(int $seconds): self
    {
        $this->delay = $seconds;
        return $this;
    }

    /**
     * Dispatch the job to the queue (static dispatch).
     *
     * Usage:
     * ```php
     * // Simple dispatch
     * SendEmailJob::dispatch($to, $subject, $message);
     *
     * // With fluent API
     * SendEmailJob::dispatch($to, $subject, $message)
     *     ->onQueue('emails')
     *     ->delay(60);
     * ```
     *
     * Performance: O(1) - Creates job instance and returns PendingDispatch
     * SOLID: Single Responsibility - each job knows how to dispatch itself
     *
     * @param mixed ...$args Constructor arguments
     * @return PendingDispatch
     */
    public static function dispatch(...$args): PendingDispatch
    {
        if (!function_exists('app') || !app()->has('dispatcher')) {
            throw new \RuntimeException('Job dispatcher not available in container. Register JobDispatcher in QueueServiceProvider.');
        }

        // Create job instance with constructor arguments
        $job = new static(...$args);

        // Return PendingDispatch for fluent API (auto-dispatches on destruct)
        $dispatcher = app('dispatcher');
        return new PendingDispatch($job, $dispatcher);
    }

    /**
     * Dispatch the job synchronously (execute immediately).
     *
     * Usage:
     * ```php
     * $result = SendEmailJob::dispatchSync($to, $subject, $message);
     * ```
     *
     * Performance: O(N) where N = job execution time (blocking)
     *
     * @param mixed ...$args Constructor arguments
     * @return mixed Job result
     */
    public static function dispatchSync(...$args): mixed
    {
        if (!function_exists('app') || !app()->has('dispatcher')) {
            throw new \RuntimeException('Job dispatcher not available in container.');
        }

        $job = new static(...$args);
        return app('dispatcher')->dispatchSync($job);
    }

    /**
     * Dispatch the job after a delay.
     *
     * Usage:
     * ```php
     * SendEmailJob::dispatchAfter(60, $to, $subject, $message); // 60 seconds delay
     * ```
     *
     * Performance: O(1) - Queues job with delayed execution
     *
     * @param int $delay Delay in seconds
     * @param mixed ...$args Constructor arguments
     * @return PendingDispatch
     */
    public static function dispatchAfter(int $delay, ...$args): PendingDispatch
    {
        return static::dispatch(...$args)->delay($delay);
    }

    /**
     * Set job priority.
     *
     * Higher priority jobs are processed first.
     *
     * @param int $priority Priority value (higher = processed first)
     * @return self
     */
    public function priority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * Get job priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Add tags to the job.
     *
     * Tags can be used for filtering and monitoring.
     *
     * @param string|array<string> $tags
     * @return self
     */
    public function tag(string|array $tags): self
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    /**
     * Get job tags.
     *
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Make job unique (prevent duplicates).
     *
     * Only one job with the same unique ID can be queued at a time.
     *
     * @param string $uniqueId Unique identifier
     * @param int $for Lock expiration in seconds (default: 3600)
     * @return self
     */
    public function unique(string $uniqueId, int $for = 3600): self
    {
        $this->uniqueId = $uniqueId;
        $this->uniqueFor = $for;
        return $this;
    }

    /**
     * Get unique job ID.
     *
     * @return string|null
     */
    public function getUniqueId(): ?string
    {
        return $this->uniqueId;
    }

    /**
     * Get unique lock expiration.
     *
     * @return int
     */
    public function getUniqueFor(): int
    {
        return $this->uniqueFor;
    }

    /**
     * Enable progress tracking for this job.
     *
     * @return self
     */
    public function trackProgress(): self
    {
        $this->trackProgress = true;
        return $this;
    }

    /**
     * Check if progress tracking is enabled.
     *
     * @return bool
     */
    public function shouldTrackProgress(): bool
    {
        return $this->trackProgress;
    }

    /**
     * Report progress (0-100).
     *
     * Requires progress tracking to be enabled.
     *
     * @param int $progress Progress percentage (0-100)
     * @param string|null $message Optional progress message
     * @return void
     */
    public function reportProgress(int $progress, ?string $message = null): void
    {
        if (!$this->trackProgress) {
            return; // Silently ignore if tracking not enabled
        }

        // Try to get JobProgress from container
        // Use app(Class) instead of app()->get() since Application doesn't have get() method
        if (function_exists('app') && app()->has(JobProgress::class)) {
            $progressTracker = app(JobProgress::class);
            $progressTracker->set($this->id, $progress, $message);
        }
    }
}
