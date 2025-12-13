<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

use Toporia\Framework\Database\ORM\Model;


/**
 * Trait HasModelCaching
 *
 * Trait providing reusable functionality for HasModelCaching in the
 * Concerns layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasModelCaching
{
    /**
     * Cache driver instance.
     *
     * @var \Toporia\Framework\Cache\Contracts\CacheInterface|null
     */
    protected static ?object $cacheDriver = null;

    /**
     * Default TTL for cached queries (in seconds).
     *
     * @var int
     */
    protected static int $cacheTtl = 3600; // 1 hour

    /**
     * Cache key prefix for this model.
     *
     * @var string
     */
    protected static string $cachePrefix = '';

    /**
     * Whether caching is enabled for this model.
     *
     * @var bool
     */
    protected static bool $cacheEnabled = true;

    /**
     * Boot the model caching trait.
     *
     * @return void
     */
    protected static function bootHasModelCaching(): void
    {
        // Auto-generate cache prefix from model class name
        if (empty(static::$cachePrefix)) {
            $className = static::class;
            $className = str_replace('\\', '_', $className);
            static::$cachePrefix = strtolower($className) . ':';
        }
    }

    /**
     * Set the cache driver.
     *
     * @param object|null $driver Cache driver instance, or null to clear
     * @return void
     */
    public static function setCacheDriver(?object $driver): void
    {
        static::$cacheDriver = $driver;
    }

    /**
     * Get the cache driver.
     *
     * @return object|null
     */
    protected static function getCacheDriver(): ?object
    {
        return static::$cacheDriver;
    }

    /**
     * Check if caching is enabled.
     *
     * @return bool
     */
    public static function isCachingEnabled(): bool
    {
        return static::$cacheEnabled && static::$cacheDriver !== null;
    }

    /**
     * Enable caching for this model.
     *
     * @return void
     */
    public static function enableCaching(): void
    {
        static::$cacheEnabled = true;
    }

    /**
     * Disable caching for this model.
     *
     * @return void
     */
    public static function disableCaching(): void
    {
        static::$cacheEnabled = false;
    }

    /**
     * Set cache TTL.
     *
     * @param int $ttl Time to live in seconds
     * @return void
     */
    public static function setCacheTtl(int $ttl): void
    {
        static::$cacheTtl = $ttl;
    }

    /**
     * Get cache TTL.
     *
     * @return int
     */
    public static function getCacheTtl(): int
    {
        return static::$cacheTtl;
    }

    /**
     * Generate cache key for a model instance.
     *
     * @param int|string $id Primary key value
     * @return string
     */
    protected static function getCacheKey(int|string $id): string
    {
        return static::$cachePrefix . 'model:' . $id;
    }

    /**
     * Generate cache key for a query.
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @return string
     */
    protected static function getQueryCacheKey(string $query, array $bindings): string
    {
        $hash = md5($query . serialize($bindings));
        return static::$cachePrefix . 'query:' . $hash;
    }

    /**
     * Get a model from cache.
     *
     * @param int|string $id Primary key value
     * @return Model|null
     */
    protected static function getFromCache(int|string $id): ?Model
    {
        if (!static::isCachingEnabled()) {
            return null;
        }

        $cache = static::getCacheDriver();
        $key = static::getCacheKey($id);

        if (method_exists($cache, 'get')) {
            $cached = $cache->get($key);
            if ($cached !== null && $cached instanceof Model) {
                return $cached;
            }
        }

        return null;
    }

    /**
     * Store a model in cache.
     *
     * @param Model $model Model instance
     * @param int|null $ttl Optional TTL (uses default if null)
     * @return void
     */
    protected static function putInCache(Model $model, ?int $ttl = null): void
    {
        if (!static::isCachingEnabled()) {
            return;
        }

        $modelKey = $model->getKey();
        if ($modelKey === null) {
            return; // Cannot cache model without a key
        }

        $cache = static::getCacheDriver();
        $key = static::getCacheKey($modelKey);
        $ttl = $ttl ?? static::$cacheTtl;

        if (method_exists($cache, 'put')) {
            $cache->put($key, $model, $ttl);
        } elseif (method_exists($cache, 'set')) {
            $cache->set($key, $model, $ttl);
        }
    }

    /**
     * Remove a model from cache.
     *
     * @param int|string $id Primary key value
     * @return void
     */
    protected static function forgetFromCache(int|string $id): void
    {
        if (!static::isCachingEnabled()) {
            return;
        }

        $cache = static::getCacheDriver();
        $key = static::getCacheKey($id);

        if (method_exists($cache, 'forget')) {
            $cache->forget($key);
        } elseif (method_exists($cache, 'delete')) {
            $cache->delete($key);
        }
    }

    /**
     * Clear all cache for this model.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        if (!static::isCachingEnabled()) {
            return;
        }

        $cache = static::getCacheDriver();
        $prefix = static::$cachePrefix;

        // If cache supports tags, use them
        if (method_exists($cache, 'flush') && method_exists($cache, 'tag')) {
            $cache->tag($prefix)->flush();
        } elseif (method_exists($cache, 'flush')) {
            // Fallback: flush all (less precise)
            $cache->flush();
        }
    }

    /**
     * Find a model by ID with caching.
     *
     * @param int|string $id Primary key value
     * @return Model|null
     */
    public static function findCached(int|string $id): ?Model
    {
        // Try cache first
        $cached = static::getFromCache($id);
        if ($cached !== null) {
            return $cached;
        }

        // Fallback to database
        if (method_exists(static::class, 'find')) {
            /** @var Model|null $model */
            $model = static::find($id);
            if ($model !== null) {
                static::putInCache($model);
            }
            return $model;
        }

        return null;
    }

    /**
     * Get the primary key value.
     *
     * @return mixed
     */
    abstract public function getKey(): mixed;
}
