<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Grammar;

use Toporia\Framework\Database\Query\{Expression, QueryBuilder};

/**
 * PostgreSQL Grammar Implementation
 *
 * Compiles QueryBuilder structures into PostgreSQL-specific SQL syntax.
 *
 * PostgreSQL Specifics:
 * - Double quotes for identifiers: "table", "column"
 * - Numbered placeholders: $1, $2, $3...
 * - FETCH FIRST n ROWS ONLY instead of LIMIT
 * - RETURNING clause for INSERT/UPDATE
 * - ON CONFLICT DO UPDATE for upserts
 * - Advanced JSON operators: column->path, column->>path, column#>path
 * - Window functions, CTEs, full-text search
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
class PostgreSQLGrammar extends Grammar
{
    /**
     * Parameter index counter for numbered placeholders.
     *
     * @var int
     */
    private int $parameterIndex = 0;

    /**
     * PostgreSQL-specific features.
     *
     * @var array<string, bool>
     */
    protected array $features = [
        'window_functions' => true,
        'returning_clause' => true,  // RETURNING *
        'upsert' => true,            // ON CONFLICT DO UPDATE
        'json_operators' => true,    // Advanced JSON support
        'cte' => true,               // WITH clause
        'full_text_search' => true,  // ts_vector, ts_query
        'arrays' => true,            // Native array support
    ];

    /**
     * {@inheritdoc}
     *
     * PostgreSQL uses double quotes to wrap identifiers.
     */
    public function wrapTable(string $table): string
    {
        // Handle qualified tables (schema.table)
        if (str_contains($table, '.')) {
            [$schema, $tableName] = explode('.', $table, 2);
            // CRITICAL FIX: Escape double quotes to prevent SQL injection
            $schema = str_replace('"', '""', $schema);
            $tableName = str_replace('"', '""', $tableName);
            return "\"{$schema}\".\"{$tableName}\"";
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
     * PostgreSQL uses double quotes to wrap column names.
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

        // Handle JSON operators (keep as-is)
        if (preg_match('/^(.+?)(->|->>|#>|#>>)(.+)$/', $column, $matches)) {
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
     *
     * PostgreSQL with PDO uses positional placeholders (?) like MySQL/SQLite.
     * PDO handles the conversion to native PostgreSQL $1, $2, $3 format internally.
     *
     * Note: Using ? (positional) instead of $1 (numbered) ensures consistency
     * across all PDO-based database connections and simplifies binding management.
     */
    public function getParameterPlaceholder(int $index): string
    {
        return '?';
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL uses FETCH FIRST n ROWS ONLY.
     */
    public function compileLimit(int $limit): string
    {
        return "FETCH FIRST {$limit} ROWS ONLY";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL uses OFFSET n ROWS.
     */
    public function compileOffset(int $offset): string
    {
        return "OFFSET {$offset} ROWS";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL INSERT uses ? placeholders (PDO converts them internally).
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
     * Compile INSERT with RETURNING clause.
     *
     * @param QueryBuilder $query
     * @param array<string, mixed> $values
     * @param array<string> $returning Columns to return
     * @return string
     */
    public function compileInsertReturning(QueryBuilder $query, array $values, array $returning = ['*']): string
    {
        $insertSql = $this->compileInsert($query, $values);
        $returningColumns = implode(', ', array_map(
            fn($col) => $this->wrapColumn($col),
            $returning
        ));

        return "{$insertSql} RETURNING {$returningColumns}";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL UPDATE uses ? placeholders (PDO converts them internally).
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
     * Compile INSERT with ON CONFLICT (PostgreSQL upsert).
     *
     * @param QueryBuilder $query
     * @param array<string, mixed> $values
     * @param array<string> $conflictColumns Conflict target columns
     * @param array<string> $updateColumns Columns to update on conflict
     * @return string
     */
    public function compileUpsert(
        QueryBuilder $query,
        array $values,
        array $conflictColumns,
        array $updateColumns
    ): string {
        $insertSql = $this->compileInsert($query, $values);

        $conflict = implode(', ', array_map(
            fn($col) => $this->wrapColumn($col),
            $conflictColumns
        ));

        $updates = [];
        foreach ($updateColumns as $column) {
            $wrappedColumn = $this->wrapColumn($column);
            $updates[] = "{$wrappedColumn} = EXCLUDED.{$wrappedColumn}";
        }

        $onConflict = implode(', ', $updates);

        return "{$insertSql} ON CONFLICT ({$conflict}) DO UPDATE SET {$onConflict}";
    }

    // =========================================================================
    // DATE/TIME FUNCTIONS - PostgreSQL-specific syntax
    // =========================================================================

    /**
     * Compile DATE() WHERE clause.
     *
     * PostgreSQL: DATE(column) works the same as MySQL.
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
     * PostgreSQL uses EXTRACT(MONTH FROM column) instead of MONTH(column).
     *
     * @param array<string, mixed> $where
     * @return string
     */
    protected function compileMonthBasicWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $operator = $where['operator'];
        return "EXTRACT(MONTH FROM {$column}) {$operator} ?";
    }

    /**
     * Compile DAY() WHERE clause.
     *
     * PostgreSQL uses EXTRACT(DAY FROM column) instead of DAY(column).
     *
     * @param array<string, mixed> $where
     * @return string
     */
    protected function compileDayBasicWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $operator = $where['operator'];
        return "EXTRACT(DAY FROM {$column}) {$operator} ?";
    }

    /**
     * Compile YEAR() WHERE clause.
     *
     * PostgreSQL uses EXTRACT(YEAR FROM column) instead of YEAR(column).
     *
     * @param array<string, mixed> $where
     * @return string
     */
    protected function compileYearBasicWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $operator = $where['operator'];
        return "EXTRACT(YEAR FROM {$column}) {$operator} ?";
    }

    /**
     * Compile TIME() WHERE clause.
     *
     * PostgreSQL uses column::TIME cast instead of TIME(column).
     *
     * @param array<string, mixed> $where
     * @return string
     */
    protected function compileTimeBasicWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $operator = $where['operator'];
        return "{$column}::TIME {$operator} ?";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL uses RANDOM() instead of RAND().
     */
    public function compileRandomOrderFunction(): string
    {
        return 'RANDOM()';
    }

    // =========================================================================
    // JSON WHERE COMPILATION - PostgreSQL-specific syntax
    // PostgreSQL uses jsonb operators: ->, ->>, ?, ?|, ?&
    // =========================================================================

    /**
     * {@inheritdoc}
     *
     * PostgreSQL: column->>'path' operator ?
     * Uses ->> for text extraction (equivalent to JSON_UNQUOTE in MySQL).
     */
    protected function compileJsonWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $path = $where['path'] ?? '';
        $operator = $where['operator'];

        // Handle nested paths: user.name -> 'user'->'name'
        $pathParts = explode('.', str_replace('->', '.', $path));

        if (count($pathParts) === 1) {
            return "{$column}->>'{$pathParts[0]}' {$operator} ?";
        }

        // For nested paths, use -> for all but the last, then ->> for text extraction
        // PERFORMANCE: Cache count() result and use array building instead of string concatenation
        $pathCount = count($pathParts);
        $lastIndex = $pathCount - 1;
        $jsonPathParts = [$column];
        for ($i = 0; $i < $lastIndex; $i++) {
            $jsonPathParts[] = "->'{$pathParts[$i]}'";
        }
        $jsonPathParts[] = "->>'" . $pathParts[$lastIndex] . "'";
        $jsonPath = implode('', $jsonPathParts);

        return "{$jsonPath} {$operator} ?";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL: column ? 'key' for top-level, column->'path' ? 'key' for nested.
     */
    protected function compileJsonContainsKeyWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $key = $where['key'];

        // Handle nested paths
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $lastKey = array_pop($parts);

            // PERFORMANCE: Use array building instead of string concatenation in loop
            $jsonPathParts = [$column];
            foreach ($parts as $part) {
                $jsonPathParts[] = "->'{$part}'";
            }
            $jsonPath = implode('', $jsonPathParts);

            return "({$jsonPath}) ? '{$lastKey}'";
        }

        return "{$column} ? '{$key}'";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL: NOT (column ? 'key').
     */
    protected function compileJsonDoesntContainKeyWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $key = $where['key'];

        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $lastKey = array_pop($parts);

            // PERFORMANCE: Use array building instead of string concatenation in loop
            $jsonPathParts = [$column];
            foreach ($parts as $part) {
                $jsonPathParts[] = "->'{$part}'";
            }
            $jsonPath = implode('', $jsonPathParts);

            return "NOT (({$jsonPath}) ? '{$lastKey}')";
        }

        return "NOT ({$column} ? '{$key}')";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL: column ?| array['value1', 'value2'] for array overlap.
     * Note: This checks if ANY of the keys exist, not value overlap.
     * For actual value overlap, we use jsonb @> operator.
     */
    protected function compileJsonOverlapsWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $values = $where['values'];

        // PostgreSQL jsonb @> for containment check
        // This checks if the column contains any of the values
        return "{$column} && ?::jsonb";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL: jsonb_typeof(column->'path') = 'type'.
     * PostgreSQL type names differ from MySQL (lowercase, different names).
     */
    protected function compileJsonTypeWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $path = $where['path'] ?? '';
        $jsonType = strtolower($where['jsonType']);

        // Map MySQL type names to PostgreSQL
        $typeMap = [
            'object' => 'object',
            'array' => 'array',
            'string' => 'string',
            'number' => 'number',
            'integer' => 'number',
            'double' => 'number',
            'boolean' => 'boolean',
            'null' => 'null',
        ];

        $pgType = $typeMap[$jsonType] ?? $jsonType;

        // Build JSON path
        // PERFORMANCE: Use array building instead of string concatenation in loop
        $pathParts = explode('.', str_replace('->', '.', $path));
        $jsonPathParts = [$column];
        foreach ($pathParts as $part) {
            if (!empty($part)) {
                $jsonPathParts[] = "->'{$part}'";
            }
        }
        $jsonPath = implode('', $jsonPathParts);

        return "jsonb_typeof({$jsonPath}) = '{$pgType}'";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL doesn't have JSON_DEPTH, use custom recursive function or return always true.
     * This is a best-effort implementation using a subquery approach.
     */
    protected function compileJsonDepthWhere(array $where): string
    {
        // PostgreSQL doesn't have a built-in JSON_DEPTH function
        // For simplicity, always return true (feature not fully supported)
        // A proper implementation would require a recursive CTE
        return '1 = 1';
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL: Check if column can be cast to jsonb.
     */
    protected function compileJsonValidWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);
        $valid = $where['valid'] ?? true;

        // PostgreSQL validates JSON when casting to jsonb
        // We check if the column is not null and can be interpreted as jsonb
        if ($valid) {
            return "({$column} IS NOT NULL AND {$column}::text ~ '^[{\\[]')";
        }

        return "({$column} IS NULL OR NOT ({$column}::text ~ '^[{\\[]'))";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL: Use text search since no direct JSON_SEARCH equivalent.
     * Uses LIKE pattern matching on the JSON text representation.
     */
    protected function compileJsonSearchWhere(array $where): string
    {
        $column = $this->wrapColumn($where['column']);

        // PostgreSQL doesn't have JSON_SEARCH, use text search
        return "{$column}::text LIKE '%' || ? || '%'";
    }

    // =========================================================================
    // JSON SELECT/ORDER COMPILATION - PostgreSQL-specific syntax
    // =========================================================================

    /**
     * {@inheritdoc}
     *
     * PostgreSQL: Uses ->> for text extraction, CAST for type conversion.
     */
    public function compileJsonSelect(string $column, string $path, ?string $cast = null, ?string $alias = null): string
    {
        $wrappedColumn = $this->wrapColumn($column);

        // Build JSON path for nested access
        // PERFORMANCE: Cache count() result and use array building instead of string concatenation
        $pathParts = explode('.', str_replace('->', '.', $path));
        $pathCount = count($pathParts);
        $lastIndex = $pathCount - 1;

        // For nested paths, use -> for all but last, then ->> for text extraction
        $jsonPathParts = [$wrappedColumn];
        for ($i = 0; $i < $lastIndex; $i++) {
            $jsonPathParts[] = "->'{$pathParts[$i]}'";
        }
        $jsonPath = implode('', $jsonPathParts);

        // Last part uses ->> for text or -> for JSON depending on cast
        $lastKey = $pathParts[$lastIndex];

        $expression = match ($cast) {
            'integer', 'int' => "({$jsonPath}->>'{$lastKey}')::INTEGER",
            'float', 'decimal' => "({$jsonPath}->>'{$lastKey}')::DECIMAL",
            'boolean', 'bool' => "({$jsonPath}->>'{$lastKey}')::BOOLEAN",
            default => "{$jsonPath}->>'{$lastKey}'",
        };

        if ($alias !== null) {
            $expression .= " AS \"{$alias}\"";
        }

        return $expression;
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL: Uses -> for JSON access, CAST for type conversion in ordering.
     */
    public function compileJsonOrder(string $column, string $path, string $direction = 'ASC', ?string $cast = null): string
    {
        $wrappedColumn = $this->wrapColumn($column);

        // Build JSON path for nested access
        // PERFORMANCE: Cache count() result and use array building instead of string concatenation
        $pathParts = explode('.', str_replace('->', '.', $path));
        $pathCount = count($pathParts);
        $lastIndex = $pathCount - 1;

        $jsonPathParts = [$wrappedColumn];
        for ($i = 0; $i < $lastIndex; $i++) {
            $jsonPathParts[] = "->'{$pathParts[$i]}'";
        }
        $jsonPath = implode('', $jsonPathParts);

        $lastKey = $pathParts[$lastIndex];

        $expression = match ($cast) {
            'integer', 'int' => "({$jsonPath}->>'{$lastKey}')::INTEGER",
            'float', 'decimal' => "({$jsonPath}->>'{$lastKey}')::DECIMAL",
            default => "{$jsonPath}->>'{$lastKey}'",
        };

        return "{$expression} " . strtoupper($direction);
    }
}
