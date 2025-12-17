<?php

declare(strict_types=1);

namespace Toporia\Framework\Cache\Drivers;

use Toporia\Framework\Cache\Contracts\CacheInterface;
use Redis;

/**
 * Class RedisCache
 *
 * High-performance caching using Redis.
 * Requires phpredis extension.
 *
 * Performance:
 * - O(1) get/set operations (Redis commands)
 * - O(N) clear where N = number of keys with prefix
 * - Sub-millisecond latency
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles Redis caching
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
final class RedisCache implements CacheInterface
{
    private Redis $redis;
    private string $prefix;

    public function __construct(Redis $redis, string $prefix = 'cache:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    /**
     * Create RedisCache from connection config
     *
     * @param array $config ['host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 0]
     * @param string $prefix Cache key prefix
     * @return self
     */
    public static function fromConfig(array $config, string $prefix = 'cache:'): self
    {
        $redis = new Redis();

        // CRITICAL: Cast port to int - env() returns string, but Redis::connect() requires int
        $host = $config['host'] ?? '127.0.0.1';
        $port = isset($config['port']) ? (int) $config['port'] : 6379;

        $redis->connect($host, $port);

        if (!empty($config['password'])) {
            $redis->auth($config['password']);
        }

        // CRITICAL: Cast database to int - env() returns string
        if (isset($config['database'])) {
            $redis->select((int) $config['database']);
        }

        return new self($redis, $prefix);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $prefixedKey = $this->prefixKey($key);
        $value = $this->redis->get($prefixedKey);

        // Redis::get() returns false if key doesn't exist
        if ($value === false || $value === null) {
            return $default;
        }

        // CRITICAL: Handle both raw integers (from INCRBY) and serialized values
        // Raw integers from increment() are stored without serialization for performance
        if (is_numeric($value) && is_string($value)) {
            // This might be a raw integer string from increment operations
            // SECURITY: Check if it's actually serialized by trying unserialize with restriction
            $unserialized = @unserialize($value, ['allowed_classes' => false]);
            if ($unserialized !== false) {
                // It was serialized after all
                return $unserialized;
            }
            // It's a raw integer string - return as integer
            return (int)$value;
        }

        // If value is already integer (from Redis GET on integer key)
        if (is_int($value)) {
            return $value;
        }

        // CRITICAL: Handle unserialize errors gracefully
        // If data is corrupted or not serialized, return default instead of throwing error
        try {
            // Check if value looks like serialized data (PHP serialized strings start with specific chars)
            // This helps detect corrupted or non-serialized data early
            if (is_string($value) && strlen($value) > 0 && !preg_match('/^[aOdNs]:/', $value) && $value !== serialize(false) && $value !== serialize(null)) {
                // Value doesn't look like serialized data - might be corrupted
                // But don't delete yet, try unserialize first
            }

            // SECURITY: Restrict unserialize to prevent PHP Object Injection attacks
            $unserialized = @unserialize($value, ['allowed_classes' => false]);

            // @unserialize returns false if data cannot be unserialized
            // But false is also a valid value, so check if original was serialized false
            if ($unserialized === false && $value !== serialize(false) && $value !== 'b:0;') {
                // Data is corrupted or not serialized - delete it and return default
                $this->redis->del($prefixedKey);
                return $default;
            }

            return $unserialized;
        } catch (\Throwable $e) {
            // Corrupted data - delete it and return default
            error_log("RedisCache unserialize error for key {$prefixedKey}: " . $e->getMessage());
            $this->redis->del($prefixedKey);
            return $default;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $key = $this->prefixKey($key);
        $value = serialize($value);

        if ($ttl === null) {
            return $this->redis->set($key, $value);
        }

        return $this->redis->setex($key, $ttl, $value);
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($this->prefixKey($key)) > 0;
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($this->prefixKey($key)) > 0;
    }

    public function clear(): bool
    {
        // CRITICAL: Use SCAN instead of KEYS to avoid blocking Redis in production
        // KEYS command is O(N) and blocks the entire Redis server
        // SCAN is O(1) per call and doesn't block
        $pattern = $this->prefixKey('*');
        $iterator = null;
        $deletedCount = 0;

        do {
            // SCAN returns [iterator, keys]
            // iterator = 0 means iteration is complete
            $result = $this->redis->scan($iterator, $pattern, 100);

            if ($result === false) {
                break;
            }

            // $result is array of keys (phpredis returns keys directly, not [iterator, keys])
            // For phpredis extension, scan() modifies $iterator by reference
            if (!empty($result)) {
                $deleted = $this->redis->del($result);
                $deletedCount += $deleted;
            }
        } while ($iterator > 0);

        return true; // Always return true even if no keys deleted (consistent with other drivers)
    }

    public function getMultiple(array $keys, mixed $default = null): array
    {
        $prefixedKeys = array_map([$this, 'prefixKey'], $keys);
        $values = $this->redis->mGet($prefixedKeys);

        $result = [];
        foreach ($keys as $i => $key) {
            $value = $values[$i];

            // Handle false/null from Redis
            if ($value === false || $value === null) {
                $result[$key] = $default;
                continue;
            }

            // CRITICAL: Handle unserialize errors gracefully
            try {
                // SECURITY: Restrict unserialize to prevent PHP Object Injection
                $unserialized = @unserialize($value, ['allowed_classes' => false]);

                if ($unserialized === false && $value !== serialize(false)) {
                    // Corrupted data - delete and use default
                    $this->redis->del($prefixedKeys[$i]);
                    $result[$key] = $default;
                } else {
                    $result[$key] = $unserialized;
                }
            } catch (\Throwable $e) {
                // Corrupted data - delete and use default
                $this->redis->del($prefixedKeys[$i]);
                $result[$key] = $default;
            }
        }

        return $result;
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        if ($ttl === null) {
            $prefixed = [];
            foreach ($values as $key => $value) {
                $prefixed[$this->prefixKey($key)] = serialize($value);
            }
            return $this->redis->mSet($prefixed);
        }

        // With TTL, set individually
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(array $keys): bool
    {
        $prefixedKeys = array_map([$this, 'prefixKey'], $keys);
        return $this->redis->del($prefixedKeys) > 0;
    }

    public function increment(string $key, int $value = 1): int|false
    {
        $prefixedKey = $this->prefixKey($key);

        // CRITICAL: Redis INCRBY requires integer values, not serialized strings
        // Check if key exists and is serialized
        $existing = $this->redis->get($prefixedKey);

        if ($existing === false) {
            // Key doesn't exist - use SETNX for atomic operation to prevent race condition
            // SETNX returns true if key was set, false if already exists
            $wasSet = $this->redis->setnx($prefixedKey, $value);
            if ($wasSet) {
                return $value;
            }
            // Another process set the key between GET and SETNX - retry with INCRBY
            $result = $this->redis->incrBy($prefixedKey, $value);
            return $result !== false ? (int)$result : false;
        }

        // Check if value is serialized or raw integer
        // Serialized integers look like "i:1;" or "s:1:"1""
        // Raw integers from Redis INCRBY are just integers
        if (is_numeric($existing)) {
            // Value is already an integer - use INCRBY directly (atomic operation)
            $result = $this->redis->incrBy($prefixedKey, $value);
            return $result !== false ? (int)$result : false;
        }

        // Value is serialized - use WATCH/MULTI/EXEC for atomic read-modify-write
        // This prevents race conditions when converting from serialized to raw integer
        try {
            // SECURITY: Restrict unserialize to prevent PHP Object Injection
            $unserialized = @unserialize($existing, ['allowed_classes' => false]);

            if (is_numeric($unserialized)) {
                $newValue = (int)$unserialized + $value;

                // Use optimistic locking with WATCH to detect concurrent modifications
                $this->redis->watch($prefixedKey);

                // Check if value changed since we read it
                $currentValue = $this->redis->get($prefixedKey);
                if ($currentValue !== $existing) {
                    // Value changed - abort and retry
                    $this->redis->unwatch();
                    return $this->increment($key, $value);
                }

                // Execute atomic transaction
                $this->redis->multi();
                $this->redis->set($prefixedKey, $newValue);
                $result = $this->redis->exec();

                if ($result === false) {
                    // Transaction failed due to concurrent modification - retry
                    return $this->increment($key, $value);
                }

                return $newValue;
            }

            // Not a numeric value - cannot increment
            return false;
        } catch (\Throwable $e) {
            // Ensure WATCH is cleared on error
            try {
                $this->redis->unwatch();
            } catch (\Throwable) {
                // Ignore unwatch errors
            }
            // Corrupted data - cannot increment
            return false;
        }
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        $result = $this->redis->decrBy($this->prefixKey($key), $value);
        return $result !== false ? (int)$result : false;
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
        $prefixedKey = $this->prefixKey($key);
        $value = $this->redis->get($prefixedKey);

        if ($value !== false && $value !== null) {
            $this->redis->del($prefixedKey);

            // SECURITY: Restrict unserialize to prevent PHP Object Injection
            // Handle raw integers from increment() operations
            if (is_numeric($value) && is_string($value)) {
                $unserialized = @unserialize($value, ['allowed_classes' => false]);
                if ($unserialized !== false) {
                    return $unserialized;
                }
                return (int)$value;
            }

            $unserialized = @unserialize($value, ['allowed_classes' => false]);
            if ($unserialized === false && $value !== serialize(false) && $value !== 'b:0;') {
                return $default;
            }
            return $unserialized;
        }

        return $default;
    }

    /**
     * {@inheritdoc}
     *
     * Uses Redis SETNX (SET if Not eXists) for atomic operation.
     * This is the most reliable way to prevent race conditions in distributed systems.
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $prefixedKey = $this->prefixKey($key);
        $serialized = serialize($value);

        if ($ttl === null) {
            // SETNX: Set if Not eXists (atomic)
            return (bool) $this->redis->setnx($prefixedKey, $serialized);
        }

        // SET with NX (not exists) and EX (expire) options - atomic operation
        // This is available in Redis 2.6.12+
        $result = $this->redis->set($prefixedKey, $serialized, ['NX', 'EX' => $ttl]);
        return $result === true;
    }

    /**
     * Get Redis instance for advanced operations
     *
     * @return Redis
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }

    /**
     * Add prefix to cache key
     *
     * @param string $key
     * @return string
     */
    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }
}
