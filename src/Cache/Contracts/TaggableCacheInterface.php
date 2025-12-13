<?php

declare(strict_types=1);

namespace Toporia\Framework\Cache\Contracts;


/**
 * Interface TaggableCacheInterface
 *
 * Contract defining the interface for TaggableCacheInterface
 * implementations in the Multi-driver caching system layer of the Toporia
 * Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Cache\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface TaggableCacheInterface extends CacheInterface
{
    /**
     * Tag a cache key.
     *
     * @param string|array $tags Tag name(s)
     * @return TaggableCacheInterface Tagged cache instance
     */
    public function tags(string|array $tags): TaggableCacheInterface;

    /**
     * Clear all cache entries with given tags.
     *
     * @param string|array $tags Tag name(s)
     * @return bool True on success
     */
    public function flushTags(string|array $tags): bool;
}

