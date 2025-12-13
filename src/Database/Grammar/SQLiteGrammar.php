<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Grammar;

use Toporia\Framework\Database\Query\{Expression, QueryBuilder};

/**
 * SQLite Grammar Implementation
 *
 * Compiles QueryBuilder structures into SQLite-specific SQL syntax.
 *
 * SQLite Specifics:
 * - Double quotes for identifiers: "table", "column"
 * - Positional placeholders: ?
 * - LIMIT/OFFSET syntax (similar to MySQL)
 * - ON CONFLICT for upserts (INSERT OR REPLACE)
 * - Limited JSON support (SQLite 3.38+)
 * - No window functions in older versions
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Grammar
 * @since       2025-01-23
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class SQLiteGrammar extends Grammar
{
    /**
     * SQLite-specific features.
     *
     * @var array<string, bool>
     */
    protected array $features = [
        'window_functions' => true,  // SQLite 3.25+
        'returning_clause' => true,  // SQLite 3.35+
        'upsert' => true,            // INSERT OR REPLACE
        'json_operators' => true,    // SQLite 3.38+ (limited)
        'cte' => true,               // WITH clause
    ];

    /**
     * {@inheritdoc}
     *
     * SQLite uses double quotes to wrap identifiers.
     */
    public function wrapTable(string $table): string
    {
        // Handle qualified tables (database.table)
        if (str_contains($table, '.')) {
            [$database, $tableName] = explode('.', $table, 2);
            // CRITICAL FIX: Escape double quotes to prevent SQL injection
            $database = str_replace('"', '""', $database);
            $tableName = str_replace('"', '""', $tableName);
            return "\"{$database}\".\"{$tableName}\"";
        }

        // Handle aliases
        if (preg_match('/^(.+?)\s+(?:as\s+)?(.+)$/i', $table, $matches)) {
            $alias = str_replace('"', '""', $matches[2]); // Escape double quotes
            return $this->wrapTable($matches[1]) . ' AS "' . $alias . '"';
        }

        // CRITICAL FIX: Escape double quotes to prevent SQL injection
        $table = str_replace('"', '""', $table);
        return "\"{$table}\"";
    }

    /**
     * {@inheritdoc}
     *
     * SQLite uses double quotes to wrap column names.
     */
    public function wrapColumn(string $column): string
    {
        if ($column === '*' || strtoupper($column) === 'NULL') {
            return $column;
        }

        // Don't wrap subqueries - they are already complete SQL
        // Use base class helper for code reusability
        if ($this->isSubquery($column)) {
            return $this->wrapSubqueryAlias($column, '"');
        }

        // Handle aggregate functions
        if (preg_match('/^(\w+)\s*\((.*)\)(\s+(as)\s+(.+))?$/i', $column, $matches)) {
            $function = strtoupper($matches[1]);
            $argument = trim($matches[2]);
            $hasAlias = !empty($matches[3]);
            $asKeyword = $matches[4] ?? 'AS'; // Preserve original case of AS/as
            $alias = $matches[5] ?? null;

            if ($argument !== '*' && !is_numeric($argument)) {
                $argument = $this->wrapColumn($argument);
            }

            $result = "{$function}({$argument})";
            if ($hasAlias && $alias) {
                // CRITICAL FIX: Escape double quotes to prevent SQL injection
                $alias = str_replace('"', '""', $alias);
                return $result . " {$asKeyword} \"{$alias}\"";
            }
            return $result;
        }

        // Handle qualified columns
        if (str_contains($column, '.')) {
            [$table, $col] = explode('.', $column, 2);
            // CRITICAL FIX: Escape double quotes to prevent SQL injection
            if ($col !== '*') {
                $col = str_replace('"', '""', $col);
                return $this->wrapTable($table) . ".\"{$col}\"";
            }
            return $this->wrapTable($table) . '.*';
        }

        // Handle aliases (column AS alias or column as alias)
        if (preg_match('/^(.+?)\s+(as)\s+(.+)$/i', $column, $matches)) {
            $column = $matches[1];
            $asKeyword = $matches[2]; // Preserve original case of AS/as
            $alias = $matches[3];
            // CRITICAL FIX: Escape double quotes to prevent SQL injection
            $alias = str_replace('"', '""', $alias);
            return $this->wrapColumn($column) . " {$asKeyword} \"{$alias}\"";
        }

        // Handle JSON operators (SQLite 3.38+)
        if (preg_match('/^(.+?)(->|->>)(.+)$/', $column, $matches)) {
            // CRITICAL FIX: Escape double quotes to prevent SQL injection
            $baseColumn = str_replace('"', '""', $matches[1]);
            $baseColumn = "\"{$baseColumn}\"";
            $operator = $matches[2];
            $path = $matches[3];
            return "{$baseColumn}{$operator}{$path}";
        }

        // CRITICAL FIX: Escape double quotes to prevent SQL injection
        $column = str_replace('"', '""', $column);
        return "\"{$column}\"";
    }

    /**
     * {@inheritdoc}
     */
    public function getParameterPlaceholder(int $index): string
    {
        return '?';
    }

    /**
     * {@inheritdoc}
     *
     * SQLite uses LIMIT (same as MySQL).
     */
    public function compileLimit(int $limit): string
    {
        return "LIMIT {$limit}";
    }

    /**
     * {@inheritdoc}
     *
     * SQLite uses OFFSET (same as MySQL).
     */
    public function compileOffset(int $offset): string
    {
        return "OFFSET {$offset}";
    }

    /**
     * {@inheritdoc}
     */
    public function compileInsert(QueryBuilder $query, array $values): string
    {
        $table = $this->wrapTable($query->getTable());

        if (isset($values[0]) && is_array($values[0])) {
            $columns = array_keys($values[0]);
        } else {
            $columns = array_keys($values);
            $values = [$values];
        }

        $wrappedColumns = implode(', ', array_map(
            fn($col) => $this->wrapColumn($col),
            $columns
        ));

        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        if (count($values) > 1) {
            $allPlaceholders = implode(', ', array_fill(0, count($values), $placeholders));
            return "INSERT INTO {$table} ({$wrappedColumns}) VALUES {$allPlaceholders}";
        }

        return "INSERT INTO {$table} ({$wrappedColumns}) VALUES {$placeholders}";
    }

    /**
     * {@inheritdoc}
     */
    public function compileUpdate(QueryBuilder $query, array $values): string
    {
        $table = $this->wrapTable($query->getTable());

        $sets = [];
        foreach ($values as $column => $value) {
            $wrappedColumn = $this->wrapColumn($column);

            // Support Expression objects from DB::raw() (Toporia style)
            if ($value instanceof Expression) {
                $sets[] = "{$wrappedColumn} = " . (string) $value;
            } else {
                $sets[] = "{$wrappedColumn} = ?";
            }
        }

        $setClause = implode(', ', $sets);
        $whereClause = $this->compileWheres($query);

        return "UPDATE {$table} SET {$setClause} {$whereClause}";
    }

    /**
     * {@inheritdoc}
     */
    public function compileDelete(QueryBuilder $query): string
    {
        $table = $this->wrapTable($query->getTable());
        $whereClause = $this->compileWheres($query);

        return "DELETE FROM {$table} {$whereClause}";
    }

    /**
     * Compile INSERT OR REPLACE (SQLite upsert).
     *
     * @param QueryBuilder $query
     * @param array<string, mixed> $values
     * @return string
     */
    public function compileUpsert(QueryBuilder $query, array $values): string
    {
        $table = $this->wrapTable($query->getTable());

        if (isset($values[0]) && is_array($values[0])) {
            $columns = array_keys($values[0]);
        } else {
            $columns = array_keys($values);
            $values = [$values];
        }

        $wrappedColumns = implode(', ', array_map(
            fn($col) => $this->wrapColumn($col),
            $columns
        ));

        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        return "INSERT OR REPLACE INTO {$table} ({$wrappedColumns}) VALUES {$placeholders}";
    }

    /**
     * Compile VACUUM statement (SQLite optimization).
     *
     * @return string
     */
    public function compileVacuum(): string
    {
        return 'VACUUM';
    }

    // =========================================================================
    // DATE/TIME FUNCTIONS - SQLite-specific syntax using strftime()
    // =========================================================================

    /**
     * Compile DATE() WHERE clause.
     *
     * SQLite: DATE(column) works the same as MySQL.
     *
     * @param array<string, mixed> $where
     * @return string
     */
    protected function compileDateBasicWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $operator = $where['operator'];
        return "DATE({$column}) {$operator} ?";
    }

    /**
     * Compile MONTH() WHERE clause.
     *
     * SQLite uses strftime('%m', column) instead of MONTH(column).
     * CAST to INTEGER to match numeric comparison (removes leading zero).
     *
     * @param array<string, mixed> $where
     * @return string
     */
    protected function compileMonthBasicWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $operator = $where['operator'];
        return "CAST(strftime('%m', {$column}) AS INTEGER) {$operator} ?";
    }

    /**
     * Compile DAY() WHERE clause.
     *
     * SQLite uses strftime('%d', column) instead of DAY(column).
     * CAST to INTEGER to match numeric comparison (removes leading zero).
     *
     * @param array<string, mixed> $where
     * @return string
     */
    protected function compileDayBasicWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $operator = $where['operator'];
        return "CAST(strftime('%d', {$column}) AS INTEGER) {$operator} ?";
    }

    /**
     * Compile YEAR() WHERE clause.
     *
     * SQLite uses strftime('%Y', column) instead of YEAR(column).
     * CAST to INTEGER for numeric comparison.
     *
     * @param array<string, mixed> $where
     * @return string
     */
    protected function compileYearBasicWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $operator = $where['operator'];
        return "CAST(strftime('%Y', {$column}) AS INTEGER) {$operator} ?";
    }

    /**
     * Compile TIME() WHERE clause.
     *
     * SQLite: TIME(column) works the same as MySQL.
     *
     * @param array<string, mixed> $where
     * @return string
     */
    protected function compileTimeBasicWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $operator = $where['operator'];
        return "TIME({$column}) {$operator} ?";
    }

    /**
     * {@inheritdoc}
     *
     * SQLite uses RANDOM() instead of RAND().
     */
    public function compileRandomOrderFunction(): string
    {
        return 'RANDOM()';
    }

    // =========================================================================
    // JSON WHERE COMPILATION - SQLite-specific syntax (JSON1 extension)
    // SQLite uses json_extract(), json_type(), etc.
    // Requires SQLite 3.38+ for full JSON support
    // =========================================================================

    /**
     * {@inheritdoc}
     *
     * SQLite: json_extract(column, '$.path') operator ?
     * Uses json_extract() for value extraction.
     */
    protected function compileJsonWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $path = '$.' . str_replace('->', '.', $where['path'] ?? '');
        $operator = $where['operator'];

        return "json_extract({$column}, '{$path}') {$operator} ?";
    }

    /**
     * {@inheritdoc}
     *
     * SQLite: json_type(column, '$.key') IS NOT NULL
     * Uses json_type() to check if a key exists.
     */
    protected function compileJsonContainsKeyWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $key = $where['key'];
        $path = '$.' . str_replace('.', '.', $key);

        return "json_type({$column}, '{$path}') IS NOT NULL";
    }

    /**
     * {@inheritdoc}
     *
     * SQLite: json_type(column, '$.key') IS NULL
     */
    protected function compileJsonDoesntContainKeyWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $key = $where['key'];
        $path = '$.' . str_replace('.', '.', $key);

        return "json_type({$column}, '{$path}') IS NULL";
    }

    /**
     * {@inheritdoc}
     *
     * SQLite: Uses json_each() to check array overlap.
     * This is a subquery approach since SQLite doesn't have direct overlap function.
     */
    protected function compileJsonOverlapsWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);

        // SQLite doesn't have JSON_OVERLAPS, use EXISTS with json_each
        // The binding will be the JSON array to check against
        return "EXISTS (SELECT 1 FROM json_each({$column}) AS a, json_each(?) AS b WHERE a.value = b.value)";
    }

    /**
     * {@inheritdoc}
     *
     * SQLite: json_type(column, '$.path') = 'type'
     * SQLite type names: null, true, false, integer, real, text, array, object
     */
    protected function compileJsonTypeWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $path = '$.' . str_replace('->', '.', $where['path'] ?? '');
        $jsonType = strtolower($where['jsonType']);

        // Map MySQL/general type names to SQLite
        $typeMap = [
            'object' => 'object',
            'array' => 'array',
            'string' => 'text',
            'number' => 'integer', // Could also be 'real'
            'integer' => 'integer',
            'double' => 'real',
            'boolean' => 'true', // SQLite returns 'true' or 'false', not 'boolean'
            'null' => 'null',
        ];

        $sqliteType = $typeMap[$jsonType] ?? $jsonType;

        // For boolean, we need to check both 'true' and 'false'
        if ($jsonType === 'boolean') {
            return "(json_type({$column}, '{$path}') IN ('true', 'false'))";
        }

        // For number, check both integer and real
        if ($jsonType === 'number') {
            return "(json_type({$column}, '{$path}') IN ('integer', 'real'))";
        }

        return "json_type({$column}, '{$path}') = '{$sqliteType}'";
    }

    /**
     * {@inheritdoc}
     *
     * SQLite doesn't have JSON_DEPTH function.
     * Return always true as fallback.
     */
    protected function compileJsonDepthWhere(array $where): string
    {
        // SQLite doesn't have a built-in JSON_DEPTH function
        // Return always true (feature not supported)
        return '1 = 1';
    }

    /**
     * {@inheritdoc}
     *
     * SQLite: json_valid(column) (SQLite 3.38+)
     */
    protected function compileJsonValidWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $valid = $where['valid'] ?? true;

        if ($valid) {
            return "json_valid({$column})";
        }

        return "NOT json_valid({$column})";
    }

    /**
     * {@inheritdoc}
     *
     * SQLite: Use instr() for text search since no JSON_SEARCH equivalent.
     */
    protected function compileJsonSearchWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);

        // SQLite doesn't have JSON_SEARCH, use text search
        return "instr({$column}, ?) > 0";
    }

    // =========================================================================
    // JSON SELECT/ORDER COMPILATION - SQLite-specific syntax (JSON1 extension)
    // =========================================================================

    /**
     * {@inheritdoc}
     *
     * SQLite: Uses json_extract() for value extraction.
     * Casting uses CAST AS INTEGER/REAL (no SIGNED/UNSIGNED in SQLite).
     */
    public function compileJsonSelect(string $column, string $path, ?string $cast = null, ?string $alias = null): string
    {
        $wrappedColumn = $this->wrapColumn($column);
        $jsonPath = '$.' . str_replace('->', '.', $path);

        $expression = match ($cast) {
            'integer', 'int' => "CAST(json_extract({$wrappedColumn}, '{$jsonPath}') AS INTEGER)",
            'float', 'decimal' => "CAST(json_extract({$wrappedColumn}, '{$jsonPath}') AS REAL)",
            'boolean', 'bool' => "CAST(json_extract({$wrappedColumn}, '{$jsonPath}') AS INTEGER)",
            default => "json_extract({$wrappedColumn}, '{$jsonPath}')",
        };

        if ($alias !== null) {
            $expression .= " AS \"{$alias}\"";
        }

        return $expression;
    }

    /**
     * {@inheritdoc}
     *
     * SQLite: Uses json_extract() for ordering by JSON values.
     */
    public function compileJsonOrder(string $column, string $path, string $direction = 'ASC', ?string $cast = null): string
    {
        $wrappedColumn = $this->wrapColumn($column);
        $jsonPath = '$.' . str_replace('->', '.', $path);

        $expression = match ($cast) {
            'integer', 'int' => "CAST(json_extract({$wrappedColumn}, '{$jsonPath}') AS INTEGER)",
            'float', 'decimal' => "CAST(json_extract({$wrappedColumn}, '{$jsonPath}') AS REAL)",
            default => "json_extract({$wrappedColumn}, '{$jsonPath}')",
        };

        return "{$expression} " . strtoupper($direction);
    }
}
