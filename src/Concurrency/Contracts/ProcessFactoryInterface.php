<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Contracts;

use Toporia\Framework\Concurrency\Process\ProcessPool;

/**
 * Process Factory Interface
 *
 * Factory for creating process pools.
 * Abstraction allows for testing and alternative implementations.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
interface ProcessFactoryInterface
{
    /**
     * Create a new process pool using a callback for configuration.
     *
     * @param callable(ProcessPool): void $callback Configuration callback
     * @return ProcessPool Configured process pool
     */
    public function pool(callable $callback): ProcessPool;

    /**
     * Create an empty process pool.
     *
     * @return ProcessPool
     */
    public function createPool(): ProcessPool;
}
