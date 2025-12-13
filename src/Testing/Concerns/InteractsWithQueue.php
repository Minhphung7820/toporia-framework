<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Concerns;


/**
 * Trait InteractsWithQueue
 *
 * Queue manager supporting multiple drivers (Database, Redis, Sync) for
 * asynchronous job processing with delayed execution, job retries, and
 * failure tracking.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait InteractsWithQueue
{
    /**
     * Fake queue (disable real queueing).
     */
    protected bool $fakeQueue = false;

    /**
     * Pushed jobs.
     *
     * @var array
     */
    protected array $pushedJobs = [];

    /**
     * Fake queue.
     *
     * Performance: O(1)
     */
    protected function fakeQueue(): void
    {
        $this->fakeQueue = true;
        $this->pushedJobs = [];
    }

    /**
     * Assert that a job was pushed.
     *
     * Performance: O(N) where N = number of jobs
     */
    protected function assertJobPushed(string $jobClass, array $data = null): void
    {
        $found = false;

        foreach ($this->pushedJobs as $job) {
            if ($job['class'] === $jobClass) {
                if ($data === null || $job['data'] === $data) {
                    $found = true;
                    break;
                }
            }
        }

        $this->assertTrue($found, "Job {$jobClass} was not pushed");
    }

    /**
     * Assert that a job was not pushed.
     *
     * Performance: O(N) where N = number of jobs
     */
    protected function assertJobNotPushed(string $jobClass): void
    {
        $found = false;

        foreach ($this->pushedJobs as $job) {
            if ($job['class'] === $jobClass) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, "Job {$jobClass} was unexpectedly pushed");
    }

    /**
     * Record a pushed job.
     *
     * Performance: O(1)
     */
    protected function recordJob(string $jobClass, array $data = []): void
    {
        $this->pushedJobs[] = [
            'class' => $jobClass,
            'data' => $data,
        ];
    }

    /**
     * Cleanup queue after test.
     */
    protected function tearDownQueue(): void
    {
        $this->pushedJobs = [];
        $this->fakeQueue = false;
    }
}
