<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency;

use InvalidArgumentException;
use Toporia\Framework\Concurrency\Contracts\ConcurrencyDriverInterface;

/**
 * Concurrency Manager
 *
 * Central manager for the Concurrency subsystem.
 * Provides a unified API for running tasks concurrently across different drivers.
 *
 * Usage:
 * ```php
 * // Using default driver
 * $results = $manager->run([
 *     'users' => fn() => $userService->getAll(),
 *     'orders' => fn() => $orderService->getRecent(),
 * ]);
 *
 * // Using specific driver
 * $results = $manager->driver('fork')->run([...]);
 *
 * // Defer tasks (fire-and-forget)
 * $manager->defer([
 *     fn() => $metrics->report(),
 *     fn() => $cache->warm(),
 * ]);
 * ```
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class ConcurrencyManager
{
    /**
     * @param array<string, ConcurrencyDriverInterface> $drivers Available drivers
     * @param string $defaultDriver Default driver name
     */
    public function __construct(
        private array $drivers,
        private string $defaultDriver
    ) {
        if (!isset($this->drivers[$this->defaultDriver])) {
            throw new InvalidArgumentException(
                sprintf('Default driver "%s" is not registered', $this->defaultDriver)
            );
        }
    }

    /**
     * Get a specific driver instance.
     *
     * @param string|null $name Driver name (null for default)
     * @return ConcurrencyDriverInterface
     * @throws InvalidArgumentException If driver not found
     */
    public function driver(?string $name = null): ConcurrencyDriverInterface
    {
        $driverName = $name ?? $this->defaultDriver;

        if (!isset($this->drivers[$driverName])) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown concurrency driver "%s". Available: %s',
                    $driverName,
                    implode(', ', array_keys($this->drivers))
                )
            );
        }

        $driver = $this->drivers[$driverName];

        // Auto-fallback if driver not supported
        if (!$driver->isSupported() && $driverName !== 'sync') {
            return $this->driver('sync');
        }

        return $driver;
    }

    /**
     * Run tasks concurrently using the default driver.
     *
     * @param array<int|string, callable> $tasks Tasks to execute
     * @return array<int|string, mixed> Results keyed by task keys
     */
    public function run(array $tasks): array
    {
        return $this->driver()->run($tasks);
    }

    /**
     * Defer tasks to run after main execution flow.
     *
     * @param array<int|string, callable> $tasks Tasks to defer
     */
    public function defer(array $tasks): void
    {
        $this->driver()->defer($tasks);
    }

    /**
     * Set the default driver.
     *
     * @param string $name Driver name
     * @return self
     */
    public function setDefaultDriver(string $name): self
    {
        if (!isset($this->drivers[$name])) {
            throw new InvalidArgumentException(
                sprintf('Cannot set default to unknown driver "%s"', $name)
            );
        }

        $this->defaultDriver = $name;
        return $this;
    }

    /**
     * Get the current default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->defaultDriver;
    }

    /**
     * Check if a driver exists.
     */
    public function hasDriver(string $name): bool
    {
        return isset($this->drivers[$name]);
    }

    /**
     * Get all available driver names.
     *
     * @return array<string>
     */
    public function getAvailableDrivers(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * Get drivers with their support status.
     *
     * @return array<string, bool>
     */
    public function getDriverSupport(): array
    {
        $support = [];

        foreach ($this->drivers as $name => $driver) {
            $support[$name] = $driver->isSupported();
        }

        return $support;
    }

    /**
     * Register a new driver.
     *
     * @param string $name Driver name
     * @param ConcurrencyDriverInterface $driver Driver instance
     * @return self
     */
    public function extend(string $name, ConcurrencyDriverInterface $driver): self
    {
        $this->drivers[$name] = $driver;
        return $this;
    }

    /**
     * Create a new manager instance with additional driver.
     *
     * @param string $name Driver name
     * @param ConcurrencyDriverInterface $driver Driver instance
     * @return self
     */
    public function withDriver(string $name, ConcurrencyDriverInterface $driver): self
    {
        $drivers = $this->drivers;
        $drivers[$name] = $driver;

        return new self($drivers, $this->defaultDriver);
    }
}
