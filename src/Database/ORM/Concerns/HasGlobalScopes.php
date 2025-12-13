<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

use Closure;
use Toporia\Framework\Database\Query\QueryBuilder;

/**
 * Trait HasGlobalScopes
 *
 * Provides Modern ORM global scope support for automatic query constraints.
 * Global scopes allow you to add constraints to all queries for a model.
 *
 * Common Use Cases:
 * - Soft deletes (automatically exclude deleted records)
 * - Multi-tenancy (automatically filter by tenant_id)
 * - Published content (automatically show only published items)
 * - Data segregation (automatically filter by user/team)
 *
 * Features:
 * - Register global scopes via addGlobalScope()
 * - Remove scopes temporarily via withoutGlobalScope() / withoutGlobalScopes()
 * - Closure-based scopes for inline definitions
 * - Class-based scopes for reusable logic
 *
 * Performance:
 * - O(N) where N = number of global scopes
 * - Scopes applied once per query
 * - Minimal overhead when no scopes registered
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\ORM\Concerns
 * @since       2025-01-22
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasGlobalScopes
{
    /**
     * Global scopes registry per model class.
     *
     * @var array<string, array<string, mixed>> Array of Closure or scope objects
     */
    protected static array $globalScopes = [];

    /**
     * Scopes that have been removed from the current query.
     *
     * @var array<string>
     */
    protected array $removedScopes = [];

    /**
     * Register a global scope with the model.
     *
     * Example with Closure:
     * ```php
     * class Post extends Model {
     *     protected static function booted() {
     *         static::addGlobalScope('published', function($query) {
     *             $query->where('published', true);
     *         });
     *     }
     * }
     * ```
     *
     * Example with Scope class:
     * ```php
     * class PublishedScope implements ScopeInterface {
     *     public function apply(QueryBuilder $query, Model $model) {
     *         $query->where('published', true);
     *     }
     * }
     *
     * static::addGlobalScope(new PublishedScope());
     * ```
     *
     * @param string|object $scope Scope name or instance
     * @param Closure|null $implementation Closure if scope is string name
     * @return void
     */
    public static function addGlobalScope(string|object $scope, ?Closure $implementation = null): void
    {
        $class = static::class;

        if (!isset(static::$globalScopes[$class])) {
            static::$globalScopes[$class] = [];
        }

        // If scope is a string, implementation must be a Closure
        if (is_string($scope)) {
            if ($implementation === null) {
                throw new \InvalidArgumentException('Global scope implementation required when scope name is string');
            }
            static::$globalScopes[$class][$scope] = $implementation;
        } else {
            // Scope is an object, use class name as key
            $scopeName = get_class($scope);
            static::$globalScopes[$class][$scopeName] = $scope;
        }
    }

    /**
     * Determine if a model has a global scope.
     *
     * @param string $scope Scope name or class name
     * @return bool
     */
    public static function hasGlobalScope(string $scope): bool
    {
        return isset(static::$globalScopes[static::class][$scope]);
    }

    /**
     * Get a global scope registered with the model.
     *
     * @param string $scope Scope name or class name
     * @return mixed Closure or scope object, or null
     */
    public static function getGlobalScope(string $scope): mixed
    {
        return static::$globalScopes[static::class][$scope] ?? null;
    }

    /**
     * Get all global scopes for the model.
     *
     * @return array<string, mixed> Array of Closure or scope objects
     */
    public static function getGlobalScopes(): array
    {
        return static::$globalScopes[static::class] ?? [];
    }

    /**
     * Remove all global scopes from the model.
     *
     * Useful for testing.
     *
     * @return void
     */
    public static function flushGlobalScopes(): void
    {
        unset(static::$globalScopes[static::class]);
    }

    /**
     * Remove a specific global scope from the model.
     *
     * @param string $name Scope name
     * @return void
     */
    public static function removeGlobalScope(string $name): void
    {
        $class = static::class;
        if (isset(static::$globalScopes[$class][$name])) {
            unset(static::$globalScopes[$class][$name]);
        }
    }

    /**
     * Remove a global scope from the current query.
     *
     * Example:
     * ```php
     * // Get all posts including unpublished
     * Post::withoutGlobalScope('published')->get();
     *
     * // Get soft-deleted records
     * User::withoutGlobalScope('soft_delete')->get();
     * ```
     *
     * @param string $scope Scope name or class name
     * @return $this
     */
    public function withoutGlobalScope(string $scope): static
    {
        $this->removedScopes[] = $scope;

        return $this;
    }

    /**
     * Remove multiple global scopes from the current query.
     *
     * Example:
     * ```php
     * // Remove specific scopes
     * Post::withoutGlobalScopes(['published', 'archived'])->get();
     *
     * // Remove all scopes
     * Post::withoutGlobalScopes()->get();
     * ```
     *
     * @param array<string>|null $scopes Scopes to remove, or null for all
     * @return $this
     */
    public function withoutGlobalScopes(?array $scopes = null): static
    {
        if ($scopes === null) {
            // Remove all scopes
            $this->removedScopes = array_keys(static::getGlobalScopes());
        } else {
            // Remove specific scopes
            $this->removedScopes = array_merge($this->removedScopes, $scopes);
        }

        return $this;
    }

    /**
     * Apply global scopes to the query builder.
     *
     * This method is called automatically when creating a query.
     *
     * @param QueryBuilder $query
     * @return void
     */
    protected function applyGlobalScopes(QueryBuilder $query): void
    {
        $scopes = static::getGlobalScopes();

        foreach ($scopes as $name => $scope) {
            // Skip removed scopes
            if (in_array($name, $this->removedScopes)) {
                continue;
            }

            if ($scope instanceof Closure) {
                // Closure-based scope
                $scope($query);
            } elseif (is_object($scope) && method_exists($scope, 'apply')) {
                // Object-based scope with apply() method
                $scope->apply($query, $this);
            }
        }
    }

    /**
     * Get a new query builder with global scopes applied.
     *
     * Override in Model to integrate with query() method.
     *
     * @return QueryBuilder
     */
    protected function newQueryWithScopes(): QueryBuilder
    {
        $query = static::query();
        $this->applyGlobalScopes($query);

        return $query;
    }

    /**
     * Create a new model instance with scopes applied.
     *
     * @return static
     */
    public static function withGlobalScopes(): static
    {
        return new static();
    }

    /**
     * Boot method to register global scopes.
     *
     * Override in child models to register scopes.
     *
     * Example:
     * ```php
     * class Post extends Model {
     *     protected static function booted() {
     *         static::addGlobalScope('published', function($query) {
     *             $query->where('published', true);
     *         });
     *     }
     * }
     * ```
     *
     * @return void
     */
    protected static function booted(): void
    {
        // Override in child classes to register global scopes
    }
}
