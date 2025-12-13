<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

use RuntimeException;
use Toporia\Framework\Container\Contracts\ContainerInterface;


/**
 * Abstract Class ServiceAccessor
 *
 * Abstract base class for ServiceAccessor implementations in the
 * Application foundation and bootstrapping layer providing common
 * functionality and contracts.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Foundation
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
abstract class ServiceAccessor
{
    /**
     * Container instance.
     */
    private static ?ContainerInterface $container = null;

    /**
     * Resolved service instances (per accessor class).
     *
     * @var array<string, object>
     */
    private static array $resolvedInstances = [];

    /**
     * Set the container instance.
     *
     * This should be called once during application bootstrap.
     *
     * @param ContainerInterface $container
     * @return void
     */
    public static function setContainer(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    /**
     * Get the container instance.
     *
     * @return ContainerInterface
     * @throws RuntimeException If container not set
     */
    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException(
                'Container not set. Call ServiceAccessor::setContainer() during bootstrap.'
            );
        }

        return self::$container;
    }

    /**
     * Get the service accessor name (container binding key).
     *
     * Each concrete accessor must implement this to specify which service it accesses.
     *
     * @return string Service name in container (e.g., 'cache', 'events', 'db')
     */
    abstract protected static function getServiceName(): string;

    /**
     * Get the underlying service instance from container.
     *
     * Resolves once and caches the instance per request.
     *
     * Performance: O(1) lookup after first resolution (cached)
     *
     * @return object Service instance
     * @throws RuntimeException If service not found in container
     */
    protected static function resolveService(): object
    {
        $accessorClass = static::class;

        // Fast path: Return cached instance if already resolved (O(1))
        if (isset(self::$resolvedInstances[$accessorClass])) {
            return self::$resolvedInstances[$accessorClass];
        }

        // Slow path: Resolve from container (only happens once per accessor)
        $container = self::getContainer();
        $serviceName = static::getServiceName();

        if (!$container->has($serviceName)) {
            throw new RuntimeException(
                "Service '{$serviceName}' not found in container. "
                . "Register it in a ServiceProvider first."
            );
        }

        // Resolve and cache for future calls
        $instance = $container->get($serviceName);
        self::$resolvedInstances[$accessorClass] = $instance;

        return $instance;
    }

    /**
     * Get the underlying service instance (alias for resolveService).
     *
     * Provides backward compatibility and cleaner API for child classes.
     *
     * Performance: O(1) - delegates to resolveService() with caching
     *
     * @return object Service instance
     */
    protected static function getService(): object
    {
        return static::resolveService();
    }

    /**
     * Handle dynamic static method calls.
     *
     * Forwards all static method calls to the underlying service instance.
     * This enables Accessor behavior where you only need to
     * implement getServiceName() and all methods are automatically delegated.
     *
     * Performance:
     * - O(1) instance lookup (cached)
     * - Direct method call forwarding (no overhead)
     * - No method_exists check - let PHP handle naturally for best performance
     *
     * @param string $method Method name
     * @param array<mixed> $arguments Method arguments
     * @return mixed Method result
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return static::resolveService()->$method(...$arguments);
    }

    /**
     * Get the underlying service instance directly.
     *
     * Useful when you need the actual instance (e.g., for type hints, instanceof checks).
     *
     * Performance: O(1) - uses cached instance
     *
     * @return object Service instance
     *
     * @example
     * $cache = Cache::getInstance();
     * if ($cache instanceof RedisCache) {
     *     $cache->someRedisSpecificMethod();
     * }
     */
    public static function getInstance(): object
    {
        return static::resolveService();
    }

    /**
     * Get the service name this accessor is bound to.
     *
     * Exposes the protected method for debugging/introspection.
     *
     * @return string Service name in container
     */
    public static function getFacadeAccessor(): string
    {
        return static::getServiceName();
    }

    /**
     * Clear all resolved instances across all accessors.
     *
     * Useful for testing - forces re-resolution from container.
     *
     * Performance: O(1) - just resets array
     *
     * @return void
     *
     * @example
     * // Between tests:
     * ServiceAccessor::clearResolvedInstances();
     */
    public static function clearResolvedInstances(): void
    {
        self::$resolvedInstances = [];
    }

    /**
     * Clear resolved instance for this specific accessor only.
     *
     * More granular than clearResolvedInstances() - only affects one accessor.
     *
     * Performance: O(1)
     *
     * @return void
     */
    public static function clearResolved(): void
    {
        unset(self::$resolvedInstances[static::class]);
    }

    /**
     * Swap the underlying service implementation.
     *
     * Useful for testing - temporarily replace service with mock/stub.
     *
     * Performance: O(1) - direct array assignment
     *
     * @param object $mock Mock/stub instance
     * @return void
     *
     * @example
     * // In tests:
     * $mockCache = new MemoryCache();
     * Cache::swap($mockCache);
     * Cache::set('key', 'value'); // Uses mock
     *
     * // Cleanup after test:
     * Cache::clearResolved();
     */
    public static function swap(object $mock): void
    {
        self::$resolvedInstances[static::class] = $mock;
    }

    /**
     * Check if service is currently resolved.
     *
     * Performance: O(1) - array key check
     *
     * @return bool True if resolved and cached
     */
    public static function isResolved(): bool
    {
        return isset(self::$resolvedInstances[static::class]);
    }

    /**
     * Get count of resolved accessor instances.
     *
     * Useful for debugging memory usage and performance monitoring.
     *
     * @return int Number of resolved accessors
     */
    public static function getResolvedCount(): int
    {
        return count(self::$resolvedInstances);
    }
}
