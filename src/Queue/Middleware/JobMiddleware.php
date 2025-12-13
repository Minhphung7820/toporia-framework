<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Middleware;

use Toporia\Framework\Queue\Contracts\JobInterface;


/**
 * Interface JobMiddleware
 *
 * Base middleware class for processing HTTP requests in a pipeline pattern
 * with before/after hooks and request/response modification.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface JobMiddleware
{
    /**
     * Handle job execution through middleware.
     *
     * Middleware can:
     * - Execute logic before job runs
     * - Decide whether to run job or skip
     * - Execute logic after job runs
     * - Catch/handle exceptions
     *
     * @param JobInterface $job The job being processed
     * @param callable $next Callback to execute next middleware/job
     * @return mixed Result from job execution
     *
     * @example
     * // Simple logging middleware
     * public function handle(JobInterface $job, callable $next): mixed
     * {
     *     $start = microtime(true);
     *
     *     $result = $next($job); // Continue to next middleware/job
     *
     *     $duration = microtime(true) - $start;
     *     Log::info("Job {$job->getId()} took {$duration}s");
     *
     *     return $result;
     * }
     *
     * @example
     * // Skip job execution based on condition
     * public function handle(JobInterface $job, callable $next): mixed
     * {
     *     if ($this->shouldSkip($job)) {
     *         return null; // Don't call $next, skip job
     *     }
     *
     *     return $next($job);
     * }
     */
    public function handle(JobInterface $job, callable $next): mixed;
}
