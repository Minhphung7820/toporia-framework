<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus;

use Toporia\Framework\Bus\Contracts\BatchRepositoryInterface;
use Toporia\Framework\Bus\Contracts\DispatcherInterface;

/**
 * Bus Facade
 *
 * Provides static access to dispatcher and batch operations.
 *
 * Performance:
 * - O(1) singleton access
 * - Lazy initialization
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Bus
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Bus
{
    private static ?DispatcherInterface $dispatcher = null;
    private static ?BatchRepositoryInterface $batchRepository = null;

    /**
     * Set the dispatcher instance.
     */
    public static function setDispatcher(DispatcherInterface $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    /**
     * Set the batch repository instance.
     */
    public static function setBatchRepository(BatchRepositoryInterface $repository): void
    {
        self::$batchRepository = $repository;
    }

    /**
     * Dispatch a command/query/job.
     *
     * @template T
     * @param T $command Command instance
     * @return mixed
     */
    public static function dispatch(mixed $command): mixed
    {
        return self::getDispatcher()->dispatch($command);
    }

    /**
     * Dispatch a command synchronously.
     *
     * @template T
     * @param T $command Command instance
     * @return mixed
     */
    public static function dispatchSync(mixed $command): mixed
    {
        return self::getDispatcher()->dispatchSync($command);
    }

    /**
     * Dispatch a command after response.
     *
     * @param mixed $command Command instance
     * @return PendingDispatch
     */
    public static function dispatchAfterResponse(mixed $command): PendingDispatch
    {
        $pending = new PendingDispatch(self::getDispatcher(), $command);
        return $pending->afterResponse();
    }

    /**
     * Create a pending dispatch with fluent API.
     *
     * Unlike dispatch() which executes immediately, this returns
     * a PendingDispatch for configuration before execution.
     *
     * Usage:
     * ```php
     * Bus::pending(new SendEmailCommand())
     *     ->onQueue('emails')
     *     ->delay(60)
     *     ->dispatch();
     * ```
     *
     * @template T
     * @param T $command Command instance
     * @return PendingDispatch<T>
     */
    public static function pending(mixed $command): PendingDispatch
    {
        return new PendingDispatch(self::getDispatcher(), $command);
    }

    /**
     * @deprecated Use pending() instead
     * @see Bus::pending()
     */
    public static function dispatch2(mixed $command): PendingDispatch
    {
        return self::pending($command);
    }

    /**
     * Create a new batch.
     *
     * @param array<mixed> $jobs Jobs array
     * @return PendingBatch
     */
    public static function batch(array $jobs): PendingBatch
    {
        return new PendingBatch(
            self::getBatchRepository(),
            self::getDispatcher(),
            $jobs
        );
    }

    /**
     * Create a new chain (sequential job execution).
     *
     * Performance:
     * - O(1) creation (lazy execution)
     * - Jobs executed sequentially when dispatch() is called
     * - Early termination on failure
     *
     * @template T
     * @param array<mixed> $jobs Jobs to chain (executed sequentially)
     * @return PendingChain<T>
     */
    public static function chain(array $jobs): PendingChain
    {
        return new PendingChain(self::getDispatcher(), $jobs);
    }

    /**
     * Find a batch by ID.
     */
    public static function findBatch(string $batchId): ?Batch
    {
        return self::getBatchRepository()->find($batchId);
    }

    /**
     * Map commands to handlers.
     *
     * @param array<string, string> $map Command => Handler mapping
     */
    public static function map(array $map): void
    {
        self::getDispatcher()->map($map);
    }

    /**
     * Add middleware to pipeline.
     *
     * @param array<callable|string> $middleware Middleware array
     */
    public static function pipeThrough(array $middleware): void
    {
        self::getDispatcher()->pipeThrough($middleware);
    }

    /**
     * Get the dispatcher instance.
     */
    private static function getDispatcher(): DispatcherInterface
    {
        if (!self::$dispatcher) {
            throw new \RuntimeException('Dispatcher not set. Call Bus::setDispatcher() first.');
        }

        return self::$dispatcher;
    }

    /**
     * Get the batch repository instance.
     */
    private static function getBatchRepository(): BatchRepositoryInterface
    {
        if (!self::$batchRepository) {
            throw new \RuntimeException('Batch repository not set. Call Bus::setBatchRepository() first.');
        }

        return self::$batchRepository;
    }
}
