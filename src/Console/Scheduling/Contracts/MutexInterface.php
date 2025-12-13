<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Scheduling\Contracts;


/**
 * Interface MutexInterface
 *
 * Contract defining the interface for MutexInterface implementations in
 * the Scheduling layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Scheduling\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface MutexInterface
{
    /**
     * Attempt to acquire a lock
     *
     * @param string $name Lock name
     * @param int $expiresAfter Lock expires after X minutes
     * @return bool True if lock was acquired, false if already locked
     */
    public function create(string $name, int $expiresAfter = 1440): bool;

    /**
     * Check if a lock exists
     *
     * @param string $name Lock name
     * @return bool
     */
    public function exists(string $name): bool;

    /**
     * Release a lock
     *
     * @param string $name Lock name
     * @return bool
     */
    public function forget(string $name): bool;
}
