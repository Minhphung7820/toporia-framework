<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Contracts;


/**
 * Interface Dispatcher
 *
 * Command/Query/Job dispatcher with support for synchronous/asynchronous
 * execution, middleware pipeline, batch operations, and job chaining.
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
interface Dispatcher
{
    /**
     * Dispatch a job to its designated queue.
     *
     * @param object $job Job instance
     * @return mixed Job ID or result
     */
    public function dispatch(object $job): mixed;

    /**
     * Dispatch a job to a specific queue.
     *
     * @param object $job Job instance
     * @param string|null $queue Queue name
     * @return mixed Job ID or result
     */
    public function dispatchToQueue(object $job, ?string $queue = null): mixed;

    /**
     * Dispatch a job after a delay.
     *
     * @param object $job Job instance
     * @param int $delay Delay in seconds
     * @param string|null $queue Queue name
     * @return mixed Job ID
     */
    public function dispatchAfter(object $job, int $delay, ?string $queue = null): mixed;

    /**
     * Dispatch a job immediately (sync).
     *
     * @param object $job Job instance
     * @return mixed Job result
     */
    public function dispatchSync(object $job): mixed;
}
