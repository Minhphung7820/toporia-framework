<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

use Closure;

/**
 * Trait BuildsUnions
 *
 * UNION query builders for Query Builder.
 * Provides Modern ORM UNION and UNION ALL operations.
 *
 * Features:
 * - UNION (distinct results)
 * - UNION ALL (all results including duplicates)
 * - Multiple unions support
 * - Proper binding merge
 *
 * Performance:
 * - Database-optimized unions
 * - Efficient duplicate elimination (UNION)
 * - Fast concatenation (UNION ALL)
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
trait BuildsUnions
{
    /**
     * Union queries.
     *
     * @var array<int, array{query: QueryBuilder|Closure, all: bool}>
     */
    private array $unions = [];

    /**
     * Add a UNION query.
     *
     * Combines results from multiple queries, removing duplicates.
     *
     * Example:
     * ```php
     * $query1 = DB::table('users')
     *     ->select(['id', 'name'])
     *     ->where('role', 'admin');
     *
     * $query2 = DB::table('users')
     *     ->select(['id', 'name'])
     *     ->where('role', 'moderator');
     *
     * $results = $query1->union($query2)->get();
     * // SELECT id, name FROM users WHERE role = 'admin'
     * // UNION
     * // SELECT id, name FROM users WHERE role = 'moderator'
     * ```
     *
     * Using closure:
     * ```php
     * $query->select(['id', 'name'])
     *       ->where('status', 'active')
     *       ->union(function($query) {
     *           $query->table('archived_users')
     *                 ->select(['id', 'name'])
     *                 ->where('archived_at', '>', '2024-01-01');
     *       });
     * ```
     *
     * Performance:
     * - Database performs duplicate elimination (slower than UNION ALL)
     * - Use UNION ALL if duplicates are acceptable for better performance
     *
     * @param QueryBuilder|Closure $query Query builder or closure
     * @return $this
     */
    public function union(self|Closure $query): self
    {
        if ($query instanceof Closure) {
            $builder = $this->newQuery();
            $query($builder);
            $query = $builder;
        }

        $this->unions[] = [
            'query' => $query,
            'all' => false,
        ];

        // Merge bindings from union query
        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'union');
        }

        return $this;
    }

    /**
     * Add a UNION ALL query.
     *
     * Combines results from multiple queries, keeping all duplicates.
     *
     * Example:
     * ```php
     * $query1 = DB::table('orders_2024')
     *     ->select(['id', 'total', 'created_at']);
     *
     * $query2 = DB::table('orders_2025')
     *     ->select(['id', 'total', 'created_at']);
     *
     * $allOrders = $query1->unionAll($query2)->get();
     * // SELECT id, total, created_at FROM orders_2024
     * // UNION ALL
     * // SELECT id, total, created_at FROM orders_2025
     * ```
     *
     * Performance:
     * - Faster than UNION (no duplicate elimination)
     * - Use when duplicates are acceptable or impossible
     *
     * @param QueryBuilder|Closure $query Query builder or closure
     * @return $this
     */
    public function unionAll(self|Closure $query): self
    {
        if ($query instanceof Closure) {
            $builder = $this->newQuery();
            $query($builder);
            $query = $builder;
        }

        $this->unions[] = [
            'query' => $query,
            'all' => true,
        ];

        // Merge bindings from union query
        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'union');
        }

        return $this;
    }

    /**
     * Get union queries.
     *
     * @return array<int, array{query: QueryBuilder|Closure, all: bool}>
     */
    protected function getUnions(): array
    {
        return $this->unions;
    }
}
