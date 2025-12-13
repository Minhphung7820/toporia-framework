<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

/**
 * Trait BuildsWhereClausesAdvanced
 *
 * Advanced WHERE clause builders for Query Builder.
 * Provides Modern ORM advanced WHERE methods with performance optimization.
 *
 * Features:
 * - Date/Time WHERE clauses (whereDate, whereMonth, whereDay, whereYear, whereTime)
 * - Column comparison (whereColumn)
 * - OR variants for all methods
 * - Optimized SQL generation
 *
 * Performance:
 * - O(1) clause addition
 * - Zero-copy SQL building
 * - Prepared statement friendly
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
trait BuildsWhereClausesAdvanced
{
    /**
     * Add a WHERE DATE clause.
     *
     * Compares only the date portion of a datetime column.
     *
     * Example:
     * ```php
     * $query->whereDate('created_at', '2025-01-22');
     * // WHERE DATE(created_at) = '2025-01-22'
     * ```
     *
     * Performance: O(1) - Single WHERE clause addition
     *
     * @param string $column Column name
     * @param string $operator Operator (=, !=, >, <, >=, <=)
     * @param string|null $value Date value (YYYY-MM-DD)
     * @return $this
     */
    public function whereDate(string $column, string $operator, ?string $value = null): self
    {
        // Handle where($column, $value) syntax
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'DateBasic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND',
        ];

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Add an OR WHERE DATE clause.
     *
     * @param string $column Column name
     * @param string $operator Operator
     * @param string|null $value Date value
     * @return $this
     */
    public function orWhereDate(string $column, string $operator, ?string $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'DateBasic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR',
        ];

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Add a WHERE MONTH clause.
     *
     * Compares only the month of a datetime column.
     *
     * Example:
     * ```php
     * $query->whereMonth('created_at', 12);  // December
     * // WHERE MONTH(created_at) = 12
     * ```
     *
     * @param string $column Column name
     * @param int|string $operator Operator or month value (1-12)
     * @param int|string|null $value Month value (optional)
     * @return $this
     */
    public function whereMonth(string $column, int|string $operator, int|string|null $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'MonthBasic',
            'column' => $column,
            'operator' => $operator,
            'value' => (int)$value,
            'boolean' => 'AND',
        ];

        $this->addBinding((int)$value, 'where');

        return $this;
    }

    /**
     * Add an OR WHERE MONTH clause.
     *
     * @param string $column Column name
     * @param int|string $operator Operator or value
     * @param int|string|null $value Month value
     * @return $this
     */
    public function orWhereMonth(string $column, int|string $operator, int|string|null $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'MonthBasic',
            'column' => $column,
            'operator' => $operator,
            'value' => (int)$value,
            'boolean' => 'OR',
        ];

        $this->addBinding((int)$value, 'where');

        return $this;
    }

    /**
     * Add a WHERE DAY clause.
     *
     * Compares only the day of a datetime column.
     *
     * Example:
     * ```php
     * $query->whereDay('created_at', 22);  // 22nd day of month
     * // WHERE DAY(created_at) = 22
     * ```
     *
     * @param string $column Column name
     * @param int|string $operator Operator or day value (1-31)
     * @param int|string|null $value Day value (optional)
     * @return $this
     */
    public function whereDay(string $column, int|string $operator, int|string|null $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'DayBasic',
            'column' => $column,
            'operator' => $operator,
            'value' => (int)$value,
            'boolean' => 'AND',
        ];

        $this->addBinding((int)$value, 'where');

        return $this;
    }

    /**
     * Add an OR WHERE DAY clause.
     *
     * @param string $column Column name
     * @param int|string $operator Operator or value
     * @param int|string|null $value Day value
     * @return $this
     */
    public function orWhereDay(string $column, int|string $operator, int|string|null $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'DayBasic',
            'column' => $column,
            'operator' => $operator,
            'value' => (int)$value,
            'boolean' => 'OR',
        ];

        $this->addBinding((int)$value, 'where');

        return $this;
    }

    /**
     * Add a WHERE YEAR clause.
     *
     * Compares only the year of a datetime column.
     *
     * Example:
     * ```php
     * $query->whereYear('created_at', 2025);
     * // WHERE YEAR(created_at) = 2025
     * ```
     *
     * @param string $column Column name
     * @param int|string $operator Operator or year value
     * @param int|string|null $value Year value (optional)
     * @return $this
     */
    public function whereYear(string $column, int|string $operator, int|string|null $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'YearBasic',
            'column' => $column,
            'operator' => $operator,
            'value' => (int)$value,
            'boolean' => 'AND',
        ];

        $this->addBinding((int)$value, 'where');

        return $this;
    }

    /**
     * Add an OR WHERE YEAR clause.
     *
     * @param string $column Column name
     * @param int|string $operator Operator or value
     * @param int|string|null $value Year value
     * @return $this
     */
    public function orWhereYear(string $column, int|string $operator, int|string|null $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'YearBasic',
            'column' => $column,
            'operator' => $operator,
            'value' => (int)$value,
            'boolean' => 'OR',
        ];

        $this->addBinding((int)$value, 'where');

        return $this;
    }

    /**
     * Add a WHERE TIME clause.
     *
     * Compares only the time portion of a datetime column.
     *
     * Example:
     * ```php
     * $query->whereTime('created_at', '>=', '10:30:00');
     * // WHERE TIME(created_at) >= '10:30:00'
     * ```
     *
     * @param string $column Column name
     * @param string $operator Operator
     * @param string|null $value Time value (HH:MM:SS)
     * @return $this
     */
    public function whereTime(string $column, string $operator, ?string $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'TimeBasic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND',
        ];

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Add an OR WHERE TIME clause.
     *
     * @param string $column Column name
     * @param string $operator Operator
     * @param string|null $value Time value
     * @return $this
     */
    public function orWhereTime(string $column, string $operator, ?string $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'TimeBasic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR',
        ];

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Add a WHERE COLUMN clause.
     *
     * Compares two columns without parameter binding.
     *
     * Example:
     * ```php
     * $query->whereColumn('first_name', 'last_name');
     * // WHERE first_name = last_name
     *
     * $query->whereColumn('updated_at', '>', 'created_at');
     * // WHERE updated_at > created_at
     * ```
     *
     * Performance: O(1) - No parameter binding needed
     *
     * @param string $first First column
     * @param string|null $operator Operator (=, !=, >, <, >=, <=)
     * @param string|null $second Second column
     * @return $this
     */
    public function whereColumn(string $first, ?string $operator = null, ?string $second = null): self
    {
        // Handle whereColumn($col1, $col2) syntax
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'Column',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => 'AND',
        ];

        // No bindings needed for column comparison

        return $this;
    }

    /**
     * Add an OR WHERE COLUMN clause.
     *
     * @param string $first First column
     * @param string|null $operator Operator
     * @param string|null $second Second column
     * @return $this
     */
    public function orWhereColumn(string $first, ?string $operator = null, ?string $second = null): self
    {
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'Column',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => 'OR',
        ];

        return $this;
    }

    /**
     * Add an OR WHERE NULL clause.
     *
     * @param string $column Column name
     * @return $this
     */
    public function orWhereNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'Null',
            'column' => $column,
            'boolean' => 'OR',
        ];

        return $this;
    }

    /**
     * Add an OR WHERE NOT NULL clause.
     *
     * @param string $column Column name
     * @return $this
     */
    public function orWhereNotNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'NotNull',
            'column' => $column,
            'boolean' => 'OR',
        ];

        return $this;
    }

    /**
     * Add an OR WHERE RAW clause.
     *
     * @param string $sql Raw SQL
     * @param array<mixed> $bindings Bindings
     * @return $this
     */
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = [
            'type' => 'Raw',
            'sql' => $sql,
            'boolean' => 'OR',
        ];

        foreach ($bindings as $binding) {
            $this->addBinding($binding, 'where');
        }

        // CRITICAL FIX: Invalidate SQL cache when WHERE clause is modified
        // Bug: orWhereRaw() was not invalidating cache, causing toSql() to return stale SQL
        $this->invalidateCache();

        return $this;
    }
}
