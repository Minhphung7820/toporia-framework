<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus\Contracts;


/**
 * Interface DispatcherInterface
 *
 * Contract defining the interface for DispatcherInterface implementations
 * in the Command/Query/Job dispatching layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Bus\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface DispatcherInterface
{
    /**
     * Dispatch a command/query/job to its handler.
     *
     * @param mixed $command Command/Query/Job instance
     * @return mixed Handler result
     */
    public function dispatch(mixed $command): mixed;

    /**
     * Dispatch a command/query/job synchronously (immediately).
     *
     * @param mixed $command Command/Query/Job instance
     * @return mixed Handler result
     */
    public function dispatchSync(mixed $command): mixed;

    /**
     * Dispatch a command after the current process.
     *
     * @param mixed $command Command/Query/Job instance
     * @return void
     */
    public function dispatchAfterResponse(mixed $command): void;

    /**
     * Map commands to handlers.
     *
     * @param array<string, string> $map Command => Handler mapping
     * @return void
     */
    public function map(array $map): void;

    /**
     * Add middleware to the pipeline.
     *
     * @param array<callable|string> $middleware Middleware classes/closures
     * @return self
     */
    public function pipeThrough(array $middleware): self;

    /**
     * Check if a handler exists for the command.
     *
     * @param mixed $command Command/Query/Job instance
     * @return bool
     */
    public function hasHandler(mixed $command): bool;

    /**
     * Get the handler for a command.
     *
     * @param mixed $command Command/Query/Job instance
     * @return callable Handler
     */
    public function getHandler(mixed $command): callable;
}
