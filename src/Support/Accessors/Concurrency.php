<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Concurrency\Concurrency as ConcurrencyFacade;
use Toporia\Framework\Concurrency\Contracts\ConcurrencyDriverInterface;

/**
 * Concurrency Accessor
 *
 * Static facade for concurrent task execution.
 * Toporia-style API for running tasks in parallel.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 *
 * @method static array run(array $tasks) Run tasks concurrently
 * @method static void defer(array $tasks) Defer tasks to run after response
 * @method static ConcurrencyDriverInterface driver(string $name) Get specific driver
 * @method static void setDefaultDriver(string $driver) Set default driver
 * @method static void setMaxConcurrent(int $max) Set max concurrent tasks
 * @method static void setTimeout(float $timeout) Set default timeout
 * @method static bool isForksSupported() Check if fork is supported
 * @method static array getAvailableDrivers() Get available drivers
 *
 * @example
 * // Run tasks in parallel with named results
 * $results = Concurrency::run([
 *     'users' => fn() => User::all(),
 *     'posts' => fn() => Post::recent(),
 *     'stats' => fn() => Stats::calculate(),
 * ]);
 * // Access: $results['users'], $results['posts'], $results['stats']
 *
 * // Defer tasks to run after response sent
 * Concurrency::defer([fn() => sendWelcomeEmail($user)]);
 *
 * // Use specific driver
 * $results = Concurrency::driver('fork')->run([...]);
 */
final class Concurrency
{
    /**
     * Forward all static calls to ConcurrencyFacade.
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return ConcurrencyFacade::$method(...$arguments);
    }
}
