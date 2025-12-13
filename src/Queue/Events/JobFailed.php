<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Events;

use Toporia\Framework\Events\Event;
use Toporia\Framework\Queue\Contracts\JobInterface;

/**
 * Job Failed Event
 *
 * Fired when a job fails (exception thrown).
 *
 * Use cases:
 * - Error tracking and alerting
 * - Failure metrics
 * - Debugging and logging
 * - Notification to administrators
 *
 * Performance: O(1) - Simple event creation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 */
final class JobFailed extends Event
{
    public function __construct(
        private readonly JobInterface $job,
        private readonly \Throwable $exception,
        private readonly int $attempt,
        private readonly bool $willRetry
    ) {}

    public function getJob(): JobInterface
    {
        return $this->job;
    }

    public function getException(): \Throwable
    {
        return $this->exception;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function willRetry(): bool
    {
        return $this->willRetry;
    }
}
