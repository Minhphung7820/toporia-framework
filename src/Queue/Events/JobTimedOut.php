<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Events;

use Toporia\Framework\Events\Event;
use Toporia\Framework\Queue\Contracts\JobInterface;

/**
 * Job Timed Out Event
 *
 * Fired when a job exceeds its execution timeout.
 *
 * Use cases:
 * - Track timeout occurrences
 * - Alert on slow jobs
 * - Performance analysis
 * - Optimize timeout values
 *
 * Performance: O(1) - Simple event creation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 */
final class JobTimedOut extends Event
{
    public function __construct(
        private readonly JobInterface $job,
        private readonly int $timeout,
        private readonly int $attempt
    ) {}

    public function getJob(): JobInterface
    {
        return $this->job;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }
}
