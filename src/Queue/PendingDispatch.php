<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue;

use Toporia\Framework\Queue\Contracts\Dispatcher;
use Toporia\Framework\Queue\Job;

/**
 * Class PendingDispatch
 *
 * Provides fluent API for configuring job dispatch.
 *
 * Usage:
 * ```php
 * dispatch(new SendEmailJob($to, $subject, $body))
 *     ->onQueue('emails')
 *     ->delay(60)
 *     ->priority(10)
 *     ->tag(['email', 'urgent']);
 * ```
 *
 * SOLID Principles:
 * - Single Responsibility: Only configures and dispatches jobs
 * - Open/Closed: Extend without modifying
 * - Dependency Inversion: Depends on Dispatcher interface
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
final class PendingDispatch
{
    private ?string $queue = null;
    private ?int $delay = null;
    private bool $dispatched = false;
    private array $tags = [];

    public function __construct(
        private readonly object $job,
        private readonly Dispatcher $dispatcher
    ) {}

    /**
     * Set the queue for the job.
     *
     * @param string $queue Queue name
     * @return $this
     */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        // Also update the job's queue property for consistency
        if ($this->job instanceof Job) {
            $this->job->onQueue($queue);
        }

        return $this;
    }

    /**
     * Set delay for the job.
     *
     * @param int $seconds Delay in seconds
     * @return $this
     */
    public function delay(int $seconds): self
    {
        $this->delay = $seconds;

        // CRITICAL: Also update the job's delay property
        // This ensures the delay is preserved when the job is serialized
        if ($this->job instanceof Job) {
            $this->job->delay($seconds);
        }

        return $this;
    }

    /**
     * Set job priority.
     *
     * @param int $priority
     * @return $this
     */
    public function priority(int $priority): self
    {
        // Apply priority directly to job (no need to store in PendingDispatch)
        if ($this->job instanceof Job) {
            $this->job->priority($priority);
        }
        return $this;
    }

    /**
     * Add tags to the job.
     *
     * @param string|array<string> $tags
     * @return $this
     */
    public function tag(string|array $tags): self
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $this->tags = array_merge($this->tags, $tags);
        if ($this->job instanceof Job) {
            $this->job->tag($tags);
        }
        return $this;
    }

    /**
     * Make job unique.
     *
     * @param string $uniqueId
     * @param int $for
     * @return $this
     */
    public function unique(string $uniqueId, int $for = 3600): self
    {
        if ($this->job instanceof Job) {
            $this->job->unique($uniqueId, $for);
        }
        return $this;
    }

    /**
     * Dispatch the job immediately (sync).
     *
     * @return mixed
     */
    public function dispatchSync(): mixed
    {
        return $this->dispatcher->dispatchSync($this->job);
    }

    /**
     * Explicitly dispatch the job now.
     *
     * Call this to dispatch immediately instead of waiting for destructor.
     * Prevents duplicate dispatch on destruct.
     *
     * @return mixed Job ID
     */
    public function dispatch(): mixed
    {
        if ($this->dispatched) {
            return null; // Already dispatched
        }

        $this->dispatched = true;

        if ($this->delay !== null) {
            return $this->dispatcher->dispatchAfter($this->job, $this->delay, $this->queue);
        }

        return $this->dispatcher->dispatchToQueue($this->job, $this->queue);
    }

    /**
     * Destructor - automatically dispatches job when object is destroyed.
     *
     * Implicit dispatch:
     * ```php
     * dispatch(new SendEmailJob(...));  // Auto-dispatches, no ->dispatch() needed!
     * ```
     *
     * Fluent API still works:
     * ```php
     * dispatch(new Job())->onQueue('emails')->delay(60);  // Auto-dispatches with config
     * ```
     *
     * Performance: O(1) - destructor is called when PendingDispatch goes out of scope.
     */
    public function __destruct()
    {
        // Prevent double dispatch (if ->dispatch() was called explicitly)
        if ($this->dispatched) {
            return;
        }

        // Mark as dispatched to prevent duplicate dispatch
        $this->dispatched = true;

        // Dispatch with configured options
        if ($this->delay !== null) {
            $this->dispatcher->dispatchAfter($this->job, $this->delay, $this->queue);
        } else {
            $this->dispatcher->dispatchToQueue($this->job, $this->queue);
        }
    }
}
