<?php

declare(strict_types=1);

namespace Toporia\Framework\Cache\Drivers;

use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class MemoryCache
 *
 * Stores cache in PHP memory (array). Data is lost at the end of request.
 * Useful for testing or caching data within a single request.
 *
 * Performance:
 * - O(1) get/set operations (array access)
 * - O(N) clear where N = number of keys
 * - Zero I/O overhead
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles in-memory caching
 * - Dependency Inversion: Implements CacheInterface
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Cache\Drivers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MemoryCache implements CacheInterface
{
    private array $storage = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->storage[$key])) {
            return $default;
        }

        $data = $this->storage[$key];

        // Check if expired
        if ($data['expires_at'] !== null && $data['expires_at'] < now()->getTimestamp()) {
            unset($this->storage[$key]);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $expiresAt = $ttl !== null ? now()->getTimestamp() + $ttl : null;

        $this->storage[$key] = [
            'value' => $value,
            'expires_at' => $expiresAt,
        ];

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->storage = [];
        return true;
    }

    public function getMultiple(array $keys, mixed $default = null): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function increment(string $key, int $value = 1): int|false
    {
        $current = $this->get($key, 0);

        if (!is_numeric($current)) {
            return false;
        }

        $new = (int)$current + $value;
        $this->set($key, $new);

        return $new;
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

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

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, null);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);
        return $value;
    }

    /**
     * {@inheritdoc}
     *
     * In-memory operation is inherently atomic in single-threaded PHP.
     * For multi-process scenarios, use RedisCache or FileCache instead.
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        // Check if key exists and is not expired
        if (isset($this->storage[$key])) {
            $data = $this->storage[$key];
            // If no expiration or not expired, key exists
            if ($data['expires_at'] === null || $data['expires_at'] >= now()->getTimestamp()) {
                return false; // Key already exists
            }
            // Key expired, will be overwritten
        }

        // Key doesn't exist or is expired - set it
        $expiresAt = $ttl !== null ? now()->getTimestamp() + $ttl : null;
        $this->storage[$key] = [
            'value' => $value,
            'expires_at' => $expiresAt,
        ];

        return true;
    }

    /**
     * Get all cached data (for debugging)
     *
     * @return array
     */
    public function all(): array
    {
        return $this->storage;
    }
}

