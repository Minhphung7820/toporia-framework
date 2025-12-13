<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Concurrency\Concurrency;
use Toporia\Framework\Concurrency\Contracts\ConcurrencyDriverInterface;
use Toporia\Framework\Concurrency\Process\ProcessFactory;
use Toporia\Framework\Concurrency\Process\ProcessPool;

/**
 * Process Accessor
 *
 * Static facade for process pool and concurrent execution.
 * Delegates to the new Concurrency subsystem.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 *
 * @method static array run(array $tasks) Run tasks concurrently
 * @method static void defer(array $tasks) Defer tasks to run after response
 * @method static ConcurrencyDriverInterface driver(string $name) Get specific driver
 * @method static void setMaxConcurrent(int $max) Set max concurrent tasks
 * @method static void setTimeout(float $timeout) Set timeout
 * @method static bool isForksSupported() Check if fork is supported
 * @method static array getAvailableDrivers() Get available drivers
 *
 * @see Concurrency
 *
 * @example
 * // Run tasks in parallel
 * $results = Process::run([
 *     fn() => heavyTask1(),
 *     fn() => heavyTask2(),
 * ]);
 *
 * // Use fork driver
 * $results = Process::driver('fork')->run([
 *     fn() => task1(),
 *     fn() => task2(),
 * ]);
 */
final class Process
{
    /**
     * Forward all static calls to Concurrency facade.
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return Concurrency::$method(...$arguments);
    }

    /**
     * Get a process pool instance (for advanced usage).
     */
    public static function pool(): ProcessPool
    {
        return (new ProcessFactory())->createPool();
    }
}
