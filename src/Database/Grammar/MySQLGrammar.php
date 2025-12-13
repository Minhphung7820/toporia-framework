<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Grammar;

use Toporia\Framework\Database\Query\{Expression, QueryBuilder};

/**
 * MySQL Grammar Implementation
 *
 * Compiles QueryBuilder structures into MySQL-specific SQL syntax.
 *
 * MySQL Specifics:
 * - Backticks for identifiers: `table`, `column`
 * - Positional placeholders: ?
 * - LIMIT/OFFSET syntax
 * - ON DUPLICATE KEY UPDATE for upserts
 * - JSON operators: column->path, column->>path
 *
 * Performance Features:
 * - Compilation caching (inherited)
 * - Index hints support
 * - Query optimization hints
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
class MySQLGrammar extends Grammar
{
    /**
     * MySQL-specific features.
     *
     * @var array<string, bool>
     */
    protected array $features = [
        'window_functions' => true,  // MySQL 8.0+
        'returning_clause' => false, // Not supported
        'upsert' => true,            // ON DUPLICATE KEY UPDATE
        'json_operators' => true,    // column->path
        'cte' => true,               // WITH clause (MySQL 8.0+)
        'index_hints' => true,       // FORCE INDEX, USE INDEX
    ];

    /**
     * {@inheritdoc}
     *
     * MySQL uses backticks to wrap table names.
     */
    public function wrapTable(string $table): string
    {
        // Handle qualified tables (database.table)
        if (str_contains($table, '.')) {
            [$database, $tableName] = explode('.', $table, 2);
            // CRITICAL FIX: Escape backticks to prevent SQL injection
            $database = str_replace('`', '``', $database);
            $tableName = str_replace('`', '``', $tableName);
            return "`{$database}`.`{$tableName}`";
        }

        // Handle aliases (table AS alias or table alias)
        if (preg_match('/^(.+?)\s+(?:as\s+)?(.+)$/i', $table, $matches)) {
            $alias = str_replace('`', '``', $matches[2]); // Escape backticks
            return $this->wrapTable($matches[1]) . ' AS `' . $alias . '`';
        }

        // CRITICAL FIX: Escape backticks to prevent SQL injection
        $table = str_replace('`', '``', $table);
        return "`{$table}`";
    }

    /**
     * {@inheritdoc}
     *
     * MySQL uses backticks to wrap column names.
     */
    public function wrapColumn(string $column): string
    {
        // Don't wrap special keywords and functions
        if ($column === '*' || strtoupper($column) === 'NULL') {
            return $column;
        }

        // Don't wrap subqueries - they are already complete SQL
        // Use base class helper for code reusability
        if ($this->isSubquery($column)) {
            return $this->wrapSubqueryAlias($column, '`');
        }

        // Handle aggregate functions (COUNT(*), SUM(column), etc.)
        if (preg_match('/^(\w+)\s*\((.*)\)(\s+(as)\s+(.+))?$/i', $column, $matches)) {
            $function = strtoupper($matches[1]);
            $argument = trim($matches[2]);
            $hasAlias = !empty($matches[3]);
            $asKeyword = $matches[4] ?? 'AS'; // Preserve original case of AS/as
            $alias = $matches[5] ?? null;

            // Wrap argument if it's not * or a number
            if ($argument !== '*' && !is_numeric($argument)) {
                $argument = $this->wrapColumn($argument);
            }

            $result = "{$function}({$argument})";

            if ($hasAlias && $alias) {
                // CRITICAL FIX: Escape backticks to prevent SQL injection
                $alias = str_replace('`', '``', $alias);
                $result .= " {$asKeyword} `{$alias}`";
            }

            return $result;
        }

        // Handle qualified columns (table.column)
        if (str_contains($column, '.')) {
            [$table, $col] = explode('.', $column, 2);
            // CRITICAL FIX: Escape backticks to prevent SQL injection
            if ($col !== '*') {
                $col = str_replace('`', '``', $col);
                return $this->wrapTable($table) . ".`{$col}`";
            }
            return $this->wrapTable($table) . '.*';
        }

        // Handle aliases (column AS alias or column as alias)
        if (preg_match('/^(.+?)\s+(as)\s+(.+)$/i', $column, $matches)) {
            $column = $matches[1];
            $asKeyword = $matches[2]; // Preserve original case of AS/as
            $alias = $matches[3];
            // CRITICAL FIX: Escape backticks to prevent SQL injection
            $alias = str_replace('`', '``', $alias);
            return $this->wrapColumn($column) . " {$asKeyword} `{$alias}`";
        }

        // Handle JSON operators (column->path or column->>path)
        if (preg_match('/^(.+?)(->|->>\')(.+)$/', $column, $matches)) {
            // CRITICAL FIX: Escape backticks to prevent SQL injection
            $baseColumn = str_replace('`', '``', $matches[1]);
            $baseColumn = "`{$baseColumn}`";
            $operator = $matches[2];
            $path = $matches[3];
            return "{$baseColumn}{$operator}{$path}";
        }

        // CRITICAL FIX: Escape backticks to prevent SQL injection
        $column = str_replace('`', '``', $column);
        return "`{$column}`";
    }

    /**
     * {@inheritdoc}
     *
     * MySQL uses positional placeholders.
     */
    public function getParameterPlaceholder(int $index): string
    {
        return '?';
    }

    /**
     * {@inheritdoc}
     */
    public function compileInsert(QueryBuilder $query, array $values): string
    {
        $table = $this->wrapTable($query->getTable());

        // Single row insert
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
            // Multiple rows
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
        $bindings = [];
        foreach ($values as $column => $value) {
            $wrappedColumn = $this->wrapColumn($column);

            // Support Expression objects from DB::raw() (Toporia style)
            if ($value instanceof Expression) {
                $sets[] = "{$wrappedColumn} = " . (string) $value;
            } else {
                $sets[] = "{$wrappedColumn} = ?";
                $bindings[] = $value;
            }
        }

        $setClause = implode(', ', $sets);
        $whereClause = $this->compileWheres($query);

        // Store bindings for later use (only non-Expression values)
        // Note: This modifies the query's bindings, but that's handled in QueryBuilder::update()
        return "UPDATE {$table} SET {$setClause} {$whereClause}";
    }

    /**
     * {@inheritdoc}
     */
    public function compileDelete(QueryBuilder $query): string
    {
        $table = $this->wrapTable($query->getTable());
        $whereClause = $this->compileWheres($query);

        // Remove trailing space if no WHERE clause
        if (empty(trim($whereClause))) {
            return "DELETE FROM {$table}";
        }

        return "DELETE FROM {$table} {$whereClause}";
    }

    /**
     * Compile INSERT with ON DUPLICATE KEY UPDATE (MySQL upsert).
     *
     * @param QueryBuilder $query
     * @param array<string, mixed> $values
     * @param array<string> $updateColumns Columns to update on conflict
     * @return string
     */
    public function compileUpsert(QueryBuilder $query, array $values, array $updateColumns): string
    {
        $insertSql = $this->compileInsert($query, $values);

        $updates = [];
        foreach ($updateColumns as $column) {
            $wrappedColumn = $this->wrapColumn($column);
            $updates[] = "{$wrappedColumn} = VALUES({$wrappedColumn})";
        }

        $onDuplicate = implode(', ', $updates);

        return "{$insertSql} ON DUPLICATE KEY UPDATE {$onDuplicate}";
    }

    /**
     * Compile TRUNCATE statement.
     *
     * @param QueryBuilder $query
     * @return string
     */
    public function compileTruncate(QueryBuilder $query): string
    {
        $table = $this->wrapTable($query->getTable());
        return "TRUNCATE TABLE {$table}";
    }

    /**
     * Compile index hint for query optimization.
     *
     * @param string $type FORCE, USE, IGNORE
     * @param string $indexName
     * @return string
     */
    public function compileIndexHint(string $type, string $indexName): string
    {
        $type = strtoupper($type);
        return "{$type} INDEX (`{$indexName}`)";
    }
}
