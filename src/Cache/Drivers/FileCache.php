<?php

declare(strict_types=1);

namespace Toporia\Framework\Cache\Drivers;

use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class FileCache
 *
 * Stores cache entries as serialized files in the filesystem.
 * Good for simple deployments without external dependencies.
 *
 * Performance:
 * - O(1) get/set operations (file I/O)
 * - O(N) clear where N = number of cache files
 * - File locking for thread safety
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles file-based caching
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
final class FileCache implements CacheInterface
{
    private string $directory;

    /**
     * Create a new FileCache instance.
     *
     * @param string|null $directory Cache directory path. If null, uses system temp directory.
     */
    public function __construct(?string $directory = null)
    {
        // Use system temp directory as fallback instead of hardcoded /tmp
        $this->directory = rtrim($directory ?? sys_get_temp_dir() . '/toporia_cache', '/');
        $this->ensureDirectoryExists();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return $default;
        }

        // SECURITY: Restrict unserialize to prevent PHP Object Injection attacks
        $data = unserialize(file_get_contents($file), ['allowed_classes' => false]);

        // Check if expired
        if ($data['expires_at'] !== null && $data['expires_at'] < now()->getTimestamp()) {
            $this->delete($key);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $file = $this->getFilePath($key);
        $expiresAt = $ttl !== null ? now()->getTimestamp() + $ttl : null;

        $data = [
            'value' => $value,
            'expires_at' => $expiresAt,
        ];

        $result = file_put_contents($file, serialize($data), LOCK_EX);
        return $result !== false;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            return unlink($file);
        }

        return true;
    }

    public function clear(): bool
    {
        $files = glob($this->directory . '/*');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

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
        $success = true;

        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
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
     * Uses exclusive file locking (LOCK_EX) with O_EXCL flag for atomic operation.
     * This prevents race conditions when multiple processes try to acquire the same lock.
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $file = $this->getFilePath($key);

        // Check if file exists and is not expired
        if (file_exists($file)) {
            // SECURITY: Restrict unserialize to prevent PHP Object Injection attacks
            $data = @unserialize(file_get_contents($file), ['allowed_classes' => false]);
            if ($data !== false) {
                // If no expiration or not expired, key exists
                if ($data['expires_at'] === null || $data['expires_at'] >= now()->getTimestamp()) {
                    return false; // Key already exists
                }
                // Key expired, delete it
                @unlink($file);
            }
        }

        // Try to create file exclusively (atomic operation)
        $expiresAt = $ttl !== null ? now()->getTimestamp() + $ttl : null;
        $data = [
            'value' => $value,
            'expires_at' => $expiresAt,
        ];

        // Use file locking with exclusive flag
        $fp = @fopen($file, 'x'); // 'x' = exclusive create, fails if file exists
        if ($fp === false) {
            return false; // File was created by another process
        }

        flock($fp, LOCK_EX);
        fwrite($fp, serialize($data));
        flock($fp, LOCK_UN);
        fclose($fp);

        return true;
    }

    /**
     * Get the file path for a cache key
     *
     * @param string $key
     * @return string
     */
    private function getFilePath(string $key): string
    {
        $hash = md5($key);
        return $this->directory . '/' . $hash . '.cache';
    }

    /**
     * Ensure the cache directory exists
     *
     * @return void
     */
    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }
}

