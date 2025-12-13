<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Routing\Contracts\RouteInterface;
use Toporia\Framework\Routing\Contracts\RouterInterface;

/**
 * Class Route
 *
 * Route Accessor (Facade) - Static accessor for Router service providing fluent route registration.
 * Enables static method calls for route definitions.
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
 * use Toporia\Framework\Support\Accessors\Route;
 *
 * // Basic routes
 * Route::get('/', [HomeController::class, 'index']);
 * Route::post('/users', [UserController::class, 'store']);
 *
 * // Route groups
 * Route::group(['prefix' => 'api', 'middleware' => 'auth'], function () {
 *     Route::get('/users', [UserController::class, 'index']);
 * });
 *
 * // SPA fallback route (catch-all)
 * Route::any('/{any}', [SpaController::class, 'index'])->where('any', '.*');
 * ```
 *
 * Performance:
 * - O(1) instance resolution (cached after first call)
 * - Zero overhead method forwarding
 * - Direct delegation to Router instance
 *
 * Clean Architecture:
 * - ServiceAccessor pattern for dependency injection
 * - Static interface for route definitions
 * - Router instance resolved from container
 *
 * @method static RouteInterface get(string $path, mixed $handler, array $middleware = [])
 * @method static RouteInterface post(string $path, mixed $handler, array $middleware = [])
 * @method static RouteInterface put(string $path, mixed $handler, array $middleware = [])
 * @method static RouteInterface patch(string $path, mixed $handler, array $middleware = [])
 * @method static RouteInterface delete(string $path, mixed $handler, array $middleware = [])
 * @method static RouteInterface any(string $path, mixed $handler, array $middleware = [])
 * @method static self fallback(mixed $handler)
 * @method static void group(array $attributes, callable $callback)
 */
final class Route extends ServiceAccessor
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
        return 'router';
    }
}
