<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

use Closure;
use Toporia\Framework\Database\Query\QueryBuilder;

/**
 * Trait BuildsAdvancedQueries
 *
 * Advanced query builders for Query Builder.
 * Provides CTEs (Common Table Expressions) and Window Functions.
 *
 * Features:
 * - CTEs (WITH clauses) for recursive queries and query organization
 * - Window Functions (ROW_NUMBER, RANK, DENSE_RANK, etc.)
 * - Advanced SQL features matching Toporia's capabilities
 *
 * Performance:
 * - CTEs: Database-optimized, can improve query readability and performance
 * - Window Functions: Database-level calculations, more efficient than PHP processing
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles advanced SQL features
 * - Open/Closed: Extensible for new window functions
 * - High Reusability: Works with any QueryBuilder instance
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
trait BuildsAdvancedQueries
{
    /**
     * Common Table Expressions (CTEs) storage.
     *
     * @var array<array{name: string, query: QueryBuilder|string, columns: ?array<string>}>
     */
    private array $ctes = [];

    /**
     * Window function clauses.
     *
     * @var array<array{function: string, alias: string, partitionBy: ?array<string>, orderBy: ?array<array{column: string, direction: string}>}>
     */
    private array $windowFunctions = [];

    /**
     * Add a Common Table Expression (CTE).
     *
     * CTEs allow you to define temporary named result sets that exist
     * only for the duration of a single query. Useful for:
     * - Recursive queries
     * - Query organization and readability
     * - Reusing subqueries
     *
     * Note: This method is named `withCte()` to avoid conflict with
     * ModelQueryBuilder::with() for eager loading relationships.
     *
     * Example:
     * ```php
     * // Simple CTE
     * $query->withCte('active_users', function($query) {
     *     $query->table('users')->where('status', 'active');
     * })
     * ->from('active_users')
     * ->select('*');
     *
     * // CTE with columns
     * $query->withCte('user_stats', function($query) {
     *     $query->table('users')
     *           ->select(['id', 'COUNT(*) as order_count'])
     *           ->groupBy('id');
     * }, ['user_id', 'order_count'])
     * ->from('user_stats')
     * ->where('order_count', '>', 10);
     *
     * // Multiple CTEs
     * $query->withCte('cte1', function($q) { ... })
     *       ->withCte('cte2', function($q) { ... })
     *       ->from('cte1')
     *       ->join('cte2', 'cte1.id', '=', 'cte2.id');
     * ```
     *
     * Performance:
     * - Database-optimized CTEs
     * - Can improve query plan optimization
     * - Reduces query complexity
     *
     * @param string $name CTE name
     * @param Closure|QueryBuilder|string $query Query builder callback, QueryBuilder instance, or raw SQL
     * @param array<string>|null $columns Optional column names for the CTE
     * @return $this
     */
    public function withCte(string $name, Closure|QueryBuilder|string $query, ?array $columns = null): self
    {
        // If query is a Closure, build it
        if ($query instanceof Closure) {
            $cteQuery = $this->newQuery();
            $query($cteQuery);
            $query = $cteQuery;
        }

        $this->ctes[] = [
            'name' => $name,
            'query' => $query,
            'columns' => $columns,
        ];

        $this->invalidateCache();

        return $this;
    }

    /**
     * Add a recursive Common Table Expression.
     *
     * Recursive CTEs allow queries that reference themselves.
     * Useful for hierarchical data (tree structures, organizational charts, etc.).
     *
     * Example:
     * ```php
     * // Find all descendants of a category
     * $query->withRecursiveCte('category_tree', function($query) {
     *     // Anchor member: root categories
     *     $query->table('categories')
     *           ->where('parent_id', null)
     *           ->select(['id', 'name', 'parent_id']);
     * }, function($query) {
     *     // Recursive member: child categories
     *     $query->table('categories')
     *           ->join('category_tree', 'categories.parent_id', '=', 'category_tree.id')
     *           ->select(['categories.id', 'categories.name', 'categories.parent_id']);
     * })
     * ->from('category_tree')
     * ->select('*');
     * ```
     *
     * Performance: Database-optimized recursive queries
     *
     * @param string $name CTE name
     * @param Closure $anchor Anchor member (non-recursive part)
     * @param Closure $recursive Recursive member
     * @param array<string>|null $columns Optional column names
     * @return $this
     */
    public function withRecursiveCte(
        string $name,
        Closure $anchor,
        Closure $recursive,
        ?array $columns = null
    ): self {
        // Build anchor query
        $anchorQuery = $this->newQuery();
        $anchor($anchorQuery);

        // Build recursive query
        $recursiveQuery = $this->newQuery();
        $recursive($recursiveQuery);

        // Store as recursive CTE
        $this->ctes[] = [
            'name' => $name,
            'query' => [
                'anchor' => $anchorQuery,
                'recursive' => $recursiveQuery,
            ],
            'columns' => $columns,
            'recursive' => true,
        ];

        $this->invalidateCache();

        return $this;
    }

    /**
     * Add a window function to SELECT clause.
     *
     * Window functions perform calculations across a set of rows related to the current row.
     * Common window functions: ROW_NUMBER, RANK, DENSE_RANK, SUM, AVG, etc.
     *
     * Example:
     * ```php
     * // Add row numbers
     * $query->select('*')
     *       ->addWindowFunction('row_number', 'ROW_NUMBER()', ['created_at' => 'DESC'])
     *       ->get();
     * // SELECT *, ROW_NUMBER() OVER (ORDER BY created_at DESC) AS row_number
     *
     * // Partitioned window function
     * $query->select('*')
     *       ->addWindowFunction('rank_in_category', 'RANK()', ['category_id'], ['price' => 'DESC'])
     *       ->get();
     * // SELECT *, RANK() OVER (PARTITION BY category_id ORDER BY price DESC) AS rank_in_category
     * ```
     *
     * Performance: Database-level calculations, very efficient
     *
     * @param string $alias Alias for the window function result
     * @param string $function Window function (ROW_NUMBER, RANK, DENSE_RANK, SUM, AVG, etc.)
     * @param array<string>|null $partitionBy Columns to partition by
     * @param array<string, string>|null $orderBy Order by columns (column => direction)
     * @return $this
     */
    public function addWindowFunction(
        string $alias,
        string $function,
        ?array $partitionBy = null,
        ?array $orderBy = null
    ): self {
        $this->windowFunctions[] = [
            'function' => $function,
            'alias' => $alias,
            'partitionBy' => $partitionBy,
            'orderBy' => $orderBy,
        ];

        // Add to SELECT columns
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        // Build window function expression
        $overClause = 'OVER (';
        if ($partitionBy !== null && !empty($partitionBy)) {
            $overClause .= 'PARTITION BY ' . implode(', ', $partitionBy) . ' ';
        }
        if ($orderBy !== null && !empty($orderBy)) {
            $orderParts = [];
            foreach ($orderBy as $column => $direction) {
                $orderParts[] = "{$column} " . strtoupper($direction);
            }
            $overClause .= 'ORDER BY ' . implode(', ', $orderParts);
        }
        $overClause .= ')';

        $this->columns[] = "{$function} {$overClause} AS {$alias}";

        $this->invalidateCache();

        return $this;
    }

    /**
     * Get CTEs for compilation.
     *
     * @return array<array>
     */
    public function getCtes(): array
    {
        return $this->ctes;
    }

    /**
     * Get window functions for compilation.
     *
     * @return array<array>
     */
    public function getWindowFunctions(): array
    {
        return $this->windowFunctions;
    }

    // =========================================================================
    // BACKWARD COMPATIBILITY ALIASES
    // =========================================================================

    /**
     * Alias for withRecursiveCte() for backward compatibility.
     *
     * @deprecated Use withRecursiveCte() instead
     * @param string $name CTE name
     * @param Closure $anchor Anchor member (non-recursive part)
     * @param Closure $recursive Recursive member
     * @param array<string>|null $columns Optional column names
     * @return $this
     */
    public function withRecursive(
        string $name,
        Closure $anchor,
        Closure $recursive,
        ?array $columns = null
    ): self {
        return $this->withRecursiveCte($name, $anchor, $recursive, $columns);
    }

    /**
     * Alias for addWindowFunction() for backward compatibility.
     *
     * @deprecated Use addWindowFunction() instead
     * @param string $alias Alias for the window function result
     * @param string $function Window function
     * @param array<string>|null $partitionBy Columns to partition by
     * @param array<string, string>|null $orderBy Order by columns
     * @return $this
     */
    public function window(
        string $alias,
        string $function,
        ?array $partitionBy = null,
        ?array $orderBy = null
    ): self {
        return $this->addWindowFunction($alias, $function, $partitionBy, $orderBy);
    }
}
