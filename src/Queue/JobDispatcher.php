<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue;

use Toporia\Framework\Queue\Contracts\{Dispatcher, JobInterface, QueueInterface};
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class JobDispatcher
 *
 * Dispatches jobs to queues with dependency injection support.
 *
 * Architecture:
 * - Uses Container for auto-resolving job dependencies
 * - Supports multiple queue drivers (sync, database, redis)
 * - Clean separation between dispatch and execution
 *
 * Performance Optimizations:
 * - Lazy queue resolution (only when needed)
 * - Reuses queue instances via container
 * - Minimal object creation overhead
 *
 * SOLID Principles:
 * - Single Responsibility: Only dispatches jobs
 * - Dependency Inversion: Depends on ContainerInterface
 * - Open/Closed: Extend via custom queue drivers
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
final class JobDispatcher implements Dispatcher
{
    private ?QueueInterface $queue = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?string $defaultQueue = 'default'
    ) {}

    /**
     * Dispatch a job to its designated queue.
     *
     * @param object $job Job instance
     * @return mixed Job ID or result
     */
    public function dispatch(object $job): mixed
    {
        return $this->dispatchToQueue($job);
    }

    /**
     * Dispatch a job to a specific queue.
     *
     * @param object $job Job instance
     * @param string|null $queue Queue name
     * @return mixed Job ID or result
     */
    public function dispatchToQueue(object $job, ?string $queue = null): mixed
    {
        // Convert to JobInterface if needed
        if (!$job instanceof JobInterface) {
            $job = $this->wrapJob($job);
        }

        $queueName = $queue ?? $this->defaultQueue ?? 'default';

        return $this->getQueue()->push($job, $queueName);
    }

    /**
     * Dispatch a job after a delay.
     *
     * @param object $job Job instance
     * @param int $delay Delay in seconds
     * @param string|null $queue Queue name
     * @return mixed Job ID
     */
    public function dispatchAfter(object $job, int $delay, ?string $queue = null): mixed
    {
        // Convert to JobInterface if needed
        if (!$job instanceof JobInterface) {
            $job = $this->wrapJob($job);
        }

        $queueName = $queue ?? $this->defaultQueue ?? 'default';

        return $this->getQueue()->later($job, $delay, $queueName);
    }

    /**
     * Dispatch a job immediately (sync).
     *
     * @param object $job Job instance
     * @return mixed Job result
     */
    public function dispatchSync(object $job): mixed
    {
        // Execute immediately using container for DI
        return $this->container->call([$job, 'handle']);
    }

    /**
     * Get or create queue instance.
     *
     * Lazy loading for performance.
     *
     * @return QueueInterface
     */
    private function getQueue(): QueueInterface
    {
        if ($this->queue === null) {
            $this->queue = $this->container->get('queue');
        }

        return $this->queue;
    }

    /**
     * Wrap a plain object into a CallableJob.
     *
     * Allows dispatching any object with a handle() method.
     *
     * @param object $job
     * @return JobInterface
     */
    private function wrapJob(object $job): JobInterface
    {
        return new CallableJob($job);
    }
}
