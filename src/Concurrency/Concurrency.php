<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency;

use Toporia\Framework\Concurrency\Contracts\ConcurrencyDriverInterface;
use Toporia\Framework\Concurrency\Drivers\ForkConcurrencyDriver;
use Toporia\Framework\Concurrency\Drivers\ProcessConcurrencyDriver;
use Toporia\Framework\Concurrency\Drivers\SyncConcurrencyDriver;
use Toporia\Framework\Concurrency\Process\ProcessFactory;
use Toporia\Framework\Concurrency\Serialization\SerializableClosureSerializer;

/**
 * Concurrency Static Facade
 *
 * Provides a static interface to the Concurrency subsystem.
 * Can be used without service container for simple use cases.
 *
 * Usage:
 * ```php
 * // Run tasks in parallel
 * [$users, $orders] = Concurrency::run([
 *     fn() => $userService->getAll(),
 *     fn() => $orderService->getRecent(),
 * ]);
 *
 * // Using specific driver
 * $results = Concurrency::driver('fork')->run([...]);
 *
 * // Defer tasks
 * Concurrency::defer([fn() => $logger->log('Done!')]);
 * ```
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class Concurrency
{
    /**
     * Singleton manager instance.
     */
    private static ?ConcurrencyManager $manager = null;

    /**
     * Default driver name.
     */
    private static string $defaultDriver = 'process';

    /**
     * Maximum concurrent tasks.
     */
    private static int $maxConcurrent = 4;

    /**
     * Default timeout.
     */
    private static float $timeout = 60.0;

    /**
     * Run tasks concurrently using the default driver.
     *
     * @param array<int|string, callable> $tasks Tasks to execute
     * @return array<int|string, mixed> Results keyed by task keys
     */
    public static function run(array $tasks): array
    {
        return self::getManager()->run($tasks);
    }

    /**
     * Defer tasks to run after main execution flow.
     *
     * @param array<int|string, callable> $tasks Tasks to defer
     */
    public static function defer(array $tasks): void
    {
        self::getManager()->defer($tasks);
    }

    /**
     * Get a specific driver.
     *
     * @param string $name Driver name (process, fork, sync)
     * @return ConcurrencyDriverInterface
     */
    public static function driver(string $name): ConcurrencyDriverInterface
    {
        return self::getManager()->driver($name);
    }

    /**
     * Set the default driver.
     */
    public static function setDefaultDriver(string $driver): void
    {
        self::$defaultDriver = $driver;
        self::$manager = null; // Reset to rebuild with new default
    }

    /**
     * Get the default driver name.
     */
    public static function getDefaultDriver(): string
    {
        return self::$defaultDriver;
    }

    /**
     * Set maximum concurrent tasks.
     */
    public static function setMaxConcurrent(int $max): void
    {
        self::$maxConcurrent = max(1, $max);
        self::$manager = null;
    }

    /**
     * Get maximum concurrent tasks.
     */
    public static function getMaxConcurrent(): int
    {
        return self::$maxConcurrent;
    }

    /**
     * Set default timeout.
     */
    public static function setTimeout(float $timeout): void
    {
        self::$timeout = max(0, $timeout);
        self::$manager = null;
    }

    /**
     * Get default timeout.
     */
    public static function getTimeout(): float
    {
        return self::$timeout;
    }

    /**
     * Check if fork driver is supported.
     */
    public static function isForksSupported(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_kill');
    }

    /**
     * Get available drivers and their support status.
     *
     * @return array<string, bool>
     */
    public static function getAvailableDrivers(): array
    {
        return self::getManager()->getDriverSupport();
    }

    /**
     * Get or create the manager instance.
     */
    private static function getManager(): ConcurrencyManager
    {
        if (self::$manager === null) {
            self::$manager = self::createManager();
        }

        return self::$manager;
    }

    /**
     * Create a new manager instance.
     */
    private static function createManager(): ConcurrencyManager
    {
        $serializer = new SerializableClosureSerializer();
        $processFactory = new ProcessFactory();
        $processFactory->defaultTimeout(self::$timeout);
        $processFactory->defaultConcurrent(self::$maxConcurrent);

        // Get working directory
        $workingDirectory = null;
        if (function_exists('base_path')) {
            $workingDirectory = base_path();
        } elseif (defined('\BASE_PATH')) {
            $workingDirectory = \constant('\BASE_PATH');
        } else {
            $workingDirectory = getcwd() ?: null;
        }

        $drivers = [
            'process' => new ProcessConcurrencyDriver(
                $processFactory,
                $serializer,
                'php',
                'console',
                $workingDirectory,
                self::$timeout
            ),
            'fork' => new ForkConcurrencyDriver(
                self::$maxConcurrent,
                self::$timeout
            ),
            'sync' => new SyncConcurrencyDriver(self::$timeout),
        ];

        // Auto-select best available driver
        $defaultDriver = self::$defaultDriver;
        if ($defaultDriver === 'fork' && !self::isForksSupported()) {
            $defaultDriver = 'process';
        }

        return new ConcurrencyManager($drivers, $defaultDriver);
    }

    /**
     * Reset the manager (for testing).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$manager = null;
        self::$defaultDriver = 'process';
        self::$maxConcurrent = 4;
        self::$timeout = 60.0;
    }

    /**
     * Set a custom manager (for testing or container integration).
     */
    public static function setManager(ConcurrencyManager $manager): void
    {
        self::$manager = $manager;
    }
}
