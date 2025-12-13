<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\ConnectionPool;

/**
 * Interface ConnectionPoolInterface
 *
 * Connection pooling interface for broker connections.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers\ConnectionPool
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ConnectionPoolInterface
{
    /**
     * Get connection from pool by key.
     *
     * @param string $key Connection identifier
     * @return mixed Connection instance or null if not found
     */
    public function get(string $key): mixed;

    /**
     * Store connection in pool.
     *
     * @param string $key Connection identifier
     * @param mixed $connection Connection instance
     * @return void
     */
    public function store(string $key, mixed $connection): void;

    /**
     * Release connection from pool.
     *
     * @param string $key Connection identifier
     * @return void
     */
    public function release(string $key): void;

    /**
     * Clear all connections from pool.
     *
     * @return void
     */
    public function clear(): void;

    /**
     * Get pool statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array;
}
