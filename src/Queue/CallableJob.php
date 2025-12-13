<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue;

/**
 * Class CallableJob
 *
 * Wraps any object with a handle() method into a JobInterface.
 * Enables dispatching plain objects without extending Job base class.
 *
 * Design Pattern: Adapter Pattern
 * - Adapts plain objects to JobInterface
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
final class CallableJob extends Job
{
    public function __construct(
        private readonly object $callable
    ) {
        parent::__construct();
    }

    /**
     * Execute the wrapped callable.
     *
     * Note: Dependencies should be injected via container
     * when Worker executes this job.
     *
     * @return void
     */
    public function handle(): void
    {
        // Delegate to wrapped object
        // Container will inject dependencies when Worker calls this
        $this->callable->handle();
    }

    /**
     * Get the wrapped callable.
     *
     * @return object
     */
    public function getCallable(): object
    {
        return $this->callable;
    }
}
