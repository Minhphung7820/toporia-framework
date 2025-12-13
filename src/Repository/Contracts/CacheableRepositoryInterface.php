<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Contracts;

/**
 * Interface CacheableRepositoryInterface
 *
 * Contract for repositories that support caching.
 * Provides methods for cache management and configuration.
 *
 * @extends RepositoryInterface
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository\Contracts
 */
interface CacheableRepositoryInterface extends RepositoryInterface
{
    /**
     * Skip cache for next query.
     *
     * @param bool $skip Whether to skip cache
     * @return static
     */
    public function skipCache(bool $skip = true): static;

    /**
     * Enable caching for queries.
     *
     * @return static
     */
    public function enableCache(): static;

    /**
     * Disable caching for queries.
     *
     * @return static
     */
    public function disableCache(): static;

    /**
     * Set cache TTL (time-to-live) in seconds.
     *
     * @param int $seconds Cache duration
     * @return static
     */
    public function cacheFor(int $seconds): static;

    /**
     * Set cache tags for grouped invalidation.
     *
     * @param array<string> $tags Cache tags
     * @return static
     */
    public function cacheTags(array $tags): static;

    /**
     * Clear all cache for this repository.
     *
     * @return bool
     */
    public function clearCache(): bool;

    /**
     * Clear cache for specific entity.
     *
     * @param int|string $id Entity ID
     * @return bool
     */
    public function clearCacheFor(int|string $id): bool;

    /**
     * Flush cache by tags.
     *
     * @param array<string> $tags Tags to flush
     * @return bool
     */
    public function flushCacheByTags(array $tags): bool;

    /**
     * Get cache key for query.
     *
     * @param string $method Method name
     * @param array<mixed> $args Method arguments
     * @return string
     */
    public function getCacheKey(string $method, array $args = []): string;

    /**
     * Check if caching is enabled.
     *
     * @return bool
     */
    public function isCacheEnabled(): bool;
}
