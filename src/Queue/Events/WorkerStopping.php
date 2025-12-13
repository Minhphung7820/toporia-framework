<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Events;

use Toporia\Framework\Events\Event;

/**
 * Worker Stopping Event
 *
 * Fired when worker begins graceful shutdown.
 *
 * Use cases:
 * - Cleanup before shutdown
 * - Send shutdown notifications
 * - Record worker uptime
 * - Flush metrics
 *
 * Performance: O(1) - Simple event creation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 */
final class WorkerStopping extends Event
{
    public function __construct(
        private readonly int $processedJobs
    ) {}

    public function getProcessedJobs(): int
    {
        return $this->processedJobs;
    }
}
