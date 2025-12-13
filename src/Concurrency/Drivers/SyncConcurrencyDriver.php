<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Drivers;

use InvalidArgumentException;
use Toporia\Framework\Concurrency\Contracts\ConcurrencyDriverInterface;
use Toporia\Framework\Concurrency\Exceptions\ConcurrencyException;
use Throwable;

/**
 * Sync Concurrency Driver
 *
 * Sequential execution driver with no actual parallelism.
 * Tasks are executed one after another in the current process.
 *
 * Use cases:
 * - Testing (predictable, debuggable execution)
 * - Environments without proc_open or pcntl
 * - Development (easier debugging)
 * - When parallelism overhead isn't worth it
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class SyncConcurrencyDriver implements ConcurrencyDriverInterface
{
    /**
     * Deferred tasks.
     *
     * @var array<callable>
     */
    private static array $deferredTasks = [];

    /**
     * Whether shutdown handler is registered.
     */
    private static bool $shutdownRegistered = false;

    /**
     * @param float $timeout Timeout in seconds (0 = no timeout)
     * @param bool $throwOnError Whether to throw on task error or continue
     */
    public function __construct(
        private readonly float $timeout = 0.0,
        private readonly bool $throwOnError = false
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function run(array $tasks): array
    {
        if (empty($tasks)) {
            return [];
        }

        $results = [];
        $startTime = microtime(true);
        $effectiveTimeout = $this->timeout > 0 ? $this->timeout : PHP_INT_MAX;

        foreach ($tasks as $key => $task) {
            if (!is_callable($task)) {
                throw new InvalidArgumentException(
                    sprintf('Task "%s" must be callable, %s given', $key, gettype($task))
                );
            }

            // Check timeout
            if ($this->timeout > 0 && (microtime(true) - $startTime) > $effectiveTimeout) {
                $results[$key] = [
                    'error' => 'Timeout exceeded',
                    'exception' => 'TimeoutException',
                ];
                continue;
            }

            try {
                $results[$key] = $task();
            } catch (Throwable $e) {
                if ($this->throwOnError) {
                    throw ConcurrencyException::taskFailed($key, $e->getMessage(), 1, $e);
                }

                $results[$key] = [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ];
            }
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function defer(array $tasks): void
    {
        if (empty($tasks)) {
            return;
        }

        foreach ($tasks as $task) {
            if (!is_callable($task)) {
                continue;
            }
            self::$deferredTasks[] = $task;
        }

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'executeDeferredTasks']);
        }
    }

    /**
     * Execute deferred tasks on shutdown.
     *
     * @internal
     */
    public static function executeDeferredTasks(): void
    {
        if (empty(self::$deferredTasks)) {
            return;
        }

        // Flush output first
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }

        foreach (self::$deferredTasks as $task) {
            try {
                $task();
            } catch (Throwable $e) {
                error_log('Deferred task failed: ' . $e->getMessage());
            }
        }

        self::$deferredTasks = [];
    }

    /**
     * {@inheritdoc}
     */
    public function isSupported(): bool
    {
        return true; // Always supported
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'sync';
    }

    /**
     * Reset deferred tasks (for testing).
     *
     * @internal
     */
    public static function resetDeferredTasks(): void
    {
        self::$deferredTasks = [];
        self::$shutdownRegistered = false;
    }
}
