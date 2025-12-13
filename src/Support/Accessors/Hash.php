<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Hashing\HashManager;
use Toporia\Framework\Hashing\Contracts\HasherInterface;

/**
 * Class Hash
 *
 * Hash Service Accessor - Provides static-like access to the hash manager.
 * All methods are automatically delegated to the underlying service via __callStatic().
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @method static string make(string $value, array $options = []) Hash a value
 * @method static bool check(string $value, string $hashedValue, array $options = []) Verify a value against a hash
 * @method static bool needsRehash(string $hashedValue, array $options = []) Check if hash needs rehashing
 * @method static array info(string $hashedValue) Get hash information
 * @method static bool isHashed(string $value) Check if value is already hashed
 * @method static HasherInterface driver(?string $name = null) Get specific hasher driver
 * @method static string getDefaultDriver() Get default driver name
 * @method static array getAvailableDrivers() Get available drivers
 *
 * @see HashManager
 *
 * @example
 * // Hash password
 * $hash = Hash::make('secret');
 *
 * // Verify password
 * if (Hash::check('secret', $hash)) {
 *     // Password correct
 * }
 *
 * // Check if needs rehash
 * if (Hash::needsRehash($hash)) {
 *     $newHash = Hash::make('secret');
 * }
 */
final class Hash extends ServiceAccessor
{
    /**
     * Get the service name for this accessor.
     *
     * This is the only method needed - all other methods are automatically
     * delegated to the underlying service via __callStatic().
     *
     * @return string Service name in container
     */
    protected static function getServiceName(): string
    {
        return 'hash';
    }
}
