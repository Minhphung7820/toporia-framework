<?php

declare(strict_types=1);

namespace Toporia\Framework\Cache\Contracts;


/**
 * Interface CacheManagerInterface
 *
 * Contract defining the interface for CacheManagerInterface
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
interface CacheManagerInterface extends CacheInterface
{
    /**
     * Get a cache driver instance
     *
     * @param string|null $driver Driver name (null = default)
     * @return CacheInterface
     */
    public function driver(?string $driver = null): CacheInterface;

    /**
     * Get default driver name
     *
     * @return string
     */
    public function getDefaultDriver(): string;

    /**
     * Get a tagged cache instance
     *
     * @param string|array $tags Tag name(s)
     * @return TaggableCacheInterface Tagged cache instance
     */
    public function tags(string|array $tags): TaggableCacheInterface;
}
