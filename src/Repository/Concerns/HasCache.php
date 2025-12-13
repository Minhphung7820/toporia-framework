<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Concerns;

use Toporia\Framework\Cache\Contracts\CacheManagerInterface;

/**
 * Trait HasCache
 *
 * Provides caching functionality for repositories.
 * Supports TTL, tags, and selective cache invalidation.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository\Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasCache
{
    /**
     * @var CacheManagerInterface|null Cache manager instance
     */
    protected ?CacheManagerInterface $cache = null;

    /**
     * @var bool Whether caching is enabled
     */
    protected bool $cacheEnabled = true;

    /**
     * @var bool Skip cache for next query
     */
    protected bool $skipCache = false;

    /**
     * @var int Cache TTL in seconds (default 1 hour)
     */
    protected int $cacheTtl = 3600;

    /**
     * @var array<string> Cache tags
     */
    protected array $cacheTags = [];

    /**
     * @var string Cache key prefix
     */
    protected string $cachePrefix = 'repository';

    /**
     * Set cache manager instance.
     *
     * @param CacheManagerInterface $cache
     * @return static
     */
    public function setCacheManager(CacheManagerInterface $cache): static
    {
        $this->cache = $cache;
        return $this;
    }

    /**
     * Skip cache for next query.
     *
     * @param bool $skip
     * @return static
     */
    public function skipCache(bool $skip = true): static
    {
        $this->skipCache = $skip;
        return $this;
    }

    /**
     * Enable caching for queries.
     *
     * @return static
     */
    public function enableCache(): static
    {
        $this->cacheEnabled = true;
        return $this;
    }

    /**
     * Disable caching for queries.
     *
     * @return static
     */
    public function disableCache(): static
    {
        $this->cacheEnabled = false;
        return $this;
    }

    /**
     * Set cache TTL in seconds.
     *
     * @param int $seconds
     * @return static
     */
    public function cacheFor(int $seconds): static
    {
        $this->cacheTtl = $seconds;
        return $this;
    }

    /**
     * Set cache tags.
     *
     * @param array<string> $tags
     * @return static
     */
    public function cacheTags(array $tags): static
    {
        $this->cacheTags = $tags;
        return $this;
    }

    /**
     * Check if caching is enabled and available.
     *
     * @return bool
     */
    public function isCacheEnabled(): bool
    {
        return $this->cache !== null && $this->cacheEnabled && !$this->skipCache;
    }

    /**
     * Get value from cache or execute callback and cache result.
     *
     * @template T
     * @param string $key Cache key
     * @param callable(): T $callback Callback to execute if not cached
     * @return T
     */
    protected function remember(string $key, callable $callback): mixed
    {
        if (!$this->isCacheEnabled()) {
            return $callback();
        }

        $cacheKey = $this->getCacheKey($key, []);

        // Try to get from cache
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Execute callback and cache result
        $result = $callback();
        $this->cacheResult($cacheKey, $result);

        return $result;
    }

    /**
     * Cache a result.
     *
     * @param string $key Cache key
     * @param mixed $result Result to cache
     * @return void
     */
    protected function cacheResult(string $key, mixed $result): void
    {
        if (!$this->isCacheEnabled()) {
            return;
        }

        $this->cache->set($key, $result, $this->cacheTtl);
    }

    /**
     * Generate cache key.
     *
     * @param string $method Method name
     * @param array<mixed> $args Method arguments
     * @return string
     */
    public function getCacheKey(string $method, array $args = []): string
    {
        $modelClass = $this->getModelClass();
        $shortClass = $this->getClassBasename($modelClass);

        // Build key components
        $keyParts = [
            $this->cachePrefix,
            strtolower($shortClass),
            $method,
        ];

        // Add serialized args if present
        if (!empty($args)) {
            $keyParts[] = md5(serialize($args));
        }

        // Add criteria hash if criteria are applied
        if (!empty($this->criteria)) {
            $criteriaClasses = array_map(fn($c) => get_class($c), $this->criteria);
            $keyParts[] = 'criteria_' . md5(implode('|', $criteriaClasses));
        }

        // Add query modifiers hash
        $modifiers = $this->getQueryModifiersHash();
        if ($modifiers) {
            $keyParts[] = $modifiers;
        }

        return implode(':', $keyParts);
    }

    /**
     * Get hash of current query modifiers.
     *
     * @return string|null
     */
    protected function getQueryModifiersHash(): ?string
    {
        $modifiers = [];

        if (!empty($this->eagerLoad)) {
            $modifiers['with'] = $this->eagerLoad;
        }
        if (!empty($this->orderBys)) {
            $modifiers['orderBy'] = $this->orderBys;
        }
        if ($this->queryLimit !== null) {
            $modifiers['limit'] = $this->queryLimit;
        }
        if ($this->queryOffset !== null) {
            $modifiers['offset'] = $this->queryOffset;
        }
        if ($this->includeTrashed) {
            $modifiers['trashed'] = 'with';
        }
        if ($this->onlyTrashedFlag) {
            $modifiers['trashed'] = 'only';
        }

        return !empty($modifiers) ? 'mod_' . md5(serialize($modifiers)) : null;
    }

    /**
     * Clear all cache for this repository.
     *
     * @return bool
     */
    public function clearCache(): bool
    {
        if ($this->cache === null) {
            return false;
        }

        $tag = $this->getRepositoryCacheTag();

        try {
            return $this->cache->tags([$tag])->clear();
        } catch (\Throwable) {
            // Fallback if tags not supported
            return true;
        }
    }

    /**
     * Clear cache for specific entity.
     *
     * @param int|string $id
     * @return bool
     */
    public function clearCacheFor(int|string $id): bool
    {
        if ($this->cache === null) {
            return false;
        }

        // Clear find cache
        $key = $this->getCacheKey('find', [$id]);
        $this->cache->delete($key);

        // Also clear any aggregate caches
        return $this->clearCache();
    }

    /**
     * Flush cache by tags.
     *
     * @param array<string> $tags
     * @return bool
     */
    public function flushCacheByTags(array $tags): bool
    {
        if ($this->cache === null) {
            return false;
        }

        try {
            return $this->cache->tags($tags)->clear();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get repository cache tag.
     *
     * @return string
     */
    protected function getRepositoryCacheTag(): string
    {
        $modelClass = $this->getModelClass();
        return 'repo:' . strtolower($this->getClassBasename($modelClass));
    }

    /**
     * Reset cache state after query.
     *
     * @return void
     */
    protected function resetCacheState(): void
    {
        $this->skipCache = false;
    }

    /**
     * Invalidate cache on write operations.
     *
     * @return void
     */
    protected function invalidateCacheOnWrite(): void
    {
        $this->clearCache();
    }

    /**
     * Get the basename of a class name.
     *
     * @param string $class
     * @return string
     */
    protected function getClassBasename(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }
}
