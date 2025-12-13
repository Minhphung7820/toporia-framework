<?php

declare(strict_types=1);

namespace Toporia\Framework\Database;

use Toporia\Framework\Database\Contracts\ConnectionInterface;
use Toporia\Framework\Database\Schema\SchemaBuilder;
use Toporia\Framework\Database\Query\{QueryBuilder, Expression, RowCollection};
use Toporia\Framework\Database\ConnectionProxy;


/**
 * Class DatabaseManager
 *
 * Core class for the Database query building and ORM layer providing
 * essential functionality for the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
class DatabaseManager
{
    /**
     * @var array<string, ConnectionInterface> Active connections.
     */
    private array $connections = [];

    /**
     * @var array<string, array> Connection configurations.
     */
    private array $config;

    /**
     * @var string Default connection name.
     */
    private string $defaultConnection = 'default';

    /**
     * @param array $config Connection configurations.
     *        Example: ['default' => ['driver' => 'mysql', ...]]
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get a database connection (for direct access).
     *
     * @param string|null $name Connection name (null for default).
     * @return ConnectionInterface
     */
    public function getConnection(?string $name = null): ConnectionInterface
    {
        $name = $name ?? $this->defaultConnection;

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->createConnection($name);
        }

        return $this->connections[$name];
    }

    /**
     * Get a connection proxy for fluent API.
     *
     * Returns ConnectionProxy which provides table() method for QueryBuilder creation.
     * Enables syntax: DB()->connection('mysql')->table('users')
     *
     * Grammar is automatically selected based on connection driver.
     *
     * Usage:
     * ```php
     * DB()->connection('mysql')->table('users')->where('status', 'active')->get();
     * DB()->connection('mongodb')->table('messages')->where('user_id', 123)->get();
     * ```
     *
     * Performance: Connection is cached per name (O(1) lookup after first call)
     *
     * @param string|null $name Connection name (null for default)
     * @return ConnectionProxy Proxy object with table() method
     */
    public function connection(?string $name = null): ConnectionProxy
    {
        $connection = $this->getConnection($name);
        return new ConnectionProxy($connection);
    }

    /**
     * Execute a callback within a database transaction.
     *
     * Automatically begins a transaction, executes the callback, and commits.
     * If an exception is thrown, the transaction is rolled back and the exception is re-thrown.
     *
     * Example:
     * ```php
     * DB()->transaction(function() {
     *     UserModel::create(['name' => 'John']);
     *     OrderModel::create(['user_id' => 1, 'total' => 100]);
     *     // If any operation fails, both are rolled back
     * });
     *
     * // With return value
     * $result = DB()->transaction(function() {
     *     $user = UserModel::create(['name' => 'John']);
     *     return $user;
     * });
     *
     * // With attempts (retry on deadlock)
     * DB()->transaction(function() {
     *     // Complex operations
     * }, 3); // Retry up to 3 times on deadlock
     * ```
     *
     * Performance:
     * - O(1) transaction overhead
     * - Automatic rollback on exceptions
     * - Deadlock retry support
     *
     * Clean Architecture:
     * - Single Responsibility: Only handles transaction lifecycle
     * - Open/Closed: Extensible via callback
     * - Dependency Inversion: Depends on ConnectionInterface
     *
     * @param \Closure $callback Callback to execute within transaction
     * @param int $attempts Number of attempts on deadlock (default: 1)
     * @param string|null $connection Connection name (null for default)
     * @return mixed Return value from callback
     * @throws \Throwable Re-throws any exception from callback
     */
    public function transaction(\Closure $callback, int $attempts = 1, ?string $connection = null): mixed
    {
        $conn = $this->getConnection($connection);

        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            // Begin transaction
            $conn->beginTransaction();

            try {
                // Execute callback
                $result = $callback($conn);

                // Commit transaction
                $conn->commit();

                return $result;
            } catch (\Throwable $e) {
                // Rollback on any exception
                $conn->rollback();

                // Check if we should retry (deadlock detection)
                if ($this->isDeadlockException($e) && $currentAttempt < $attempts) {
                    // Wait a bit before retry (exponential backoff)
                    usleep(100000 * $currentAttempt); // 100ms, 200ms, 300ms...
                    continue;
                }

                // Re-throw exception if not retrying
                throw $e;
            }
        }

        // This should never be reached, but PHP requires a return
        throw new \RuntimeException('Transaction failed after ' . $attempts . ' attempts');
    }

    /**
     * Check if exception is a deadlock exception.
     *
     * Detects database deadlock errors that can be safely retried.
     *
     * @param \Throwable $e Exception to check
     * @return bool True if exception indicates deadlock
     */
    private function isDeadlockException(\Throwable $e): bool
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // MySQL deadlock error code: 1213
        if ($code === 1213) {
            return true;
        }

        // PostgreSQL deadlock detection
        if (stripos($message, 'deadlock detected') !== false) {
            return true;
        }

        // SQLite deadlock detection
        if (stripos($message, 'database is locked') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Create a new connection instance.
     *
     * @param string $name Connection name.
     * @return ConnectionInterface
     */
    private function createConnection(string $name): ConnectionInterface
    {
        if (!isset($this->config[$name])) {
            throw new \RuntimeException("Database connection '{$name}' not configured");
        }

        return new Connection($this->config[$name]);
    }

    /**
     * Get schema builder for a connection.
     *
     * @param string|null $name Connection name.
     * @return SchemaBuilder
     */
    public function schema(?string $name = null): SchemaBuilder
    {
        $connection = $this->getConnection($name);
        return new SchemaBuilder($connection);
    }

    /**
     * Set the default connection name.
     *
     * @param string $name Connection name.
     * @return void
     */
    public function setDefaultConnection(string $name): void
    {
        $this->defaultConnection = $name;
    }

    /**
     * Get the default connection name.
     *
     * @return string
     */
    public function getDefaultConnectionName(): string
    {
        return $this->defaultConnection;
    }

    /**
     * Disconnect all connections.
     *
     * @return void
     */
    public function disconnect(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }

        $this->connections = [];
    }

    /**
     * Reconnect a connection.
     *
     * @param string|null $name Connection name.
     * @return void
     */
    public function reconnect(?string $name = null): void
    {
        $name = $name ?? $this->defaultConnection;

        if (isset($this->connections[$name])) {
            $this->connections[$name]->reconnect();
        }
    }

    // =========================================================================
    // QUERY LOG METHODS (for static access via DB accessor)
    // =========================================================================

    /**
     * Enable query logging.
     *
     * All executed queries will be logged with their SQL, bindings, and execution time.
     *
     * @return void
     */
    public function enableQueryLog(): void
    {
        QueryBuilder::enableQueryLog();
    }

    /**
     * Disable query logging.
     *
     * @return void
     */
    public function disableQueryLog(): void
    {
        QueryBuilder::disableQueryLog();
    }

    /**
     * Get the query log.
     *
     * Returns array of executed queries with:
     * - query: SQL query string
     * - bindings: Parameter bindings
     * - time: Execution time in milliseconds
     *
     * @return array<array{query: string, bindings: array, time: float}>
     */
    public function getQueryLog(): array
    {
        return QueryBuilder::getQueryLog();
    }

    /**
     * Clear the query log.
     *
     * @return void
     */
    public function flushQueryLog(): void
    {
        QueryBuilder::flushQueryLog();
    }

    // =========================================================================
    // RAW SQL METHODS (for static access via DB accessor)
    // =========================================================================

    /**
     * Create a raw database expression.
     *
     * Returns an Expression object that can be used in query building.
     * The expression will not be quoted or escaped by the query grammar.
     *
     * This method works exactly like Toporia's DB::raw() - it returns an
     * expression object for use in query building, not execute a query.
     *
     * Performance: O(1) - lightweight object creation, no database overhead
     * Security: Expression is not automatically escaped - use with caution
     *
     * @param string $value Raw SQL expression
     * @return Expression Expression object for use in queries
     *
     * @example
     * ```php
     * // Use in SELECT clause
     * DB::table('users')
     *     ->select(DB::raw('COUNT(*) as total'))
     *     ->get();
     *
     * // Use in WHERE clause
     * DB::table('orders')
     *     ->where(DB::raw('DATE(created_at)'), '=', '2024-01-01')
     *     ->get();
     *
     * // Use in ORDER BY
     * DB::table('products')
     *     ->orderBy(DB::raw('RAND()'))
     *     ->get();
     *
     * // Use in aggregate functions
     * DB::table('users')
     *     ->select(DB::raw('AVG(age) as avg_age'))
     *     ->get();
     * ```
     */
    public function raw(string $value): Expression
    {
        return new Expression($value);
    }

    /**
     * Execute a raw SQL SELECT query and return results.
     *
     * Executes a raw SELECT query and returns the results as a DatabaseCollection.
     * This is different from raw() which returns an Expression for query building.
     *
     * Performance: Direct SQL execution with prepared statements
     * Security: Uses parameter binding to prevent SQL injection
     *
     * @param string $sql Raw SQL SELECT query
     * @param array<int|string, mixed> $bindings Query parameter bindings
     * @return DatabaseCollection Query results
     *
     * @example
     * ```php
     * // Simple raw query
     * $users = DB::select('SELECT * FROM users WHERE status = ?', ['active']);
     *
     * // Complex query with joins
     * $results = DB::select('
     *     SELECT u.*, COUNT(p.id) as post_count
     *     FROM users u
     *     LEFT JOIN posts p ON p.user_id = u.id
     *     WHERE u.created_at > ?
     *     GROUP BY u.id
     * ', [$date]);
     *
     * // Named parameters
     * $user = DB::select('SELECT * FROM users WHERE id = :id', ['id' => 1]);
     * ```
     */
    public function select(string $sql, array $bindings = []): DatabaseCollection
    {
        $connection = $this->getConnection();
        $rows = $connection->select($sql, $bindings);
        return new RowCollection($rows);
    }

    /**
     * Execute a raw SQL statement (INSERT, UPDATE, DELETE).
     *
     * Executes a raw SQL statement that modifies data and returns the number
     * of affected rows.
     *
     * Performance: Direct SQL execution with prepared statements
     * Security: Uses parameter binding to prevent SQL injection
     *
     * @param string $sql Raw SQL statement
     * @param array<int|string, mixed> $bindings Query parameter bindings
     * @return int Number of affected rows
     *
     * @example
     * ```php
     * // Raw UPDATE
     * $affected = DB::statement('UPDATE users SET status = ? WHERE last_login < ?', ['inactive', $date]);
     *
     * // Raw DELETE
     * $deleted = DB::statement('DELETE FROM sessions WHERE expires_at < NOW()');
     *
     * // Raw INSERT
     * DB::statement('INSERT INTO logs (message, level) VALUES (?, ?)', [$message, 'info']);
     * ```
     */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->connection()->statement($sql, $bindings);
    }

    /**
     * Execute an unprepared SQL statement (DDL, SET variables).
     *
     * WARNING: Does NOT use prepared statements. Never pass user input.
     * Use only for DDL statements (CREATE, DROP, ALTER) or SET operations.
     *
     * @param string $sql Raw SQL statement
     * @return bool True on success
     *
     * @example
     * ```php
     * // DDL statements
     * DB::unprepared('CREATE TABLE temp_data (id INT PRIMARY KEY, data TEXT)');
     * DB::unprepared('SET FOREIGN_KEY_CHECKS = 0');
     * DB::unprepared('TRUNCATE TABLE cache');
     * ```
     */
    public function unprepared(string $sql): bool
    {
        return $this->connection()->unprepared($sql);
    }
}
