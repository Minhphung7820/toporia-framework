<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Session\Store;

/**
 * Class Session
 *
 * Session Accessor (Facade) - Static accessor for session store providing convenient API.
 * Enables static method calls for session operations.
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
 * Usage:
 * ```php
 * use Toporia\Framework\Support\Accessors\Session;
 *
 * // Get session value
 * $value = Session::get('key');
 *
 * // Set session value
 * Session::set('key', 'value');
 *
 * // Check if exists
 * if (Session::has('key')) {
 *     // ...
 * }
 *
 * // Remove value
 * Session::remove('key');
 *
 * // Regenerate session ID
 * Session::regenerate();
 * ```
 *
 * Performance:
 * - O(1) instance resolution (cached after first call)
 * - Zero overhead method forwarding
 * - Direct delegation to Store methods
 *
 * Clean Architecture:
 * - ServiceAccessor pattern for dependency injection
 * - Wrapper for Store instance
 *
 * SOLID Principles:
 * - Single Responsibility: Only forwards calls to Store
 * - Open/Closed: Extend via Store, don't modify accessor
 * - Liskov Substitution: Behaves like Store
 * - Interface Segregation: Provides specific session interface
 * - Dependency Inversion: Depends on container abstraction
 *
 * @method static bool start()
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 * @method static bool has(string $key)
 * @method static void remove(string $key)
 * @method static array all()
 * @method static void flush()
 * @method static bool regenerate(bool $deleteOldSession = false)
 * @method static string getId()
 * @method static void setId(string $id)
 * @method static string getName()
 * @method static bool save()
 * @method static mixed getFlash(?string $key = null, mixed $default = null)
 * @method static void setFlash(string|array $key, mixed $value = null)
 * @method static bool hasFlash(?string $key = null)
 * @method static void removeFlash(?string $key = null)
 * @method static mixed getOldInput(?string $key = null, mixed $default = null)
 * @method static void setOldInput(array $input)
 * @method static bool hasOldInput(?string $key = null)
 * @method static void removeOldInput()
 * @method static array getMultiple(array $keys, mixed $default = null)
 * @method static void setMultiple(array $values)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static bool isStarted()
 */
final class Session extends ServiceAccessor
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
        return 'session';
    }
}
