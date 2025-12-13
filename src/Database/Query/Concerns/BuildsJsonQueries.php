<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

use Toporia\Framework\Database\Query\Expression;

/**
 * Trait BuildsJsonQueries
 *
 * Complete JSON query support for QueryBuilder.
 * Provides full Toporia-style JSON operations plus advanced features.
 *
 * Features:
 * - whereJson: Basic JSON column queries with -> syntax
 * - whereJsonContainsKey: Check if JSON has key
 * - whereJsonOverlaps: Check if JSON arrays overlap
 * - JSON casting: Cast JSON values to specific types
 *
 * Performance:
 * - Uses database-native JSON functions
 * - Supports JSON indexes (MySQL 8.0+, PostgreSQL)
 * - O(1) clause addition
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Query\Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * Database Support:
 * - MySQL 5.7+ (JSON_CONTAINS, JSON_EXTRACT, etc.)
 * - PostgreSQL 9.3+ (jsonb operators: @>, ->, #>>)
 * - SQLite 3.38+ (JSON1 extension)
 */
trait BuildsJsonQueries
{
    /**
     * Add a WHERE clause for JSON column using arrow syntax.
     *
     * Supports both -> (object) and ->> (text extraction) syntax.
     *
     * Example:
     * ```php
     * $query->whereJson('settings->theme', 'dark');
     * // MySQL: WHERE JSON_UNQUOTE(JSON_EXTRACT(settings, '$.theme')) = ?
     * // PostgreSQL: WHERE settings->>'theme' = ?
     *
     * $query->whereJson('data->user->name', 'John');
     * // Nested path: settings.user.name
     * ```
     *
     * @param string $column JSON column with path (e.g., 'settings->theme')
     * @param mixed $operator Operator or value
     * @param mixed $value Value (optional if $operator is value)
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereJson(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND'): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        [$columnName, $path] = $this->parseJsonPath($column);

        $this->wheres[] = [
            'type' => 'Json',
            'column' => $columnName,
            'path' => $path,
            'operator' => $operator,
            'value' => $value,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($value, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE clause for JSON column.
     *
     * @param string $column JSON column with path
     * @param mixed $operator Operator or value
     * @param mixed $value Value
     * @return $this
     */
    public function orWhereJson(string $column, mixed $operator, mixed $value = null): self
    {
        return $this->whereJson($column, $operator, $value, 'OR');
    }

    /**
     * Add a WHERE clause checking if JSON has a key.
     *
     * Example:
     * ```php
     * $query->whereJsonContainsKey('settings', 'theme');
     * // MySQL: WHERE JSON_CONTAINS_PATH(settings, 'one', '$.theme')
     * // PostgreSQL: WHERE settings ? 'theme'
     *
     * $query->whereJsonContainsKey('data', 'user.name');
     * // Nested: settings.user.name
     * ```
     *
     * @param string $column JSON column name
     * @param string $key Key to check (use dot notation for nested)
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereJsonContainsKey(string $column, string $key, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'JsonContainsKey',
            'column' => $column,
            'key' => $key,
            'boolean' => strtoupper($boolean),
        ];

        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE clause checking if JSON has a key.
     *
     * @param string $column JSON column name
     * @param string $key Key to check
     * @return $this
     */
    public function orWhereJsonContainsKey(string $column, string $key): self
    {
        return $this->whereJsonContainsKey($column, $key, 'OR');
    }

    /**
     * Add a WHERE clause checking if JSON doesn't have a key.
     *
     * @param string $column JSON column name
     * @param string $key Key to check
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereJsonDoesntContainKey(string $column, string $key, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'JsonDoesntContainKey',
            'column' => $column,
            'key' => $key,
            'boolean' => strtoupper($boolean),
        ];

        $this->invalidateCache();

        return $this;
    }

    /**
     * Add a WHERE clause checking if JSON arrays overlap.
     *
     * Example:
     * ```php
     * $query->whereJsonOverlaps('tags', ['php', 'toporia']);
     * // MySQL: WHERE JSON_OVERLAPS(tags, '["php","toporia"]')
     * // PostgreSQL: WHERE tags ?| array['php', 'toporia']
     * ```
     *
     * @param string $column JSON column name
     * @param array<mixed> $values Values to check for overlap
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereJsonOverlaps(string $column, array $values, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'JsonOverlaps',
            'column' => $column,
            'values' => $values,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding(json_encode($values), 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE clause checking if JSON arrays overlap.
     *
     * @param string $column JSON column name
     * @param array<mixed> $values Values to check
     * @return $this
     */
    public function orWhereJsonOverlaps(string $column, array $values): self
    {
        return $this->whereJsonOverlaps($column, $values, 'OR');
    }

    /**
     * Add a WHERE clause for JSON type.
     *
     * Check the type of a JSON value.
     *
     * Example:
     * ```php
     * $query->whereJsonType('data->value', 'number');
     * // WHERE JSON_TYPE(JSON_EXTRACT(data, '$.value')) = 'NUMBER'
     * ```
     *
     * @param string $column JSON column with path
     * @param string $type JSON type (object, array, string, number, boolean, null)
     * @param string $boolean Boolean operator
     * @return $this
     */
    public function whereJsonType(string $column, string $type, string $boolean = 'AND'): self
    {
        [$columnName, $path] = $this->parseJsonPath($column);

        $this->wheres[] = [
            'type' => 'JsonType',
            'column' => $columnName,
            'path' => $path,
            'jsonType' => strtoupper($type),
            'boolean' => strtoupper($boolean),
        ];

        $this->invalidateCache();

        return $this;
    }

