<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Contracts;


/**
 * Interface QueueInterface
 *
 * Contract defining the interface for QueueInterface implementations in
 * the Asynchronous job processing layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Queue\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface QueueInterface
{
    /**
     * Push a job onto the queue
     *
     * @param JobInterface $job
     * @param string $queue Queue name
     * @return string Job ID
     */
    public function push(JobInterface $job, string $queue = 'default'): string;

    /**
     * Push a job onto the queue with a delay
     *
     * @param JobInterface $job
     * @param int $delay Delay in seconds
     * @param string $queue Queue name
     * @return string Job ID
     */
    public function later(JobInterface $job, int $delay, string $queue = 'default'): string;

    /**
     * Pop the next job off the queue
     *
     * @param string $queue Queue name
     * @return JobInterface|null
     */
    public function pop(string $queue = 'default'): ?JobInterface;

    /**
     * Get the size of a queue
     *
     * @param string $queue Queue name
     * @return int
     */
    public function size(string $queue = 'default'): int;

    /**
     * Clear all jobs from a queue
     *
     * @param string $queue Queue name
     * @return void
     */
    public function clear(string $queue = 'default'): void;
}
