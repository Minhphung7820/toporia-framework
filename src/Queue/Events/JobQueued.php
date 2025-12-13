<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Events;

use Toporia\Framework\Events\Event;
use Toporia\Framework\Queue\Contracts\JobInterface;

/**
 * Job Queued Event
 *
 * Fired when a job is pushed to the queue.
 *
 * Use cases:
 * - Logging queued jobs
 * - Metrics tracking
 * - Monitoring queue size
 *
 * Performance: O(1) - Simple event creation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 */
final class JobQueued extends Event
{
    public function __construct(
        private readonly JobInterface $job,
        private readonly string $queue,
        private readonly int $delay = 0
    ) {}

    public function getJob(): JobInterface
    {
        return $this->job;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }
}
