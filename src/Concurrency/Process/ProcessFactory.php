<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Process;

use Toporia\Framework\Concurrency\Contracts\ProcessFactoryInterface;

/**
 * Process Factory
 *
 * Factory for creating and configuring process pools.
 * Provides a fluent API for process pool creation.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class ProcessFactory implements ProcessFactoryInterface
{
    /**
     * Default working directory for new pools.
     */
    private ?string $defaultWorkingDirectory = null;

    /**
     * Default timeout for new pools.
     */
    private float $defaultTimeout = 60.0;

    /**
     * Default max concurrent processes.
     */
    private int $defaultMaxConcurrent = 10;

    /**
     * Set default working directory for all new pools.
     */
    public function defaultPath(string $path): self
    {
        $this->defaultWorkingDirectory = $path;
        return $this;
    }

    /**
     * Set default timeout for all new pools.
     */
    public function defaultTimeout(float $seconds): self
    {
        $this->defaultTimeout = max(0.0, $seconds);
        return $this;
    }

    /**
     * Set default max concurrent for all new pools.
     */
    public function defaultConcurrent(int $max): self
    {
        $this->defaultMaxConcurrent = max(1, $max);
        return $this;
    }

    /**
     * Create a new process pool using a callback for configuration.
     *
     * @param callable(ProcessPool): void $callback
     */
    public function pool(callable $callback): ProcessPool
    {
        $pool = $this->createPool();
        $callback($pool);
        return $pool;
    }

    /**
     * Create an empty process pool with default settings.
     */
    public function createPool(): ProcessPool
    {
        $pool = new ProcessPool();

        if ($this->defaultWorkingDirectory !== null) {
            $pool->path($this->defaultWorkingDirectory);
        }

        $pool->timeout($this->defaultTimeout);
        $pool->concurrent($this->defaultMaxConcurrent);

        return $pool;
    }

    /**
     * Run processes concurrently and return results.
     *
     * Convenience method for simple use cases.
     *
     * @param callable(ProcessPool): void $callback
     * @return array<string|int, ProcessResult>
     */
    public function concurrently(callable $callback): array
    {
        return $this->pool($callback)->start();
    }
}
