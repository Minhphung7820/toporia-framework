<?php

declare(strict_types=1);

namespace Toporia\Framework\Cache;

use Toporia\Framework\Cache\Contracts\{CacheInterface, TaggableCacheInterface};

/**
 * Class TaggedCache
 *
 * Wraps a cache driver with tag support.
 * Allows grouping related cache entries and clearing by tag.
 *
 * Performance:
 * - Tag operations: O(N) where N = keys in tag
 * - Tag clearing: O(N*M) where N = tags, M = keys per tag
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles tag management
 * - Dependency Inversion: Uses CacheInterface
 * - Decorator pattern: Wraps existing cache
 *
 * SOLID Principles:
 * - S: Only handles tagging
 * - O: Extensible via different tag storage
 * - L: Behaves like CacheInterface
 * - I: Focused interface
 * - D: Depends on CacheInterface abstraction
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
final class TaggedCache implements TaggableCacheInterface
{
    /**
     * @var array<string> Active tags
     */
    private array $tags = [];

    /**
     * @param CacheInterface $cache Base cache driver
     * @param CacheInterface $tagStore Cache for storing tag-key mappings
     * @param array<string> $tags Initial tags
     */
    public function __construct(
        private CacheInterface $cache,
        private CacheInterface $tagStore,
        array $tags = []
    ) {
        $this->tags = $tags;
    }

    /**
     * Tag a cache key.
     *
     * @param string|array $tags Tag name(s)
     * @return TaggableCacheInterface Tagged cache instance
     */
    public function tags(string|array $tags): TaggableCacheInterface
    {
        $tags = is_array($tags) ? $tags : [$tags];
        return new self($this->cache, $this->tagStore, $tags);
    }

    /**
     * Get a value from cache.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $taggedKey = $this->getTaggedKey($key);
        return $this->cache->get($taggedKey, $default);
    }

    /**
     * Set a value in cache.
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl
     * @return bool
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $taggedKey = $this->getTaggedKey($key);
        $result = $this->cache->set($taggedKey, $value, $ttl);

        if ($result) {
            $this->addKeyToTags($key);
        }

        return $result;
    }

    /**
     * Check if a key exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $taggedKey = $this->getTaggedKey($key);
        return $this->cache->has($taggedKey);
    }

    /**
     * Delete a value from cache.
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $taggedKey = $this->getTaggedKey($key);
        $result = $this->cache->delete($taggedKey);

        if ($result) {
            $this->removeKeyFromTags($key);
        }

        return $result;
    }

    /**
     * Clear all cache.
     *
     * @return bool
     */
    public function clear(): bool
    {
        return $this->flushTags($this->tags);
    }

    /**
     * Get multiple values.
     *
     * @param array $keys
     * @param mixed $default
     * @return array
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * Set multiple values.
     *
     * @param array $values
     * @param int|null $ttl
     * @return bool
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Delete multiple values.
     *
     * @param array $keys
     * @return bool
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Increment a numeric value.
     *
     * @param string $key
     * @param int $value
     * @return int|false
     */
    public function increment(string $key, int $value = 1): int|false
    {
        $taggedKey = $this->getTaggedKey($key);
        return $this->cache->increment($taggedKey, $value);
    }

    /**
     * Decrement a numeric value.
     *
     * @param string $key
     * @param int $value
     * @return int|false
     */
    public function decrement(string $key, int $value = 1): int|false
    {
        $taggedKey = $this->getTaggedKey($key);
        return $this->cache->decrement($taggedKey, $value);
    }

    /**
     * Remember a value using closure.
     *
     * @param string $key
     * @param int|null $ttl
     * @param callable $callback
     * @return mixed
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * Remember a value forever.
     *
     * @param string $key
     * @param callable $callback
     * @return mixed
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    /**
     * Store a value forever.
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, null);
    }

    /**
     * Get and delete a value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);
        return $value;
    }

    /**
     * Clear all cache entries with given tags.
     *
     * @param string|array $tags
     * @return bool
     */
    public function flushTags(string|array $tags): bool
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $success = true;

        foreach ($tags as $tag) {
            $tagKey = $this->getTagKey($tag);
            $keys = $this->tagStore->get($tagKey, []);

            if (is_array($keys)) {
                foreach ($keys as $key) {
                    $taggedKey = $this->getTaggedKey($key, $tag);
                    $this->cache->delete($taggedKey);
                }
            }

            $this->tagStore->delete($tagKey);
        }

        return $success;
    }

    /**
     * Get tagged cache key.
     *
     * @param string $key
     * @param string|null $tag Optional specific tag
     * @return string
     */
    private function getTaggedKey(string $key, ?string $tag = null): string
    {
        $tags = $tag !== null ? [$tag] : $this->tags;
        $tagString = implode('|', $tags);
        return "tagged:{$tagString}:{$key}";
    }

    /**
     * Get tag storage key.
     *
     * @param string $tag
     * @return string
     */
    private function getTagKey(string $tag): string
    {
        return "cache_tags:{$tag}";
    }

    /**
     * Add key to tag tracking.
     *
     * @param string $key
     * @return void
     */
    private function addKeyToTags(string $key): void
    {
        foreach ($this->tags as $tag) {
            $tagKey = $this->getTagKey($tag);
            $keys = $this->tagStore->get($tagKey, []);
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
                $this->tagStore->forever($tagKey, $keys);
            }
        }
    }

    /**
     * Remove key from tag tracking.
     *
     * @param string $key
     * @return void
     */
    private function removeKeyFromTags(string $key): void
    {
        foreach ($this->tags as $tag) {
            $tagKey = $this->getTagKey($tag);
            $keys = $this->tagStore->get($tagKey, []);
            $keys = array_filter($keys, fn($k) => $k !== $key);
            $this->tagStore->forever($tagKey, array_values($keys));
        }
    }
}
