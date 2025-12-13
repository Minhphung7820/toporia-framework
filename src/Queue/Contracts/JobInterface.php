<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Contracts;


/**
 * Interface JobInterface
 *
 * Contract defining the interface for JobInterface implementations in the
 * Asynchronous job processing layer of the Toporia Framework.
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
interface JobInterface
{
    // Note: handle() method signature varies per implementation for DI support

    /**
     * Get the job identifier
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Get the queue name
     *
     * @return string
     */
    public function getQueue(): string;

    /**
     * Get the number of times the job has been attempted
     *
     * @return int
     */
    public function attempts(): int;

    /**
     * Get the maximum number of attempts
     *
     * @return int
     */
    public function getMaxAttempts(): int;

    /**
     * Increment the attempt counter
     *
     * @return void
     */
    public function incrementAttempts(): void;

    /**
     * Decrement the attempt counter
     *
     * Used when job needs to be retried without counting as a failed attempt
     * (e.g., RateLimitException, JobAlreadyRunning)
     *
     * @return void
     */
    public function decrementAttempts(): void;

    /**
     * Handle a job failure
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void;

    /**
     * Get the job execution timeout in seconds.
     *
     * @return int Timeout in seconds (0 = no timeout)
     */
    public function getTimeout(): int;

    /**
     * Handle job timeout.
     * Called when job execution exceeds the timeout limit.
     *
     * @return void
     */
    public function timeout(): void;

    /**
     * Get the backoff delay for retry in seconds.
     *
     * @return int Delay in seconds before next retry
     */
    public function getBackoffDelay(): int;
}
