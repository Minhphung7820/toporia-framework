<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing\Contracts;


/**
 * Interface RouteCacheInterface
 *
 * Contract defining the interface for RouteCacheInterface implementations
 * in the HTTP routing and URL generation layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Routing\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface RouteCacheInterface
{
    /**
     * Check if routes are cached.
     *
     * @return bool
     */
    public function isCached(): bool;

    /**
     * Get cached routes.
     *
     * Returns compiled route data for fast lookup.
     *
     * @return array|null Cached routes or null if not cached
     */
    public function get(): ?array;

    /**
     * Cache compiled routes.
     *
     * Stores routes in optimized format for O(1) lookup.
     *
     * @param array $routes Compiled routes data
     * @return bool Success status
     */
    public function put(array $routes): bool;

    /**
     * Clear route cache.
     *
     * @return bool Success status
     */
    public function clear(): bool;

    /**
     * Get cache file path.
     *
     * @return string
     */
    public function getCachePath(): string;
}
