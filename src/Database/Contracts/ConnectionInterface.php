<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Contracts;

use PDO;
use Toporia\Framework\Database\Query\QueryBuilder;


/**
 * Interface ConnectionInterface
 *
 * Contract defining the interface for ConnectionInterface implementations
 * in the Database query building and ORM layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface ConnectionInterface
{
    /**
     * Get the underlying PDO instance.
     *
     * @return PDO
     */
    public function getPdo(): PDO;

    /**
     * Get connection configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;

    /**
     * Execute a SQL query and return the PDO statement.
     *
     * @param string $query SQL query.
     * @param array $bindings Parameter bindings.
     * @return \PDOStatement
     */
    public function execute(string $query, array $bindings = []): \PDOStatement;

    /**
     * Execute query in streaming mode for large datasets.
     *
     * @param string $query SQL query.
     * @param array $bindings Parameter bindings.
     * @return \Generator<array>
     */
    public function executeStreaming(string $query, array $bindings = []): \Generator;

    /**
     * Check if streaming is supported for current driver.
     *
     * @return bool
     */
    public function supportsStreaming(): bool;

    /**
     * Begin a database transaction.
     *
     * @return bool
     */
    public function beginTransaction(): bool;

    /**
     * Commit the current transaction.
     *
     * @return bool
     */
    public function commit(): bool;

    /**
     * Rollback the current transaction.
     *
     * @return bool
     */
    public function rollback(): bool;

    /**
     * Check if currently in a transaction.
     *
     * @return bool
     */
    public function inTransaction(): bool;

    /**
     * Get the last inserted ID.
     *
     * @param string|null $name Sequence name (for PostgreSQL).
     * @return string
     */
    public function lastInsertId(?string $name = null): string;

    /**
     * Get the database driver name.
     *
     * @return string (mysql, pgsql, sqlite)
     */
    public function getDriverName(): string;

    /**
     * Get the SQL Grammar instance for this connection.
     *
     * Grammar provides database-specific SQL compilation.
     *
     * @return GrammarInterface
     */
    public function getGrammar(): GrammarInterface;

    /**
     * Disconnect from the database.
     *
     * @return void
     */
    public function disconnect(): void;

    /**
     * Reconnect to the database.
     *
     * @return void
     */
    public function reconnect(): void;

    /**
     * Execute query and return results as array.
     *
     * @param string $query SQL query.
     * @param array $bindings Parameter bindings.
     * @return array<int, array<string, mixed>>
     */
    public function query(string $query, array $bindings = []): array;

    /**
     * Execute a SELECT query and return all rows.
     *
     * @param string $query SQL query.
     * @param array $bindings Parameter bindings.
     * @return array<int, array<string, mixed>>
     */
    public function select(string $query, array $bindings = []): array;

    /**
     * Execute a SELECT query and return first result.
     *
     * @param string $query SQL query.
     * @param array $bindings Parameter bindings.
     * @return array<string, mixed>|null
     */
    public function selectOne(string $query, array $bindings = []): ?array;

    /**
     * Execute an INSERT, UPDATE, or DELETE statement.
     *
     * @param string $query SQL query.
     * @param array $bindings Parameter bindings.
     * @return int Number of affected rows.
     */
    public function affectingStatement(string $query, array $bindings = []): int;

    /**
     * Execute an unprepared SQL statement.
     *
     * WARNING: This method does NOT use prepared statements.
     * Only use for DDL statements or when prepared statements are not supported.
     *
     * @param string $query Raw SQL statement (no parameter binding).
     * @return bool True on success.
     */
    public function unprepared(string $query): bool;

    /**
     * Get a query builder for the given table.
     *
     * Enables fluent query building pattern:
     * $users = $connection->table('users')->where('active', true)->get();
     *
     * @param string $table Table name.
     * @return QueryBuilder
     */
    public function table(string $table): QueryBuilder;
}
