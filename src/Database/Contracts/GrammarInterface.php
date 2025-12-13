<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Contracts;

use Toporia\Framework\Database\Query\QueryBuilder;

/**
 * Interface GrammarInterface
 *
 * Defines the contract for SQL grammar implementations.
 * Each database (MySQL, PostgreSQL, SQLite) has its own Grammar
 * that knows how to compile QueryBuilder structures into SQL.
 *
 * Design Pattern: Strategy Pattern
 * - Different SQL dialects are different strategies
 * - QueryBuilder uses Grammar to compile to SQL
 *
 * SOLID Principles:
 * - Single Responsibility: Only compile queries to SQL
 * - Open/Closed: Open for extension (new databases), closed for modification
 * - Dependency Inversion: QueryBuilder depends on this interface, not concrete Grammar
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Contracts
 * @since       2025-01-23
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface GrammarInterface
{
    /**
     * Compile a SELECT query to SQL.
     *
     * Performance: Compilation is cached per query structure.
     * Different databases produce different SQL:
     * - MySQL: SELECT * FROM `users` WHERE `id` = ? LIMIT 10
     * - PostgreSQL: SELECT * FROM "users" WHERE "id" = $1 FETCH FIRST 10 ROWS ONLY
     * - SQLite: SELECT * FROM "users" WHERE "id" = ? LIMIT 10
     *
     * @param QueryBuilder $query Query builder instance with query structure
     * @return string Complete SQL SELECT statement
     */
    public function compileSelect(QueryBuilder $query): string;

    /**
     * Compile an INSERT query to SQL.
     *
     * Different databases handle RETURNING clause differently:
     * - MySQL: Returns last insert ID via connection
     * - PostgreSQL: RETURNING * returns all columns
     * - SQLite: Returns last insert ID
     *
     * @param QueryBuilder $query Query builder instance
     * @param array<string, mixed> $values Values to insert
     * @return string Complete SQL INSERT statement
     */
    public function compileInsert(QueryBuilder $query, array $values): string;

    /**
     * Compile an UPDATE query to SQL.
     *
     * @param QueryBuilder $query Query builder instance
     * @param array<string, mixed> $values Values to update
     * @return string Complete SQL UPDATE statement
     */
    public function compileUpdate(QueryBuilder $query, array $values): string;

    /**
     * Compile a DELETE query to SQL.
     *
     * @param QueryBuilder $query Query builder instance
     * @return string Complete SQL DELETE statement
     */
    public function compileDelete(QueryBuilder $query): string;

    /**
     * Wrap a table name with database-specific identifier quotes.
     *
     * - MySQL: `table_name`
     * - PostgreSQL/SQLite: "table_name"
     *
     * Performance: Results are cached.
     *
     * @param string $table Table name
     * @return string Quoted table name
     */
    public function wrapTable(string $table): string;

    /**
     * Wrap a column name with database-specific identifier quotes.
     *
     * - MySQL: `column_name`
     * - PostgreSQL/SQLite: "column_name"
     *
     * Handles qualified columns (table.column) and aliases (column AS alias).
     *
     * @param string $column Column name or expression
     * @return string Quoted column name
     */
    public function wrapColumn(string $column): string;

    /**
     * Get the parameter placeholder for prepared statements.
     *
     * - MySQL/SQLite: ? (positional)
     * - PostgreSQL: $1, $2, $3... (numbered)
     *
     * @param int $index Parameter index (0-based)
     * @return string Placeholder string
     */
    public function getParameterPlaceholder(int $index): string;

    /**
     * Get the database-specific LIMIT clause syntax.
     *
     * - MySQL/SQLite: LIMIT 10
     * - PostgreSQL: FETCH FIRST 10 ROWS ONLY
     *
     * @param int $limit Number of rows to limit
     * @return string LIMIT clause
     */
    public function compileLimit(int $limit): string;

    /**
     * Get the database-specific OFFSET clause syntax.
     *
     * - MySQL/SQLite: OFFSET 20
     * - PostgreSQL: OFFSET 20 ROWS
     *
     * @param int $offset Number of rows to skip
     * @return string OFFSET clause
     */
    public function compileOffset(int $offset): string;

    /**
     * Check if the database supports a specific feature.
     *
     * Features:
     * - window_functions: Window functions (ROW_NUMBER, etc.)
     * - returning_clause: RETURNING * in INSERT/UPDATE
     * - upsert: ON CONFLICT DO UPDATE (PostgreSQL) or ON DUPLICATE KEY UPDATE (MySQL)
     * - json_operators: Native JSON operators (PostgreSQL ->, MySQL ->)
     *
     * @param string $feature Feature name
     * @return bool True if supported
     */
    public function supportsFeature(string $feature): bool;

    /**
     * Compile UNION clauses.
     *
     * UNION syntax is standard across MySQL, PostgreSQL, and SQLite:
     * - UNION: Removes duplicate rows
     * - UNION ALL: Keeps all rows including duplicates
     *
     * @param array<int, array{query: QueryBuilder, all: bool}> $unions Union queries
     * @return string Compiled UNION SQL
     */
    public function compileUnions(array $unions): string;

    /**
     * Compile the random order function for this database.
     *
     * - MySQL/MariaDB: RAND()
     * - PostgreSQL/SQLite: RANDOM()
     *
     * @return string The database-specific random function
     */
    public function compileRandomOrderFunction(): string;
}
