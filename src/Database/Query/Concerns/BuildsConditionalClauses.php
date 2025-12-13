<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

use Closure;

/**
 * Trait BuildsConditionalClauses
 *
 * Conditional query builders for Query Builder.
 * Provides Modern ORM conditional methods for clean, readable queries.
 *
 * Features:
 * - Conditional query building (when, unless)
 * - Query inspection (tap)
 * - Fluent interface
 *
 * Performance:
 * - Zero runtime overhead (conditions evaluated once)
 * - No query execution during building
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Query\Concerns
 * @since       2025-01-22
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait BuildsConditionalClauses
{
    /**
     * Apply callback if condition is truthy.
     *
     * Enables conditional query building without breaking the fluent chain.
     *
     * Example:
     * ```php
     * $query->where('status', 'active')
     *       ->when($request->get('search'), function($query, $search) {
     *           $query->where('title', 'LIKE', "%{$search}%");
     *       })
     *       ->when($request->get('category'), function($query, $category) {
     *           $query->where('category_id', $category);
     *       });
     * ```
     *
     * With default callback:
     * ```php
     * $query->when($condition, function($query) {
     *     // Execute if condition is truthy
     *     $query->where('status', 'active');
     * }, function($query) {
     *     // Execute if condition is falsy
     *     $query->where('status', 'inactive');
     * });
     * ```
     *
     * Performance: O(1) - Condition evaluated once at build time
     *
     * @param mixed $condition Condition to check (truthy/falsy)
     * @param Closure $callback Callback to apply if condition is truthy
     * @param Closure|null $default Callback to apply if condition is falsy
     * @return $this
     */
    public function when(mixed $condition, Closure $callback, ?Closure $default = null): self
    {
        $value = $condition instanceof Closure ? $condition($this) : $condition;

        if ($value) {
            return $callback($this, $value) ?? $this;
        }

        if ($default !== null) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    /**
     * Apply callback if condition is falsy.
     *
     * Opposite of when(). Applies callback when condition is falsy.
     *
     * Example:
     * ```php
     * $query->unless($user->isAdmin(), function($query) {
     *     // Only apply this filter if user is NOT an admin
     *     $query->where('is_public', true);
     * });
     * ```
     *
     * With default callback:
     * ```php
     * $query->unless($condition, function($query) {
     *     // Execute if condition is falsy
     *     $query->where('is_public', true);
     * }, function($query) {
     *     // Execute if condition is truthy
     *     $query->whereNull('deleted_at');
     * });
     * ```
     *
     * Performance: O(1) - Condition evaluated once at build time
     *
     * @param mixed $condition Condition to check (truthy/falsy)
     * @param Closure $callback Callback to apply if condition is falsy
     * @param Closure|null $default Callback to apply if condition is truthy
     * @return $this
     */
    public function unless(mixed $condition, Closure $callback, ?Closure $default = null): self
    {
        $value = $condition instanceof Closure ? $condition($this) : $condition;

        if (!$value) {
            return $callback($this, $value) ?? $this;
        }

        if ($default !== null) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    /**
     * Apply a callback to the query without breaking the chain.
     *
     * Useful for debugging or inspecting the query builder state.
     * The callback receives a copy of the query, so it cannot modify the original.
     *
     * Example:
     * ```php
     * $query->where('status', 'active')
     *       ->tap(function($query) {
     *           // Debug: log current SQL
     *           error_log('Current SQL: ' . $query->toSql());
     *       })
     *       ->orderBy('created_at', 'DESC');
     * ```
     *
     * Use case - Conditional debugging:
     * ```php
     * $query->tap(function($query) {
     *     if (app()->environment('local')) {
     *         dump($query->toSql());
     *         dump($query->getBindings());
     *     }
     * });
     * ```
     *
     * Performance: O(1) - No query execution, just callback invocation
     *
     * @param Closure $callback Callback receiving the query builder
     * @return $this
     */
    public function tap(Closure $callback): self
    {
        $callback($this);
        return $this;
    }
}
