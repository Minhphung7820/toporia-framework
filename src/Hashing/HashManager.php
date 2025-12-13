<?php

declare(strict_types=1);

namespace Toporia\Framework\Hashing;

use Toporia\Framework\Hashing\Contracts\HasherInterface;

/**
 * Class HashManager
 *
 * Manages multiple hashing drivers with automatic fallback and driver caching.
 * Provides unified API for password hashing across different algorithms.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Hashing
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class HashManager
{
    /**
     * @var array<string, HasherInterface> Resolved hasher instances
     */
    private array $hashers = [];

    private string $defaultDriver;

    /**
     * @param array $config Hashing configuration
     */
    public function __construct(
        private array $config = []
    ) {
        $this->defaultDriver = $config['driver'] ?? 'bcrypt';
    }

    /**
     * Hash the given value using the default driver.
     *
     * Performance: O(1) but slow by design (50-250ms for security)
     *
     * @param string $value Plain text value
     * @param array $options Hashing options
     * @return string Hashed value
     */
    public function make(string $value, array $options = []): string
    {
        return $this->driver()->make($value, $options);
    }

    /**
     * Check the given plain value against a hash.
     *
     * Automatically detects algorithm from hash and uses correct driver.
     *
     * Performance: O(1) - constant time comparison (security)
     *
     * @param string $value Plain text value
     * @param string $hashedValue Hashed value
     * @param array $options Additional options
     * @return bool True if match, false otherwise
     */
    public function check(string $value, string $hashedValue, array $options = []): bool
    {
        // Auto-detect algorithm from hash
        $driver = $this->detectDriver($hashedValue);

        return $this->driver($driver)->check($value, $hashedValue, $options);
    }

    /**
     * Check if the given hash needs to be rehashed.
     *
     * Returns true if:
     * - Hash uses old algorithm
     * - Hash uses lower cost than current config
     * - Algorithm parameters changed
     *
     * Performance: O(1) - fast string parsing
     *
     * @param string $hashedValue Hashed value
     * @param array $options Current options
     * @return bool True if rehash needed
     */
    public function needsRehash(string $hashedValue, array $options = []): bool
    {
        return $this->driver()->needsRehash($hashedValue, $options);
    }

    /**
     * Get information about the given hashed value.
     *
     * Returns algorithm name, options (cost, memory, time), and metadata.
     *
     * Performance: O(1) - fast string parsing
     *
     * @param string $hashedValue Hashed value
     * @return array Hash information
     */
    public function info(string $hashedValue): array
    {
        $driver = $this->detectDriver($hashedValue);
        return $this->driver($driver)->info($hashedValue);
    }

    /**
     * Check if the given value is a valid hash.
     *
     * @param string $value Value to check
     * @return bool True if valid hash format
     */
    public function isHashed(string $value): bool
    {
        // Check if starts with hash identifier ($2y$, $argon2id$, etc.)
        return str_starts_with($value, '$2')
            || str_starts_with($value, '$argon2');
    }

    /**
     * Get hasher driver instance.
     *
     * Uses singleton pattern - driver instantiated only once.
     *
     * Performance: O(1) - array lookup
     *
     * @param string|null $name Driver name (null = default)
     * @return HasherInterface Hasher instance
     */
    public function driver(?string $name = null): HasherInterface
    {
        $name = $name ?? $this->defaultDriver;

        // Return cached instance if exists
        if (isset($this->hashers[$name])) {
            return $this->hashers[$name];
        }

        // Create and cache new driver
        $this->hashers[$name] = $this->createDriver($name);

        return $this->hashers[$name];
    }

    /**
     * Get default driver name.
     *
     * @return string Driver name
     */
    public function getDefaultDriver(): string
    {
        return $this->defaultDriver;
    }

    /**
     * Create hasher driver instance.
     *
     * Factory method with automatic fallback.
     *
     * @param string $name Driver name
     * @return HasherInterface Hasher instance
     * @throws \InvalidArgumentException If driver not supported
     */
    private function createDriver(string $name): HasherInterface
    {
        $drivers = $this->config['drivers'] ?? [];
        $driverConfig = $drivers[$name] ?? [];

        return match ($name) {
            'bcrypt' => $this->createBcryptDriver($driverConfig),
            'argon2id', 'argon2' => $this->createArgon2Driver($driverConfig),
            default => throw new \InvalidArgumentException(
                "Unsupported hashing driver: {$name}. " .
                    "Supported drivers: bcrypt, argon2id"
            )
        };
    }

    /**
     * Create Bcrypt driver.
     *
     * @param array $config Driver configuration
     * @return HasherInterface Bcrypt hasher
     */
    private function createBcryptDriver(array $config): HasherInterface
    {
        $cost = $config['cost'] ?? 12;

        return new BcryptHasher($cost);
    }

    /**
     * Create Argon2id driver with fallback.
     *
     * Automatically falls back to bcrypt if Argon2id not available.
     *
     * @param array $config Driver configuration
     * @return HasherInterface Argon2id or Bcrypt hasher
     */
    private function createArgon2Driver(array $config): HasherInterface
    {
        // Check if Argon2id is available
        if (!defined('PASSWORD_ARGON2ID')) {
            // Fallback to bcrypt
            error_log('Argon2id not available, falling back to bcrypt');
            return $this->createBcryptDriver(['cost' => 12]);
        }

        $memory = $config['memory'] ?? 65536; // 64 MB
        $time = $config['time'] ?? 4;
        $threads = $config['threads'] ?? 1;

        return new Argon2IdHasher($memory, $time, $threads);
    }

    /**
     * Detect hasher driver from hash string.
     *
     * Analyzes hash format to determine which algorithm was used.
     *
     * Performance: O(1) - string comparison
     *
     * @param string $hashedValue Hashed value
     * @return string Driver name
     */
    private function detectDriver(string $hashedValue): string
    {
        // Argon2id: $argon2id$...
        if (str_starts_with($hashedValue, '$argon2id$')) {
            return 'argon2id';
        }

        // Argon2i: $argon2i$...
        if (str_starts_with($hashedValue, '$argon2i$')) {
            return 'argon2id'; // Use same driver
        }

        // Bcrypt: $2y$... or $2a$... or $2b$...
        if (str_starts_with($hashedValue, '$2')) {
            return 'bcrypt';
        }

        // Default to bcrypt for unknown formats
        return 'bcrypt';
    }

    /**
     * Get all available drivers.
     *
     * @return array<string> Driver names
     */
    public function getAvailableDrivers(): array
    {
        $drivers = ['bcrypt'];

        if (defined('PASSWORD_ARGON2ID')) {
            $drivers[] = 'argon2id';
        }

        return $drivers;
    }
}
