<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

use Closure;

/**
 * Trait BuildsSubqueries
 *
 * Subquery builders for Query Builder.
 * Provides Modern ORM subquery methods with performance optimization.
 *
 * Features:
 * - WHERE IN/NOT IN with subqueries
 * - WHERE EXISTS/NOT EXISTS
 * - SELECT subqueries
 * - FROM subqueries
 *
 * Performance:
 * - Database-optimized subqueries
 * - Zero-copy SQL building
 * - Proper query plan optimization
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
trait BuildsSubqueries
{
    /**
     * Add a WHERE EXISTS clause.
     *
     * Checks if the subquery returns any rows.
     *
     * Example:
     * ```php
     * $query->whereExists(function($query) {
     *     $query->table('orders')
     *           ->whereColumn('orders.user_id', 'users.id');
     * });
     * // WHERE EXISTS (SELECT * FROM orders WHERE orders.user_id = users.id)
     * ```
     *
     * Performance: O(1) - Single subquery addition, database optimizes execution
     *
     * @param Closure $callback Callback receiving a QueryBuilder instance
     * @return $this
     */
    public function whereExists(Closure $callback): self
    {
        $query = $this->newQuery();
        $callback($query);

        $this->wheres[] = [
            'type' => 'Exists',
            'query' => $query,
            'boolean' => 'AND',
        ];

        // Merge bindings from subquery
        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * Add an OR WHERE EXISTS clause.
     *
     * @param Closure $callback Callback receiving a QueryBuilder instance
     * @return $this
     */
    public function orWhereExists(Closure $callback): self
    {
        $query = $this->newQuery();
        $callback($query);

        $this->wheres[] = [
            'type' => 'Exists',
            'query' => $query,
            'boolean' => 'OR',
        ];

        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * Add a WHERE NOT EXISTS clause.
     *
     * Example:
     * ```php
     * $query->whereNotExists(function($query) {
     *     $query->table('orders')
     *           ->whereColumn('orders.user_id', 'users.id');
     * });
     * // WHERE NOT EXISTS (SELECT * FROM orders WHERE orders.user_id = users.id)
     * ```
     *
     * @param Closure $callback Callback receiving a QueryBuilder instance
     * @return $this
     */
    public function whereNotExists(Closure $callback): self
    {
        $query = $this->newQuery();
        $callback($query);

        $this->wheres[] = [
            'type' => 'NotExists',
            'query' => $query,
            'boolean' => 'AND',
        ];

        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * Add an OR WHERE NOT EXISTS clause.
     *
     * @param Closure $callback Callback receiving a QueryBuilder instance
     * @return $this
     */
    public function orWhereNotExists(Closure $callback): self
    {
        $query = $this->newQuery();
        $callback($query);

        $this->wheres[] = [
            'type' => 'NotExists',
            'query' => $query,
            'boolean' => 'OR',
        ];

        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * Add a WHERE IN subquery clause.
     *
     * Extends whereIn() to support subqueries.
     *
     * Example:
     * ```php
     * $query->whereInSub('user_id', function($query) {
     *     $query->table('active_users')->select('id');
     * });
     * // WHERE user_id IN (SELECT id FROM active_users)
     * ```
     *
     * Performance: Database optimizes IN subquery, often using join internally
     *
     * @param string $column Column to check
     * @param Closure $callback Subquery builder callback
     * @return $this
     */
    public function whereInSub(string $column, Closure $callback): self
    {
        $query = $this->newQuery();
        $callback($query);

        $this->wheres[] = [
            'type' => 'InSub',
            'column' => $column,
            'query' => $query,
            'boolean' => 'AND',
        ];

        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * Add an OR WHERE IN subquery clause.
     *
     * @param string $column Column to check
     * @param Closure $callback Subquery builder callback
     * @return $this
     */
    public function orWhereInSub(string $column, Closure $callback): self
    {
        $query = $this->newQuery();
        $callback($query);

        $this->wheres[] = [
            'type' => 'InSub',
            'column' => $column,
            'query' => $query,
            'boolean' => 'OR',
        ];

        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * Add a WHERE NOT IN subquery clause.
     *
     * Example:
     * ```php
     * $query->whereNotInSub('user_id', function($query) {
     *     $query->table('banned_users')->select('id');
     * });
     * // WHERE user_id NOT IN (SELECT id FROM banned_users)
     * ```
     *
     * @param string $column Column to check
     * @param Closure $callback Subquery builder callback
     * @return $this
     */
    public function whereNotInSub(string $column, Closure $callback): self
    {
        $query = $this->newQuery();
        $callback($query);

        $this->wheres[] = [
            'type' => 'NotInSub',
            'column' => $column,
            'query' => $query,
            'boolean' => 'AND',
        ];

        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * Add an OR WHERE NOT IN subquery clause.
     *
     * @param string $column Column to check
     * @param Closure $callback Subquery builder callback
     * @return $this
     */
    public function orWhereNotInSub(string $column, Closure $callback): self
    {
        $query = $this->newQuery();
        $callback($query);

        $this->wheres[] = [
            'type' => 'NotInSub',
            'column' => $column,
            'query' => $query,
            'boolean' => 'OR',
        ];

        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * Add a subquery to the SELECT clause.
     *
     * Example:
     * ```php
     * $query->selectSub(function($query) {
     *     $query->table('orders')
     *           ->selectRaw('COUNT(*)')
     *           ->whereColumn('orders.user_id', 'users.id');
     * }, 'orders_count');
     * // SELECT (SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id) AS orders_count
     * ```
     *
     * @param Closure $callback Subquery builder callback
     * @param string $as Alias for the subquery result
     * @return $this
     */
    public function selectSub(Closure $callback, string $as): self
    {
        $query = $this->newQuery();
        $callback($query);

        // Get subquery SQL and remove initial columns to avoid *, COUNT(*) issue
        $sql = '(' . $query->toSql() . ') AS ' . $as;

        // If this is the first select, clear the default '*'
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $this->columns[] = $sql;

        // Merge bindings
        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * Use a subquery as the FROM clause.
     *
     * Example:
     * ```php
     * $query->fromSub(function($query) {
     *     $query->table('orders')
     *           ->select(['user_id', 'total'])
     *           ->where('status', 'completed');
     * }, 'completed_orders');
     * // FROM (SELECT user_id, total FROM orders WHERE status = 'completed') AS completed_orders
     * ```
     *
     * @param Closure $callback Subquery builder callback
     * @param string $as Alias for the subquery table
     * @return $this
     */
    public function fromSub(Closure $callback, string $as): self
    {
        $query = $this->newQuery();
        $callback($query);

        // Set table to subquery
        $this->table = '(' . $query->toSql() . ') AS ' . $as;

        // Merge bindings (prepend to maintain order)
        $this->bindings = array_merge($query->getBindings(), $this->bindings);

        return $this;
    }
}
