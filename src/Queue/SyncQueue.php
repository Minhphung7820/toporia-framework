<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Queue\Contracts\{JobInterface, QueueInterface};

/**
 * Class SyncQueue
 *
 * Synchronous Queue Driver - Executes jobs immediately without queueing.
 * Useful for testing and development.
 *
 * Performance Optimizations:
 * - Uses container for dependency injection (no manual wiring)
 * - Zero storage overhead (executes immediately)
 * - Minimal memory footprint
 *
 * SOLID Principles:
 * - Single Responsibility: Only executes jobs synchronously
 * - Dependency Inversion: Depends on ContainerInterface
 * - Open/Closed: Extend via custom job types
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
final class SyncQueue implements QueueInterface
{
    public function __construct(
        private readonly ContainerInterface $container
    ) {}

    public function push(JobInterface $job, string $queue = 'default'): string
    {
        $this->executeJob($job);
        return $job->getId();
    }

    public function later(JobInterface $job, int $delay, string $queue = 'default'): string
    {
        // In sync mode, ignore delay and execute immediately
        return $this->push($job, $queue);
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        // Sync queue doesn't store jobs
        return null;
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }

    public function clear(string $queue = 'default'): void
    {
        // Nothing to clear
    }

    /**
     * Execute a job with dependency injection.
     *
     * Performance: O(1) for job execution + O(D) for DI resolution
     * where D = number of dependencies.
     *
     * @param JobInterface $job
     * @return void
     */
    private function executeJob(JobInterface $job): void
    {
        try {
            // Use container to call handle() with auto-injected dependencies
            // This resolves MailerInterface and other dependencies automatically
            $this->container->call([$job, 'handle']);
        } catch (\Throwable $e) {
            $job->failed($e);
            throw $e;
        }
    }
}
