<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

use Toporia\Framework\Database\Query\QueryBuilder;


/**
 * Trait HasQueryScopes
 *
 * Trait providing reusable functionality for HasQueryScopes in the
 * Concerns layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasQueryScopes
{
    /**
     * Local scopes registered for this model.
     *
     * @var array<string, callable>
     */
    protected static array $localScopes = [];

    /**
     * PERFORMANCE FIX: Cache Reflection instances to avoid overhead.
     *
     * @var array<string, \ReflectionClass>
     */
    private static array $reflectionCache = [];

    /**
     * Boot the query scopes trait.
     *
     * This is called automatically when the model is first used.
     * We use lazy initialization to discover scopes on first access.
     *
     * @return void
     */
    protected static function bootHasQueryScopes(): void
    {
        // Auto-discover local scopes (methods starting with "scope")
        static::discoverLocalScopes();
    }

    /**
     * Ensure scopes are discovered before use.
     * Called lazily when scopes are accessed.
     *
     * @return void
     */
    protected static function ensureScopesDiscovered(): void
    {
        static $discovered = [];
        $class = static::class;

        if (!isset($discovered[$class])) {
            static::discoverLocalScopes();
            $discovered[$class] = true;
        }
    }

    /**
     * Discover local scopes from model methods.
     *
     * Methods starting with "scope" are automatically registered as local scopes.
     * Example: scopeActive() becomes ->active()
     *
     * Performance: O(n) where n = number of methods (runs once per class)
     * PERFORMANCE FIX: Cache Reflection instance to avoid repeated creation
     *
     * @return void
     */
    protected static function discoverLocalScopes(): void
    {
        // PERFORMANCE FIX: Use cached Reflection instance
        $reflection = static::getReflectionClass(static::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED);

        foreach ($methods as $method) {
            $methodName = $method->getName();

            // Check if method starts with "scope" and has at least one parameter
            if (str_starts_with($methodName, 'scope') && strlen($methodName) > 5) {
                $scopeName = lcfirst(substr($methodName, 5)); // Remove "scope" prefix

                // Register scope
                static::$localScopes[$scopeName] = function (QueryBuilder $query, ...$args) use ($methodName) {
                    return static::{$methodName}($query, ...$args);
                };
            }
        }
    }

    /**
     * PERFORMANCE FIX: Get cached Reflection instance.
     *
     * @param string $class Class name
     * @return \ReflectionClass
     */
    private static function getReflectionClass(string $class): \ReflectionClass
    {
        if (!isset(self::$reflectionCache[$class])) {
            self::$reflectionCache[$class] = new \ReflectionClass($class);
        }
        return self::$reflectionCache[$class];
    }

    /**
     * Add a global scope (delegates to HasGlobalScopes trait).
     *
     * Note: This matches Model's signature from HasGlobalScopes trait.
     * Model already includes HasGlobalScopes which handles global scopes.
     * We access the trait's static property directly to ensure correct class context.
     * PERFORMANCE FIX: Cache Reflection instance
     *
     * @param string|object $scope Scope name or scope object
     * @param \Closure|null $implementation Closure if scope is string name
     * @return void
     */
    public static function addGlobalScope(string|object $scope, ?\Closure $implementation = null): void
    {
        // PERFORMANCE FIX: Use cached Reflection instance
        $reflection = static::getReflectionClass('Toporia\Framework\Database\ORM\Concerns\HasGlobalScopes');
        $property = $reflection->getProperty('globalScopes');
        $property->setAccessible(true);
        $globalScopes = $property->getValue(null) ?? [];

        $class = static::class;
        if (!isset($globalScopes[$class])) {
            $globalScopes[$class] = [];
        }

        if (is_string($scope)) {
            if ($implementation === null) {
                throw new \InvalidArgumentException('Global scope implementation required when scope name is string');
            }
            $globalScopes[$class][$scope] = $implementation;
        } else {
            $scopeName = get_class($scope);
            $globalScopes[$class][$scopeName] = $scope;
        }

        $property->setValue(null, $globalScopes);
    }

    /**
     * Get all global scopes (delegates to HasGlobalScopes trait).
     * PERFORMANCE FIX: Cache Reflection instance
     *
     * @return array<string, callable>
     */
    public static function getGlobalScopes(): array
    {
        // PERFORMANCE FIX: Use cached Reflection instance
        $reflection = static::getReflectionClass('Toporia\Framework\Database\ORM\Concerns\HasGlobalScopes');
        $property = $reflection->getProperty('globalScopes');
        $property->setAccessible(true);
        $globalScopes = $property->getValue(null) ?? [];
        return $globalScopes[static::class] ?? [];
    }

    /**
     * Check if a global scope exists (delegates to HasGlobalScopes trait).
     * PERFORMANCE FIX: Cache Reflection instance
     *
     * @param string $name Scope name
     * @return bool
     */
    public static function hasGlobalScope(string $name): bool
    {
        // PERFORMANCE FIX: Use cached Reflection instance
        $reflection = static::getReflectionClass('Toporia\Framework\Database\ORM\Concerns\HasGlobalScopes');
        $property = $reflection->getProperty('globalScopes');
        $property->setAccessible(true);
        $globalScopes = $property->getValue(null) ?? [];
        return isset($globalScopes[static::class][$name]);
    }

    /**
     * Remove a global scope (delegates to HasGlobalScopes trait).
     * PERFORMANCE FIX: Cache Reflection instance
     *
     * @param string $name Scope name
     * @return void
     */
    public static function removeGlobalScope(string $name): void
    {
        // PERFORMANCE FIX: Use cached Reflection instance
        $reflection = static::getReflectionClass('Toporia\Framework\Database\ORM\Concerns\HasGlobalScopes');
        $property = $reflection->getProperty('globalScopes');
        $property->setAccessible(true);
        $globalScopes = $property->getValue(null) ?? [];
        $class = static::class;
        if (isset($globalScopes[$class][$name])) {
            unset($globalScopes[$class][$name]);
            $property->setValue(null, $globalScopes);
        }
    }

    /**
     * Add a local scope.
     *
     * Local scopes are applied when explicitly called.
     *
     * @param string $name Scope name
     * @param callable $callback Scope callback
     * @return void
     *
     * @example
     * ```php
     * static::addLocalScope('published', function (QueryBuilder $query) {
     *     return $query->where('published_at', '<=', now());
     * });
     * ```
     */
    public static function addLocalScope(string $name, callable $callback): void
    {
        static::$localScopes[$name] = $callback;
    }

    /**
     * Get all local scopes.
     *
     * @return array<string, callable>
     */
    public static function getLocalScopes(): array
    {
        static::ensureScopesDiscovered();
        return static::$localScopes;
    }

    /**
     * Check if a local scope exists.
     *
     * @param string $name Scope name
     * @return bool
     */
    public static function hasLocalScope(string $name): bool
    {
        static::ensureScopesDiscovered();
        return isset(static::$localScopes[$name]);
    }


    /**
     * Apply a local scope to a query.
     *
     * @param QueryBuilder $query Query builder instance
     * @param string $name Scope name
     * @param mixed ...$args Scope arguments
     * @return QueryBuilder
     *
     * @throws \InvalidArgumentException If scope doesn't exist
     */
    public static function applyLocalScope(QueryBuilder $query, string $name, mixed ...$args): QueryBuilder
    {
        static::ensureScopesDiscovered();

        if (!isset(static::$localScopes[$name])) {
            throw new \InvalidArgumentException("Local scope '{$name}' does not exist on " . static::class);
        }

        $scope = static::$localScopes[$name];
        $result = $scope($query, ...$args);

        // Ensure we return QueryBuilder (scope might return null)
        return $result ?? $query;
    }

    /**
     * Apply global scopes to a query (static helper for tests).
     *
     * Note: Model has protected instance method applyGlobalScopes() from HasGlobalScopes trait.
     * This static method is a helper for tests that need to apply global scopes manually.
     *
     * @param QueryBuilder $query Query builder instance
     * @return QueryBuilder
     */
    public static function applyQueryGlobalScopes(QueryBuilder $query): QueryBuilder
    {
        // Get global scopes from HasGlobalScopes trait if available
        if (method_exists(static::class, 'getGlobalScopes')) {
            $scopes = static::getGlobalScopes();
            foreach ($scopes as $name => $scope) {
                if ($scope instanceof \Closure) {
                    $scope($query);
                }
            }
        }

        return $query;
    }
}
