<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Concerns;


/**
 * Trait InteractsWithCache
 *
 * Multi-driver cache implementation supporting File, Redis, and Memory
 * drivers with PSR-16 inspired interface and tag-based invalidation.
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
trait InteractsWithCache
{
    /**
     * Cache storage (in-memory for testing).
     *
     * @var array<string, mixed>
     */
    protected array $cache = [];

    /**
     * Clear cache.
     *
     * Performance: O(1)
     */
    protected function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Assert that a cache key exists.
     *
     * Performance: O(1)
     */
    protected function assertCacheHas(string $key): void
    {
        $this->assertArrayHasKey($key, $this->cache, "Cache key {$key} does not exist");
    }

    /**
     * Assert that a cache key doesn't exist.
     *
     * Performance: O(1)
     */
    protected function assertCacheMissing(string $key): void
    {
        $this->assertArrayNotHasKey($key, $this->cache, "Cache key {$key} unexpectedly exists");
    }

    /**
     * Assert cache value.
     *
     * Performance: O(1)
     */
    protected function assertCacheEquals(mixed $expected, string $key): void
    {
        $this->assertArrayHasKey($key, $this->cache, "Cache key {$key} does not exist");
        $this->assertEquals($expected, $this->cache[$key], "Cache value mismatch for key {$key}");
    }

    /**
     * Cleanup cache after test.
     */
    protected function tearDownCache(): void
    {
        $this->clearCache();
    }
}