    /**
     * Add a WHERE clause comparing JSON depth.
     *
     * Example:
     * ```php
     * $query->whereJsonDepth('data', '<=', 3);
     * // WHERE JSON_DEPTH(data) <= 3
     * ```
     *
     * @param string $column JSON column name
     * @param string $operator Comparison operator
     * @param int $depth Depth value
     * @param string $boolean Boolean operator
     * @return $this
     */
    public function whereJsonDepth(string $column, string $operator, int $depth, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'JsonDepth',
            'column' => $column,
            'operator' => $operator,
            'value' => $depth,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($depth, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add a WHERE clause for valid JSON.
     *
     * Check if column contains valid JSON.
     *
     * Example:
     * ```php
     * $query->whereJsonValid('data');
     * // WHERE JSON_VALID(data)
     * ```
     *
     * @param string $column Column name
     * @param bool $valid Check for valid (true) or invalid (false) JSON
     * @param string $boolean Boolean operator
     * @return $this
     */
    public function whereJsonValid(string $column, bool $valid = true, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'JsonValid',
            'column' => $column,
            'valid' => $valid,
            'boolean' => strtoupper($boolean),
        ];

        $this->invalidateCache();

        return $this;
    }

    /**
     * Select JSON value with optional casting.
     *
     * Multi-database support:
     * - MySQL: JSON_UNQUOTE(JSON_EXTRACT(column, '$.path'))
     * - PostgreSQL: column->>'path'
     * - SQLite: json_extract(column, '$.path')
     *
     * Example:
     * ```php
     * $query->selectJson('settings->theme', 'theme');
     * // MySQL: SELECT JSON_UNQUOTE(JSON_EXTRACT(settings, '$.theme')) AS theme
     * // PostgreSQL: SELECT settings->>'theme' AS "theme"
     *
     * $query->selectJson('data->count', 'count', 'integer');
     * // MySQL: SELECT CAST(JSON_EXTRACT(data, '$.count') AS SIGNED) AS count
     * // PostgreSQL: SELECT (data->>'count')::INTEGER AS "count"
     * ```
     *
     * @param string $column JSON column with path
     * @param string|null $alias Column alias
     * @param string|null $cast Cast to type (integer, float, string, boolean)
     * @return $this
     */
    public function selectJson(string $column, ?string $alias = null, ?string $cast = null): self
    {
        [$columnName, $path] = $this->parseJsonPath($column);

        // Use Grammar for multi-database JSON select compilation
        $grammar = $this->connection->getGrammar();
        $expression = $grammar->compileJsonSelect($columnName, $path ?? '', $cast, $alias);

        $this->columns[] = new Expression($expression);

        return $this;
    }

    /**
     * Order by JSON value.
     *
     * Multi-database support:
     * - MySQL: JSON_EXTRACT(column, '$.path')
     * - PostgreSQL: column->'path'
     * - SQLite: json_extract(column, '$.path')
     *
     * Example:
     * ```php
     * $query->orderByJson('settings->priority', 'desc');
     * // MySQL: ORDER BY JSON_EXTRACT(settings, '$.priority') DESC
     * // PostgreSQL: ORDER BY settings->>'priority' DESC
     * ```
     *
     * @param string $column JSON column with path
     * @param string $direction Sort direction
     * @param string|null $cast Cast to type for proper sorting
     * @return $this
     */
    public function orderByJson(string $column, string $direction = 'asc', ?string $cast = null): self
    {
        [$columnName, $path] = $this->parseJsonPath($column);

        // Use Grammar for multi-database JSON order compilation
        $grammar = $this->connection->getGrammar();
        $orderSql = $grammar->compileJsonOrder($columnName, $path ?? '', $direction, $cast);

        $this->orders[] = [
            'type' => 'raw',
            'sql' => $orderSql,
        ];

        $this->invalidateCache();

        return $this;
    }

    /**
     * Search in JSON.
     *
     * Find path to value in JSON document.
     *
     * Example:
     * ```php
     * $query->whereJsonSearch('data', 'one', 'searchValue');
     * // WHERE JSON_SEARCH(data, 'one', 'searchValue') IS NOT NULL
     * ```
     *
     * @param string $column JSON column name
     * @param string $oneOrAll 'one' returns first match, 'all' returns all
     * @param string $searchValue Value to search for
     * @param string $boolean Boolean operator
     * @return $this
     */
    public function whereJsonSearch(
        string $column,
        string $oneOrAll,
        string $searchValue,
        string $boolean = 'AND'
    ): self {
        $this->wheres[] = [
            'type' => 'JsonSearch',
            'column' => $column,
            'oneOrAll' => $oneOrAll,
            'searchValue' => $searchValue,
            'boolean' => strtoupper($boolean),
        ];

        $this->addBinding($searchValue, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Parse JSON path from column string.
     *
     * @param string $column Column with optional path (e.g., 'settings->theme')
     * @return array{0: string, 1: string|null} [column, path]
     */
    protected function parseJsonPath(string $column): array
    {
        if (!str_contains($column, '->')) {
            return [$column, null];
        }

        $parts = explode('->', $column, 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
