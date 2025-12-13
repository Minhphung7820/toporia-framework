<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Events;

use Toporia\Framework\Events\Event;
use Toporia\Framework\Queue\Contracts\JobInterface;

/**
 * Job Processed Event
 *
 * Fired when a job completes successfully.
 *
 * Use cases:
 * - Calculate job execution time
 * - Success metrics tracking
 * - Post-processing actions
 * - Trigger dependent jobs
 *
 * Performance: O(1) - Simple event creation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 */
final class JobProcessed extends Event
{
    public function __construct(
        private readonly JobInterface $job,
        private readonly int $attempt
    ) {}

    public function getJob(): JobInterface
    {
        return $this->job;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }
}
