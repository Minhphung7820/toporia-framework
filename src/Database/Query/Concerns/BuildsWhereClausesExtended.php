<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

use Closure;

/**
 * Trait BuildsWhereClausesExtended
 *
 * Extended WHERE clause builders for Query Builder.
 * Provides comprehensive WHERE methods matching Toporia's capabilities.
 *
 * Features:
 * - Range queries: whereBetween, whereNotBetween
 * - JSON queries: whereJsonContains, whereJsonDoesntContain, whereJsonLength
 * - Full-text search: whereFullText (MySQL/PostgreSQL)
 * - Regex/Like: whereLike, whereNotLike, whereRegexp
 *
 * Performance:
 * - O(1) clause addition
 * - Database-optimized JSON operations
 * - Index-friendly range queries
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles extended WHERE clauses
 * - Open/Closed: Extensible for new WHERE types
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
trait BuildsWhereClausesExtended
{
    /**
     * Add a WHERE BETWEEN clause.
     *
     * Checks if column value is between two values (inclusive).
     *
     * Example:
     * ```php
     * $query->whereBetween('price', [100, 500]);
     * // WHERE price BETWEEN ? AND ?
     *
     * $query->whereBetween('created_at', [$startDate, $endDate]);
     * ```
     *
     * Performance: O(1) - Single WHERE clause, database optimizes with indexes
     *
     * @param string $column Column name
     * @param array<int|float|string> $values Array of [min, max] values
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereBetween(string $column, array $values, string $boolean = 'AND'): self
    {
        if (count($values) !== 2) {
            throw new \InvalidArgumentException('whereBetween requires exactly 2 values [min, max]');
        }

        $this->wheres[] = [
            'type' => 'Between',
            'column' => $column,
            'values' => $values,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($values[0], 'where');
        $this->addBinding($values[1], 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add a WHERE NOT BETWEEN clause.
     *
     * Checks if column value is NOT between two values.
     *
     * Example:
     * ```php
     * $query->whereNotBetween('price', [100, 500]);
     * // WHERE price NOT BETWEEN ? AND ?
     * ```
     *
     * @param string $column Column name
     * @param array<int|float|string> $values Array of [min, max] values
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereNotBetween(string $column, array $values, string $boolean = 'AND'): self
    {
        if (count($values) !== 2) {
            throw new \InvalidArgumentException('whereNotBetween requires exactly 2 values [min, max]');
        }

        $this->wheres[] = [
            'type' => 'NotBetween',
            'column' => $column,
            'values' => $values,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($values[0], 'where');
        $this->addBinding($values[1], 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE BETWEEN clause.
     *
     * @param string $column Column name
     * @param array<int|float|string> $values Array of [min, max] values
     * @return $this
     */
    public function orWhereBetween(string $column, array $values): self
    {
        return $this->whereBetween($column, $values, 'OR');
    }

    /**
     * Add an OR WHERE NOT BETWEEN clause.
     *
     * @param string $column Column name
     * @param array<int|float|string> $values Array of [min, max] values
     * @return $this
     */
    public function orWhereNotBetween(string $column, array $values): self
    {
        return $this->whereNotBetween($column, $values, 'OR');
    }

    /**
     * Add a WHERE JSON CONTAINS clause.
     *
     * Checks if JSON column contains a specific value.
     * Works with MySQL 5.7+ and PostgreSQL 9.3+.
     *
     * Example:
     * ```php
     * // MySQL: Check if JSON array contains value
     * $query->whereJsonContains('tags', 'php');
     * // WHERE JSON_CONTAINS(tags, '"php"')
     *
     * // PostgreSQL: Check if JSON array contains value
     * $query->whereJsonContains('tags', 'php');
     * // WHERE tags @> '"php"'
     *
     * // Nested path
     * $query->whereJsonContains('options->features', 'premium');
     * ```
     *
     * Performance: Database-optimized JSON operations (uses JSON indexes if available)
     *
     * @param string $column JSON column name (supports -> syntax for nested paths)
     * @param mixed $value Value to check for
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereJsonContains(string $column, mixed $value, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'JsonContains',
            'column' => $column,
            'value' => $value,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($value, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add a WHERE JSON DOESN'T CONTAIN clause.
     *
     * Checks if JSON column does NOT contain a specific value.
     *
     * Example:
     * ```php
     * $query->whereJsonDoesntContain('tags', 'deprecated');
     * ```
     *
     * @param string $column JSON column name
     * @param mixed $value Value to check for
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereJsonDoesntContain(string $column, mixed $value, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'JsonDoesntContain',
            'column' => $column,
            'value' => $value,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($value, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE JSON CONTAINS clause.
     *
     * @param string $column JSON column name
     * @param mixed $value Value to check for
     * @return $this
     */
    public function orWhereJsonContains(string $column, mixed $value): self
    {
        return $this->whereJsonContains($column, $value, 'OR');
    }

    /**
     * Add an OR WHERE JSON DOESN'T CONTAIN clause.
     *
     * @param string $column JSON column name
     * @param mixed $value Value to check for
     * @return $this
     */
    public function orWhereJsonDoesntContain(string $column, mixed $value): self
    {
        return $this->whereJsonDoesntContain($column, $value, 'OR');
    }

    /**
     * Add a WHERE JSON LENGTH clause.
     *
     * Checks the length of a JSON array or object.
     *
     * Example:
     * ```php
     * // Check if JSON array has at least 3 items
     * $query->whereJsonLength('tags', '>=', 3);
     * // WHERE JSON_LENGTH(tags) >= 3
     *
     * // Check if JSON array has exactly 5 items
     * $query->whereJsonLength('items', 5);
     * ```
     *
     * Performance: Database-optimized JSON functions
     *
     * @param string $column JSON column name
     * @param string $operator Operator (=, !=, >, <, >=, <=)
     * @param int|null $value Length value
     * @return $this
     */
    public function whereJsonLength(string $column, string $operator, ?int $value = null): self
    {
        if ($value === null) {
            $value = (int) $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'JsonLength',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND',
        ];

        $this->addBinding($value, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE JSON LENGTH clause.
     *
     * @param string $column JSON column name
     * @param string $operator Operator
     * @param int|null $value Length value
     * @return $this
     */
    public function orWhereJsonLength(string $column, string $operator, ?int $value = null): self
    {
        if ($value === null) {
            $value = (int) $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'JsonLength',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR',
        ];

        $this->addBinding($value, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add a WHERE LIKE clause.
     *
     * Pattern matching with LIKE operator.
     *
     * Example:
     * ```php
     * $query->whereLike('name', '%john%');
     * // WHERE name LIKE '%john%'
     * ```
     *
     * Performance: Can use indexes if pattern doesn't start with %
     *
     * @param string $column Column name
     * @param string $pattern LIKE pattern
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereLike(string $column, string $pattern, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'Like',
            'column' => $column,
            'value' => $pattern,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($pattern, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add a WHERE NOT LIKE clause.
     *
     * @param string $column Column name
     * @param string $pattern LIKE pattern
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereNotLike(string $column, string $pattern, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'NotLike',
            'column' => $column,
            'value' => $pattern,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($pattern, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE LIKE clause.
     *
     * @param string $column Column name
     * @param string $pattern LIKE pattern
     * @return $this
     */
    public function orWhereLike(string $column, string $pattern): self
    {
        return $this->whereLike($column, $pattern, 'OR');
    }

    /**
     * Add an OR WHERE NOT LIKE clause.
     *
     * @param string $column Column name
     * @param string $pattern LIKE pattern
     * @return $this
     */
    public function orWhereNotLike(string $column, string $pattern): self
    {
        return $this->whereNotLike($column, $pattern, 'OR');
    }

    /**
     * Add a WHERE REGEXP clause (MySQL only).
     *
     * Regular expression matching.
     *
     * Example:
     * ```php
     * $query->whereRegexp('email', '^[a-z]+@example\.com$');
     * // WHERE email REGEXP '^[a-z]+@example\.com$'
     * ```
     *
     * Performance: Can be slow on large tables, use with caution
     *
     * @param string $column Column name
     * @param string $pattern Regex pattern
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereRegexp(string $column, string $pattern, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'Regexp',
            'column' => $column,
            'value' => $pattern,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($pattern, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE REGEXP clause.
     *
     * @param string $column Column name
     * @param string $pattern Regex pattern
     * @return $this
     */
    public function orWhereRegexp(string $column, string $pattern): self
    {
        return $this->whereRegexp($column, $pattern, 'OR');
    }

    /**
     * Add a WHERE FULLTEXT clause (MySQL/PostgreSQL).
     *
     * Full-text search on indexed columns.
     *
     * Example:
     * ```php
     * // MySQL
     * $query->whereFullText(['title', 'body'], 'search term');
     * // WHERE MATCH(title, body) AGAINST('search term' IN NATURAL LANGUAGE MODE)
     *
     * // PostgreSQL
     * $query->whereFullText(['title', 'body'], 'search term');
     * // WHERE to_tsvector('english', title || ' ' || body) @@ to_tsquery('english', 'search term')
     * ```
     *
     * Performance: Uses full-text indexes for fast searching
     *
     * @param string|array<string> $columns Column(s) to search
     * @param string $term Search term
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereFullText(string|array $columns, string $term, string $boolean = 'AND'): self
    {
        $columns = is_array($columns) ? $columns : [$columns];

        $this->wheres[] = [
            'type' => 'FullText',
            'columns' => $columns,
            'value' => $term,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($term, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE FULLTEXT clause.
     *
     * @param string|array<string> $columns Column(s) to search
     * @param string $term Search term
     * @return $this
     */
    public function orWhereFullText(string|array $columns, string $term): self
    {
        return $this->whereFullText($columns, $term, 'OR');
    }
}
