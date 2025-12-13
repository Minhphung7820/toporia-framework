<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Contracts;

/**
 * Concurrency Driver Interface
 *
 * Defines the contract for all concurrency drivers.
 * Each driver implements a different strategy for parallel task execution.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
interface ConcurrencyDriverInterface
{
    /**
     * Run the given tasks concurrently and return the results.
     *
     * Results are returned in the same key order as the input array.
     * If a task throws an exception, it should be wrapped in a ConcurrencyException.
     *
     * @param array<int|string, callable> $tasks Array of callables to execute
     * @return array<int|string, mixed> Results keyed by original task keys
     *
     * @throws \Toporia\Framework\Concurrency\Exceptions\ConcurrencyException
     */
    public function run(array $tasks): array;

    /**
     * Schedule tasks to run after the main execution flow.
     *
     * Useful for fire-and-forget operations like metrics reporting,
     * cleanup tasks, or non-critical background work.
     *
     * @param array<int|string, callable> $tasks Array of callables to defer
     * @return void
     */
    public function defer(array $tasks): void;

    /**
     * Check if this driver is supported in the current environment.
     *
     * @return bool
     */
    public function isSupported(): bool;

    /**
     * Get the driver name.
     *
     * @return string
     */
    public function getName(): string;
}
