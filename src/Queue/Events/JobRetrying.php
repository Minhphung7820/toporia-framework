<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Events;

use Toporia\Framework\Events\Event;
use Toporia\Framework\Queue\Contracts\JobInterface;

/**
 * Job Retrying Event
 *
 * Fired when a failed job is being retried.
 *
 * Use cases:
 * - Track retry attempts
 * - Monitor retry patterns
 * - Adjust backoff strategies
 * - Alert on excessive retries
 *
 * Performance: O(1) - Simple event creation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 */
final class JobRetrying extends Event
{
    public function __construct(
        private readonly JobInterface $job,
        private readonly int $attempt,
        private readonly int $delay,
        private readonly ?\Throwable $exception = null
    ) {}

    public function getJob(): JobInterface
    {
        return $this->job;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }
}
