<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

/**
 * Trait BuildsAggregates
 *
 * Aggregate function builders for Query Builder.
 * Provides comprehensive aggregate methods matching Toporia's capabilities.
 *
 * Features:
 * - Basic aggregates: count, sum, avg, min, max
 * - Advanced aggregates: aggregate (custom)
 * - Multiple column aggregates
 * - Conditional aggregates
 *
 * Performance:
 * - Database-level aggregation (efficient)
 * - Single query execution
 * - Optimized for large datasets
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles aggregate queries
 * - Open/Closed: Extensible for new aggregate types
 * - Dependency Inversion: Uses QueryBuilder abstraction
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Query\Concerns
 * @since       2025-01-22
 *
 * @link        https://github.com/Minhphung4820/toporia
 */
trait BuildsAggregates
{
    /**
     * Retrieve the sum of the values of a given column.
     *
     * Example:
     * ```php
     * $total = DB::table('orders')->sum('total');
     * // SELECT SUM(total) as aggregate FROM orders
     *
     * $total = DB::table('orders')
     *     ->where('status', 'completed')
     *     ->sum('total');
     * ```
     *
     * Performance: O(1) - Single aggregate query, database optimized
     *
     * @param string $column Column to sum
     * @return float|int Sum value (0 if no rows)
     */
    public function sum(string $column): float|int
    {
        return $this->aggregate('SUM', $column);
    }

    /**
     * Retrieve the average of the values of a given column.
     *
     * Example:
     * ```php
     * $avgPrice = DB::table('products')->avg('price');
     * // SELECT AVG(price) as aggregate FROM products
     * ```
     *
     * Performance: O(1) - Single aggregate query
     *
     * @param string $column Column to average
     * @return float|null Average value (null if no rows)
     */
    public function avg(string $column): ?float
    {
        $result = $this->aggregate('AVG', $column);
        return $result !== null ? (float) $result : null;
    }

    /**
     * Alias for avg().
     *
     * @param string $column Column to average
     * @return float|null
     */
    public function average(string $column): ?float
    {
        return $this->avg($column);
    }

    /**
     * Retrieve the minimum value of a given column.
     *
     * Example:
     * ```php
     * $minPrice = DB::table('products')->min('price');
     * // SELECT MIN(price) as aggregate FROM products
     * ```
     *
     * Performance: O(1) - Single aggregate query
     *
     * @param string $column Column to find minimum
     * @return mixed Minimum value (null if no rows)
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Retrieve the maximum value of a given column.
     *
     * Example:
     * ```php
     * $maxPrice = DB::table('products')->max('price');
     * // SELECT MAX(price) as aggregate FROM products
     * ```
     *
     * Performance: O(1) - Single aggregate query
     *
     * @param string $column Column to find maximum
     * @return mixed Maximum value (null if no rows)
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Execute an aggregate function on the database.
     *
     * Generic method for executing any aggregate function.
     * Used internally by sum(), avg(), min(), max(), count().
     *
     * Example:
     * ```php
     * $result = DB::table('orders')
     *     ->aggregate('COUNT', 'DISTINCT user_id');
     * // SELECT COUNT(DISTINCT user_id) as aggregate FROM orders
     * ```
     *
     * Performance: O(1) - Single aggregate query
     *
     * Clean Architecture:
     * - Single Responsibility: Executes aggregate queries
     * - Open/Closed: Extensible for new aggregate types
     *
     * @param string $function Aggregate function (SUM, AVG, MIN, MAX, COUNT, etc.)
     * @param string $column Column or expression to aggregate
     * @return mixed Aggregate result
     */
    public function aggregate(string $function, string $column): mixed
    {
        $originalColumns = $this->columns;
        $this->columns = ["{$function}({$column}) as aggregate"];

        // Execute query directly to get raw array result
        $sql = $this->toSql();
        $rows = $this->connection->select($sql, $this->bindings);
        $result = $rows[0] ?? null;

        // Restore original columns
        $this->columns = $originalColumns;

        return $result['aggregate'] ?? null;
    }

    /**
     * Execute multiple aggregate functions in a single query.
     *
     * More efficient than calling multiple aggregate methods separately.
     *
     * Example:
     * ```php
     * $stats = DB::table('orders')
     *     ->aggregates([
     *         'total_sum' => 'SUM(total)',
     *         'total_avg' => 'AVG(total)',
     *         'order_count' => 'COUNT(*)',
     *         'max_total' => 'MAX(total)'
     *     ]);
     * // SELECT SUM(total) as total_sum, AVG(total) as total_avg,
     * //        COUNT(*) as order_count, MAX(total) as max_total
     * // FROM orders
     * ```
     *
     * Performance: O(1) - Single query with multiple aggregates
     * Much more efficient than separate queries
     *
     * @param array<string, string> $aggregates Array of alias => expression
     * @return array<string, mixed> Associative array of aggregate results
     */
    public function aggregates(array $aggregates): array
    {
        $originalColumns = $this->columns;

        // Build select columns
        $selectColumns = [];
        foreach ($aggregates as $alias => $expression) {
            $selectColumns[] = "{$expression} as {$alias}";
        }
        $this->columns = $selectColumns;

        // Execute query
        $sql = $this->toSql();
        $rows = $this->connection->select($sql, $this->bindings);
        $result = $rows[0] ?? [];

        // Restore original columns
        $this->columns = $originalColumns;

        return $result;
    }
}
