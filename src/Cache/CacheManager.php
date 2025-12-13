<?php

declare(strict_types=1);

namespace Toporia\Framework\Cache;

use Toporia\Framework\Cache\Contracts\{CacheInterface, CacheManagerInterface, TaggableCacheInterface};
use Toporia\Framework\Cache\TaggedCache;

/**
 * Class CacheManager
 *
 * Manages multiple cache drivers and provides a unified interface.
 * Supports driver switching, fallback mechanisms, and tag support.
 *
 * Performance Optimizations:
 * - O(1) driver resolution (cached after first call)
 * - Lazy driver instantiation (only when needed)
 * - Default driver caching (avoids repeated driver() calls)
 * - Memory-efficient tag storage
 *
 * Clean Architecture:
 * - Single Responsibility: Only manages cache drivers
 * - Dependency Inversion: Depends on CacheInterface abstraction
 * - Open/Closed: Extensible via new drivers without modification
 *
 * SOLID Principles:
 * - S: Only manages caches, delegates operations to drivers
 * - O: Extensible via new driver implementations
 * - L: All drivers interchangeable (Liskov Substitution)
 * - I: Focused interfaces (CacheInterface, CacheManagerInterface)
 * - D: Depends on abstractions, not concrete implementations
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Cache
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class CacheManager implements CacheManagerInterface
{
    /**
     * @var array<string, CacheInterface> Resolved driver instances cache
     */
    private array $drivers = [];

    /**
     * @var CacheInterface|null Cached default driver instance (performance optimization)
     */
    private ?CacheInterface $defaultDriverInstance = null;

    /**
     * @var string Default driver name
     */
    private string $defaultDriver;

    /**
     * @var array Cache configuration
     */
    private array $config;

    /**
     * @param array $config Cache configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultDriver = $config['default'] ?? 'file';
    }

    /**
     * Get a cache driver instance.
     *
     * Performance: O(1) after first call (cached)
     * Memory: Lazy instantiation (only creates when accessed)
     *
     * @param string|null $driver Driver name (null = default)
     * @return CacheInterface Cache driver instance
     * @throws \InvalidArgumentException If driver is not configured
     */
    public function driver(?string $driver = null): CacheInterface
    {
        $driver = $driver ?? $this->defaultDriver;

        // Return cached instance if exists
        if (isset($this->drivers[$driver])) {
            return $this->drivers[$driver];
        }

        // Validate driver exists in config
        if (!isset($this->config['stores'][$driver])) {
            throw new \InvalidArgumentException(
                "Cache driver [{$driver}] is not configured. " .
                    "Available drivers: " . implode(', ', array_keys($this->config['stores'] ?? []))
            );
        }

        // Create and cache driver instance
        $this->drivers[$driver] = $this->createDriver($driver);

        // Cache default driver instance for performance
        if ($driver === $this->defaultDriver) {
            $this->defaultDriverInstance = $this->drivers[$driver];
        }

        return $this->drivers[$driver];
    }

    /**
     * Create a cache driver instance.
     *
     * Performance: O(1) - Direct instantiation
     * Memory: Creates driver only when needed
     *
     * @param string $driver Driver name
     * @return CacheInterface Cache driver instance
     * @throws \InvalidArgumentException If driver type is unsupported
     */
    private function createDriver(string $driver): CacheInterface
    {
        $config = $this->config['stores'][$driver] ?? [];
        $driverType = $config['driver'] ?? $driver;

        return match ($driverType) {
            'file' => new Drivers\FileCache(
                $config['path'] ?? sys_get_temp_dir() . '/cache'
            ),
            'redis' => Drivers\RedisCache::fromConfig(
                $config,
                $config['prefix'] ?? $this->config['prefix'] ?? 'cache:'
            ),
            'memory', 'array' => new Drivers\MemoryCache(),
            default => throw new \InvalidArgumentException(
                "Unsupported cache driver type: {$driverType}. " .
                    "Supported types: file, redis, memory, array"
            ),
        };
    }

    /**
     * Get default driver instance (cached for performance).
     *
     * Performance: O(1) - Returns cached instance
     *
     * @return CacheInterface Default cache driver
     */
    private function getDefaultDriverInstance(): CacheInterface
    {
        // Return cached instance if available
        if ($this->defaultDriverInstance !== null) {
            return $this->defaultDriverInstance;
        }

        // Create and cache default driver
        $this->defaultDriverInstance = $this->driver();
        return $this->defaultDriverInstance;
    }

    // =========================================================================
    // Proxy Methods to Default Driver (Performance Optimized)
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getDefaultDriverInstance()->get($key, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->getDefaultDriverInstance()->set($key, $value, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        return $this->getDefaultDriverInstance()->has($key);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        return $this->getDefaultDriverInstance()->delete($key);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        return $this->getDefaultDriverInstance()->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        return $this->getDefaultDriverInstance()->getMultiple($keys, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        return $this->getDefaultDriverInstance()->setMultiple($values, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys): bool
    {
        return $this->getDefaultDriverInstance()->deleteMultiple($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function increment(string $key, int $value = 1): int|false
    {
        return $this->getDefaultDriverInstance()->increment($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->getDefaultDriverInstance()->decrement($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        return $this->getDefaultDriverInstance()->remember($key, $ttl, $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->getDefaultDriverInstance()->rememberForever($key, $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->getDefaultDriverInstance()->forever($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->getDefaultDriverInstance()->pull($key, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->getDefaultDriverInstance()->add($key, $value, $ttl);
    }

    // =========================================================================
    // Manager-Specific Methods
    // =========================================================================

    /**
     * Flush all cache stores.
     *
     * Performance: O(N) where N = number of initialized drivers
     *
     * @return void
     */
    public function flushAll(): void
    {
        foreach ($this->drivers as $driver) {
            $driver->clear();
        }
    }

    /**
     * Get default driver name.
     *
     * Performance: O(1) - Direct property access
     *
     * @return string Default driver name
     */
    public function getDefaultDriver(): string
    {
        return $this->defaultDriver;
    }

    /**
     * Get a tagged cache instance.
     *
     * Performance: O(1) - Creates tagged wrapper
     * Memory: Uses memory cache for tag tracking (efficient)
     *
     * @param string|array $tags Tag name(s)
     * @return TaggableCacheInterface Tagged cache instance
     */
    public function tags(string|array $tags): TaggableCacheInterface
    {
        $cache = $this->getDefaultDriverInstance();
        $tagStore = $this->driver('memory'); // Use memory cache for tag tracking
        $normalizedTags = is_array($tags) ? $tags : [$tags];

        return new TaggedCache($cache, $tagStore, $normalizedTags);
    }

    /**
     * Check if a driver is configured.
     *
     * Performance: O(1) - Array key check
     *
     * @param string $driver Driver name
     * @return bool True if driver is configured
     */
    public function hasDriver(string $driver): bool
    {
        return isset($this->config['stores'][$driver]);
    }

    /**
     * Get all configured driver names.
     *
     * Performance: O(N) where N = number of configured drivers
     *
     * @return array<string> Array of driver names
     */
    public function getDrivers(): array
    {
        return array_keys($this->config['stores'] ?? []);
    }
}
