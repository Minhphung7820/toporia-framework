<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Cache\CacheManager;

/**
 * Class Cache
 *
 * Cache Service Accessor - Provides static-like access to the cache service.
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
 * @method static mixed get(string $key, mixed $default = null) Get value from cache
 * @method static bool set(string $key, mixed $value, int|\DateInterval|null $ttl = null) Store value in cache
 * @method static bool has(string $key) Check if key exists in cache
 * @method static bool delete(string $key) Delete value from cache
 * @method static bool clear() Clear all cache
 * @method static mixed remember(string $key, int|\DateInterval|null $ttl, \Closure $callback) Get or store value
 * @method static bool forever(string $key, mixed $value) Store value forever
 * @method static int increment(string $key, int $value = 1) Increment value
 * @method static int decrement(string $key, int $value = 1) Decrement value
 * @method static array getMultiple(iterable $keys, mixed $default = null) Get multiple values
 * @method static bool setMultiple(iterable $values, int|\DateInterval|null $ttl = null) Set multiple values
 * @method static CacheManager driver(?string $name = null) Get specific cache driver
 *
 * @see CacheManager
 *
 * @example
 * // Get from cache
 * $value = Cache::get('key', 'default');
 *
 * // Set to cache
 * Cache::set('key', 'value', 3600);
 *
 * // Remember pattern
 * $users = Cache::remember('users', 3600, fn() => User::all());
 *
 * // Use specific driver
 * Cache::driver('redis')->set('key', 'value');
 */
final class Cache extends ServiceAccessor
{
    protected static function getServiceName(): string
    {
        return 'cache';
    }
}
