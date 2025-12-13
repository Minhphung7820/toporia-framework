<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Scheduling;

use Toporia\Framework\Console\Scheduling\Contracts\MutexInterface;
use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class CacheMutex
 *
 * Implements mutex using cache backend (file, Redis, etc.)
 * for preventing task overlaps across multiple servers.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Scheduling
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class CacheMutex implements MutexInterface
{
    public function __construct(
        private CacheInterface $cache
    ) {}

    /**
     * {@inheritdoc}
     *
     * CRITICAL: Uses atomic add() operation to prevent TOCTOU race condition.
     * In distributed systems, the old has() + set() pattern would allow
     * multiple servers to acquire the same lock simultaneously.
     */
    public function create(string $name, int $expiresAfter = 1440): bool
    {
        $key = $this->getKey($name);

        // Use atomic add() - returns false if key already exists
        // This prevents race condition where two processes both pass has() check
        return $this->cache->add($key, now()->getTimestamp(), $expiresAfter * 60); // Convert minutes to seconds
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $name): bool
    {
        return $this->cache->has($this->getKey($name));
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $name): bool
    {
        return $this->cache->delete($this->getKey($name));
    }

    /**
     * Get cache key for mutex
     *
     * @param string $name
     * @return string
     */
    private function getKey(string $name): string
    {
        return "mutex:{$name}";
    }
}
