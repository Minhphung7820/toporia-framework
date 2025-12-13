<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Support\ColoredLogger;
use Toporia\Framework\Queue\Contracts\{JobInterface, QueueInterface};
use Toporia\Framework\Queue\Exceptions\{RateLimitExceededException, JobAlreadyRunningException, JobTimeoutException};
use Toporia\Framework\Queue\Events\{JobQueued, JobProcessing, JobProcessed, JobFailed, JobTimedOut, JobRetrying, WorkerStopping};
use Toporia\Framework\Queue\Job;
use Toporia\Framework\Queue\Middleware\EnsureUnique;
use Toporia\Framework\Queue\Support\{JobCancellation, JobMetrics, QueueMetrics};
use Toporia\Framework\Events\Contracts\EventDispatcherInterface;
use Toporia\Framework\Queue\RedisQueue;

/**
 * Class Worker
 *
 * Processes jobs from the queue with multi-queue support.
 * Handles job execution, retries, and failure management.
 *
 * Multi-Queue Features:
 * - Supports single or multiple queues with priority order
 * - First queue in array has highest priority
 * - Efficient round-robin polling across queues
 *
 * Performance:
 * - O(Q) per iteration where Q = number of queues
 * - Graceful shutdown support (waits for current job)
 * - Configurable sleep between iterations
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
final class Worker
{
    private bool $shouldQuit = false;
    private int $processed = 0;
    private ColoredLogger $logger;
    private ?EventDispatcherInterface $dispatcher = null;
    private int $memoryLimit = 0;
    private int $maxRuntime = 0;
    private float $startTime = 0;

    public function __construct(
        private QueueInterface $queue,
        private ?ContainerInterface $container = null,
        private int $maxJobs = 0,
        private int $sleep = 1,
        ?string $timezone = null,
        int $memoryLimit = 128,
        int $maxRuntime = 0
    ) {
        // Get timezone from config or use default
        $timezone = $timezone ?? $this->getTimezone();
        $this->logger = new ColoredLogger($timezone);

        // Resolve event dispatcher from container if available
        if ($container && $container->has(EventDispatcherInterface::class)) {
            $this->dispatcher = $container->get(EventDispatcherInterface::class);
        }

        // Set restart thresholds (convert MB to bytes)
        $this->memoryLimit = $memoryLimit * 1024 * 1024;
        $this->maxRuntime = $maxRuntime;
        $this->startTime = microtime(true);
    }

    /**
     * Start processing jobs from the queue(s).
     *
     * Supports both single queue (string) and multiple queues (array) with priority.
     * When multiple queues are provided, processes in priority order (first = highest).
     *
     * Performance: O(Q) per iteration where Q = number of queues
     *
     * @param string|array<string> $queues Queue name(s) - string or array
     * @return void
     */
    public function work(string|array $queues = 'default'): void
    {
        // CRITICAL: Disable ALL output buffering for real-time logs
        // PHP can have multiple output buffer levels, we need to disable ALL of them
        while (ob_get_level()) {
            ob_end_flush();
        }

        // Enable implicit flush - automatically flush after every output
        // This ensures logs appear immediately without manual flush() calls
        ini_set('implicit_flush', '1');
        ob_implicit_flush(true);

        // Normalize to array
        $queueArray = is_array($queues) ? $queues : [$queues];

        $queueNames = implode(',', $queueArray);
        $this->logger->info("Queue worker started. Listening on queue: {$queueNames}");

        // Force flush to ensure startup message is displayed immediately
        if (function_exists('flush')) {
            flush();
        }

        while (!$this->shouldQuit) {
            // Dispatch pending signals before checking for jobs
            // This ensures Ctrl+C is handled even during blocking operations
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // CRITICAL: Check restart conditions BEFORE processing next job
            // This prevents OOM crashes by stopping before a memory-heavy job runs
            if ($this->shouldRestart()) {
                break;
            }

            $job = $this->getNextJob($queueArray);

            // Check shouldQuit again after getNextJob (may have been set during blocking wait)
            if ($this->shouldQuit) {
                break;
            }

            if ($job === null) {
                // No job available on any queue, sleep (don't spam logs)
                // sleep() is now interruptible and will check shouldQuit
                $this->sleep();
                continue;
            }

            $this->processJob($job);
            $this->processed++;

            // Check if we've hit max jobs limit
            if ($this->maxJobs > 0 && $this->processed >= $this->maxJobs) {
                $this->logger->warning("Max jobs limit reached. Stopping worker.");
                break;
            }
        }

        $this->logger->info("Queue worker stopped. Processed {$this->processed} jobs.");
    }

    /**
     * Get next job from queues in priority order.
     *
     * Checks queues in order and returns first available job.
     * This ensures high-priority queues are processed first.
     *
     * Performance: O(Q) where Q = number of queues
     *
     * @param array<string> $queues Queue names in priority order
     * @return JobInterface|null
     */
    private function getNextJob(array $queues): ?JobInterface
    {
        // Try each queue in priority order
        foreach ($queues as $queueName) {
            try {
                $job = $this->queue->pop($queueName);

                if ($job !== null) {
                    return $job; // Found a job, return immediately
                }
            } catch (\Throwable $e) {
                // Log connection errors but don't crash the worker
                // The connection will be reconnected on next attempt
                $this->logger->warning("Error getting job from queue '{$queueName}': {$e->getMessage()}");
                // Continue to next queue
            }
        }

        return null; // No jobs available on any queue
    }

    /**
     * Process a single job with middleware support and backoff retry.
     *
     * Execution flow:
     * 1. Check cancellation
     * 2. Increment attempts
     * 3. Set timeout alarm if configured
     * 4. Execute middleware pipeline
     * 5. Execute job handle() method
     * 6. Clear timeout alarm
     * 7. Record metrics
     * 8. On success: log completion
     * 9. On failure: retry with backoff or mark as failed
     *
     * Performance: O(M + H) where M = middleware count, H = job execution time
     *
     * @param JobInterface $job
     * @return void
     */
    private function processJob(JobInterface $job): void
    {
        // Check if job is cancelled
        if ($this->isJobCancelled($job)) {
            $this->logger->warning("Job cancelled: {$job->getId()}");
            return;
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $success = false;

        try {
            $attemptNumber = $job->attempts() + 1;
            $this->logger->info("Processing job: {$job->getId()} (attempt {$attemptNumber})");

            // Force flush to show log immediately
            if (function_exists('flush')) {
                flush();
            }

            $job->incrementAttempts();

            // Dispatch JobProcessing event
            $this->dispatchEvent(new JobProcessing($job, $attemptNumber));

            // Set timeout alarm if supported and configured
            $timeout = $job->getTimeout();
            if ($timeout > 0 && function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
                $this->registerTimeoutHandler($job);
                pcntl_alarm($timeout);
            }

            // Execute job through middleware pipeline
            $this->executeJobThroughMiddleware($job);

            // Clear timeout alarm
            if ($timeout > 0 && function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            $this->logger->success("Job completed: {$job->getId()}");
            $success = true;

            // Force flush success log
            if (function_exists('flush')) {
                flush();
            }

            // Dispatch JobProcessed event
            $this->dispatchEvent(new JobProcessed($job, $attemptNumber));
        } catch (JobTimeoutException $e) {
            // Clear alarm
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            $this->logger->error("Job timed out: {$job->getId()} - {$e->getMessage()}");

            // Dispatch JobTimedOut event
            $this->dispatchEvent(new JobTimedOut($job, $job->getTimeout(), $job->attempts()));

            // Call timeout callback
            $job->timeout();

            // Retry logic same as other exceptions
            $willRetry = $job->attempts() < $job->getMaxAttempts();
            $this->dispatchEvent(new JobFailed($job, $e, $job->attempts(), $willRetry));

            if ($willRetry) {
                // CRITICAL: Calculate backoff delay AFTER attempts increment
                // At this point, attempts has already been incremented (line 225)
                $delay = $job->getBackoffDelay();
                $nextAttempt = $job->attempts() + 1;
                $maxAttempts = $job->getMaxAttempts();

                $this->dispatchEvent(new JobRetrying($job, $job->attempts(), $delay, $e));

                if ($delay > 0) {
                    // Show clear retry info: "Retry attempt X/Y in Ns"
                    $this->logger->warning(
                        "⏱️  Retrying job: {$job->getId()} in {$delay}s " .
                            "(attempt {$nextAttempt}/{$maxAttempts}, current attempts: {$job->attempts()})"
                    );

                    // Force flush retry message
                    if (function_exists('flush')) {
                        flush();
                    }

                    $this->queue->later($job, $delay, $job->getQueue());
                } else {
                    // Immediate retry (delay = 0)
                    $this->logger->warning(
                        "⚡ Retrying job: {$job->getId()} immediately " .
                            "(attempt {$nextAttempt}/{$maxAttempts}, current attempts: {$job->attempts()})"
                    );

                    // Force flush immediate retry message
                    if (function_exists('flush')) {
                        flush();
                    }

                    $this->queue->push($job, $job->getQueue());
                }
            } else {
                $this->logger->error("❌ Job exceeded max attempts: {$job->getId()} ({$job->attempts()}/{$job->getMaxAttempts()})");

                // Force flush exceeded message
                if (function_exists('flush')) {
                    flush();
                }

                $job->failed($e);

                // Store failed job in both Database and Redis queues
                if ($this->queue instanceof DatabaseQueue || $this->queue instanceof RedisQueue) {
                    $this->queue->storeFailed($job, $e);
                }
            }
        } catch (RateLimitExceededException $e) {
            // Clear alarm before retry
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            // CRITICAL: Rate limit is NOT a job failure, decrement attempts
            // Job should not lose an attempt due to external rate limiting
            $job->decrementAttempts();

            // Rate limit exceeded - release back to queue with delay
            $retryAfter = $e->getRetryAfter();
            $this->logger->warning("🚦 Job rate limited: {$job->getId()}. Retrying in {$retryAfter}s (attempts not counted)");
            $this->dispatchEvent(new JobRetrying($job, $job->attempts(), $retryAfter, $e));
            $this->queue->later($job, $retryAfter, $job->getQueue());
        } catch (JobAlreadyRunningException $e) {
            // Clear alarm before retry
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            // CRITICAL: Job already running is NOT a failure, decrement attempts
            // Job should not lose an attempt due to concurrent execution
            $job->decrementAttempts();

            // Job already running - release back to queue with delay
            $this->logger->warning("🔒 Job already running: {$job->getId()}. Retrying in 60s (attempts not counted)");
            $this->dispatchEvent(new JobRetrying($job, $job->attempts(), 60, $e));
            $this->queue->later($job, 60, $job->getQueue());
        } catch (\Throwable $e) {
            // CRITICAL: Clear alarm first to prevent timeout during retry
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            $this->logger->error("Job failed: {$job->getId()} - {$e->getMessage()}");

            // Force flush error log immediately
            if (function_exists('flush')) {
                flush();
            }

            // Check if we should retry
            $willRetry = $job->attempts() < $job->getMaxAttempts();
            $this->dispatchEvent(new JobFailed($job, $e, $job->attempts(), $willRetry));

            if ($willRetry) {
                // Calculate backoff delay AFTER attempts increment
                $delay = $job->getBackoffDelay();
                $nextAttempt = $job->attempts() + 1;
                $maxAttempts = $job->getMaxAttempts();

                $this->dispatchEvent(new JobRetrying($job, $job->attempts(), $delay, $e));

                if ($delay > 0) {
                    $this->logger->warning(
                        "⏱️  Retrying job: {$job->getId()} in {$delay}s " .
                        "(attempt {$nextAttempt}/{$maxAttempts}, current attempts: {$job->attempts()})"
                    );

                    // Force flush retry message
                    if (function_exists('flush')) {
                        flush();
                    }

                    $this->queue->later($job, $delay, $job->getQueue());
                } else {
                    $this->logger->warning(
                        "⚡ Retrying job: {$job->getId()} immediately " .
                        "(attempt {$nextAttempt}/{$maxAttempts}, current attempts: {$job->attempts()})"
                    );

                    // Force flush immediate retry message
                    if (function_exists('flush')) {
                        flush();
                    }

                    $this->queue->push($job, $job->getQueue());
                }
            } else {
                $this->logger->error("Job exceeded max attempts: {$job->getId()}");

                // Force flush exceeded message
                if (function_exists('flush')) {
                    flush();
                }

                $job->failed($e);

                // Store in failed jobs table if using DatabaseQueue or RedisQueue
                if ($this->queue instanceof DatabaseQueue || $this->queue instanceof RedisQueue) {
                    $this->queue->storeFailed($job, $e);
                }
            }
        } finally {
            // Ensure alarm is always cleared, even if exception occurred
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            // Record metrics
            $this->recordMetrics($job, $success, $startTime, $startMemory);
        }
    }

    /**
     * Check if job is cancelled.
     *
     * Performance: O(1)
     *
     * @param JobInterface $job
     * @return bool
     */
    private function isJobCancelled(JobInterface $job): bool
    {
        if (!$this->container || !$this->container->has('cache')) {
            return false;
        }

        try {
            $cancellation = new JobCancellation(
                $this->container->get('cache')
            );
            return $cancellation->isCancelled($job->getId());
        } catch (\Throwable $e) {
            return false; // Silently ignore errors
        }
    }

    /**
     * Record job execution metrics.
     *
     * Performance: O(1)
     *
     * @param JobInterface $job
     * @param bool $success
     * @param float $startTime
     * @param int $startMemory
     * @return void
     */
    private function recordMetrics(JobInterface $job, bool $success, float $startTime, int $startMemory): void
    {
        if (!$this->container || !$this->container->has('cache')) {
            return;
        }

        try {
            $duration = microtime(true) - $startTime;
            $memory = memory_get_usage(true) - $startMemory;

            // Record job metrics
            $jobMetrics = new JobMetrics(
                $this->container->get('cache')
            );
            $jobMetrics->record(get_class($job), $success, $duration, $memory);

            // Record queue metrics
            $queueMetrics = new QueueMetrics(
                $this->container->get('cache')
            );
            $queueMetrics->record($job->getQueue() ?? 'default', 'process', $duration);
        } catch (\Throwable $e) {
            // Silently ignore metrics errors
        }
    }

    /**
     * Execute job through middleware pipeline.
     *
     * Builds middleware pipeline and executes job with dependency injection.
     *
     * Performance: O(M) where M = number of middleware
     *
     * @param JobInterface $job
     * @return mixed
     */
    private function executeJobThroughMiddleware(JobInterface $job): mixed
    {
        // Get job middleware (if job supports it)
        // Only Job class has middleware() method
        $middleware = [];
        if ($job instanceof Job) {
            $middleware = $job->middleware();
        }

        // Auto-apply EnsureUnique middleware if job has uniqueId
        if ($job instanceof Job && $job->getUniqueId() !== null) {
            if ($this->container && $this->container->has('cache')) {
                $cache = $this->container->get('cache');
                $ensureUnique = new EnsureUnique($cache);
                // Add to beginning of middleware stack
                array_unshift($middleware, $ensureUnique);
            }
        }

        if (empty($middleware)) {
            // No middleware, execute directly
            return $this->executeJob($job);
        }

        // Build middleware pipeline
        $pipeline = array_reduce(
            array_reverse($middleware),
            function ($next, $middleware) {
                return function ($job) use ($middleware, $next) {
                    return $middleware->handle($job, $next);
                };
            },
            function ($job) {
                return $this->executeJob($job);
            }
        );

        // Execute pipeline
        return $pipeline($job);
    }

    /**
     * Execute job handle() method with dependency injection.
     *
     * Final step in middleware pipeline.
     *
     * @param JobInterface $job
     * @return mixed
     */
    private function executeJob(JobInterface $job): mixed
    {
        // Verify job has handle() method
        if (!method_exists($job, 'handle')) {
            throw new \BadMethodCallException(
                sprintf('Job class %s must implement handle() method', get_class($job))
            );
        }

        // Use container to call handle() with dependency injection
        if ($this->container) {
            return $this->container->call([$job, 'handle']);
        }

        /** @var callable $handler */
        $handler = [$job, 'handle'];
        return $handler();
    }

    /**
     * Sleep for the configured duration with signal interruption support.
     *
     * Uses interruptible sleep to allow graceful shutdown during sleep.
     * Signals (Ctrl+C) can interrupt the sleep immediately.
     *
     * Performance: O(1) - Simple sleep loop
     * Clean Architecture: Handles signal dispatch during blocking operations
     *
     * @return void
     */
    private function sleep(): void
    {
        // Use interruptible sleep to allow signal handling
        // Sleep in 1-second chunks and check shouldQuit between chunks
        // This allows Ctrl+C to interrupt sleep immediately
        $remaining = $this->sleep;

        while ($remaining > 0 && !$this->shouldQuit) {
            // Sleep in 1-second chunks to allow signal interruption
            $chunk = min(1, $remaining);
            sleep((int) $chunk);
            $remaining -= $chunk;

            // Dispatch pending signals (important for signal handling)
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Stop the worker gracefully
     *
     * @return void
     */
    public function stop(): void
    {
        $this->logger->warning("Stopping worker...");
        $this->dispatchEvent(new WorkerStopping($this->processed));
        $this->shouldQuit = true;
    }

    /**
     * Dispatch an event if dispatcher is available.
     *
     * Performance: O(N) where N = number of listeners
     * SOLID: Dependency Inversion - depends on EventDispatcherInterface
     *
     * @param object $event Event to dispatch
     * @return void
     */
    private function dispatchEvent(object $event): void
    {
        if ($this->dispatcher) {
            $this->dispatcher->dispatch($event);
        }
    }

    /**
     * Register timeout signal handler for job.
     *
     * Uses SIGALRM to interrupt job execution when timeout is reached.
     *
     * Performance: O(1) - Signal registration
     * SOLID: Single Responsibility - only handles timeout signals
     *
     * @param JobInterface $job Job to monitor
     * @return void
     */
    private function registerTimeoutHandler(JobInterface $job): void
    {
        pcntl_signal(SIGALRM, function () use ($job) {
            throw new JobTimeoutException($job->getId(), $job->getTimeout());
        });
    }

    /**
     * Check if worker should restart based on configured thresholds.
     *
     * Restart conditions:
     * - Memory usage exceeds limit
     * - Runtime exceeds limit
     *
     * Performance: O(1) - Simple checks
     * Clean Architecture: Single responsibility for restart logic
     *
     * @return bool True if worker should restart
     */
    private function shouldRestart(): bool
    {
        // Check memory limit
        if ($this->memoryExceeded()) {
            $memory = round(memory_get_usage(true) / 1024 / 1024, 2);
            $limit = round($this->memoryLimit / 1024 / 1024, 2);
            $this->logger->warning("Memory limit exceeded: {$memory}MB / {$limit}MB. Restarting worker.");
            return true;
        }

        // Check runtime limit
        if ($this->runtimeExceeded()) {
            $runtime = round(microtime(true) - $this->startTime);
            $this->logger->warning("Runtime limit exceeded: {$runtime}s / {$this->maxRuntime}s. Restarting worker.");
            return true;
        }

        return false;
    }

    /**
     * Check if memory usage exceeds configured limit.
     *
     * @return bool
     */
    private function memoryExceeded(): bool
    {
        if ($this->memoryLimit <= 0) {
            return false;
        }

        return memory_get_usage(true) >= $this->memoryLimit;
    }

    /**
     * Check if runtime exceeds configured limit.
     *
     * @return bool
     */
    private function runtimeExceeded(): bool
    {
        if ($this->maxRuntime <= 0) {
            return false;
        }

        return (microtime(true) - $this->startTime) >= $this->maxRuntime;
    }

    /**
     * Get timezone from config or container
     *
     * @return string
     */
    private function getTimezone(): string
    {
        if ($this->container && $this->container->has('config')) {
            $config = $this->container->get('config');
            return $config->get('app.timezone', 'UTC');
        }

        return 'UTC';
    }

    /**
     * Get the number of processed jobs
     *
     * @return int
     */
    public function getProcessedCount(): int
    {
        return $this->processed;
    }

    /**
     * Get the queue instance
     *
     * @return QueueInterface
     */
    public function getQueue(): QueueInterface
    {
        return $this->queue;
    }
}
