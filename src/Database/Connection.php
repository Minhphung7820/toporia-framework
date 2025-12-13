<?php

declare(strict_types=1);

namespace Toporia\Framework\Database;

use Toporia\Framework\Database\Contracts\ConnectionInterface;
use Toporia\Framework\Database\Contracts\GrammarInterface;
use Toporia\Framework\Database\Grammar\{MySQLGrammar, PostgreSQLGrammar, SQLiteGrammar, MongoDBGrammar};
use Toporia\Framework\Database\Query\QueryBuilder;
use PDO;
use PDOException;
use Toporia\Framework\Database\Exception\{ConnectionException, QueryException};


/**
 * Class Connection
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
class Connection implements ConnectionInterface
{
    /**
     * @var PDO|null PDO instance.
     */
    private ?PDO $pdo = null;

    /**
     * @var array<string, mixed> Connection configuration.
     */
    private array $config;

    /**
     * @var GrammarInterface|null SQL Grammar instance.
     */
    private ?GrammarInterface $grammar = null;

    /**
     * @var int Transaction nesting level (for savepoint support).
     */
    private int $transactionLevel = 0;

    /**
     * @var array<string> Stack of savepoint names for nested transactions.
     */
    private array $savepointStack = [];

    /**
     * @param array $config Connection configuration.
     *        Required keys: driver, host, database, username, password
     *        Optional keys: port, charset, options
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    /**
     * {@inheritdoc}
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->reconnect();
        }

        return $this->pdo;
    }

    /**
     * Ensure connection is alive, reconnect if needed.
     *
     * This method checks if the connection is still valid by attempting a simple query.
     * If the connection is dead (e.g., "MySQL server has gone away"), it reconnects.
     *
     * @return void
     */
    public function ensureConnected(): void
    {
        if ($this->pdo === null) {
            $this->reconnect();
            return;
        }

        try {
            // Try a simple query to check if connection is alive
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            // Connection is dead, reconnect
            if ($this->isConnectionLost($e)) {
                $this->reconnect();
            } else {
                throw $e;
            }
        }
    }

    /**
     * Check if exception indicates connection was lost.
     *
     * @param PDOException $e
     * @return bool
     */
    private function isConnectionLost(PDOException $e): bool
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // MySQL: "MySQL server has gone away" (2006) or "Lost connection" (2013)
        if ($code === 2006 || $code === 2013) {
            return true;
        }

        // Check error message for common connection lost patterns
        $lostPatterns = [
            'server has gone away',
            'lost connection',
            'connection was killed',
            'connection was closed',
            'broken pipe',
        ];

        foreach ($lostPatterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $query, array $bindings = []): \PDOStatement
    {
        try {
            $statement = $this->getPdo()->prepare($query);
            $this->bindParameters($statement, $bindings);
            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            // If connection was lost, reconnect and retry once
            if ($this->isConnectionLost($e)) {
                $this->reconnect();

                // Retry the query with same binding logic
                $statement = $this->getPdo()->prepare($query);
                $this->bindParameters($statement, $bindings);
                $statement->execute();
                return $statement;
            }

            throw new QueryException(
                "Query execution failed: {$e->getMessage()}",
                $query,
                $bindings,
                $e
            );
        }
    }

    /**
     * Bind parameters to PDO statement with proper type handling.
     *
     * @param \PDOStatement $statement
     * @param array $bindings
     * @return void
     */
    private function bindParameters(\PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            // Convert arrays/objects to JSON strings for JSON columns
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $type = $this->getPdoType($value);
            $statement->bindValue(
                is_int($key) ? $key + 1 : $key,
                $value,
                $type
            );
        }
    }

    /**
     * Execute query and return results as array.
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @return array
     */
    public function query(string $query, array $bindings = []): array
    {
        // Log query if query logging is enabled
        $startTime = null;
        if (QueryBuilder::isQueryLogEnabled()) {
            $startTime = microtime(true);
        }

        $statement = $this->execute($query, $bindings);
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Log query execution
        if ($startTime !== null) {
            $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            QueryBuilder::logQueryDirectly($query, $bindings, $executionTime);
        }

        return $results;
    }

    /**
     * Begin a transaction with nested transaction (savepoint) support.
     *
     * For the first level, uses standard PDO beginTransaction().
     * For nested levels, creates a savepoint instead.
     *
     * @return bool True on success
     * @throws PDOException On connection error
     *
     * @example
     * ```php
     * $conn->beginTransaction();           // Level 1: START TRANSACTION
     * $conn->beginTransaction();           // Level 2: SAVEPOINT trans_2
     * $conn->rollback();                   // ROLLBACK TO SAVEPOINT trans_2
     * $conn->commit();                     // COMMIT
     * ```
     */
    public function beginTransaction(): bool
    {
        $this->transactionLevel++;

        // First level: start actual transaction
        if ($this->transactionLevel === 1) {
            try {
                return $this->getPdo()->beginTransaction();
            } catch (PDOException $e) {
                $this->transactionLevel--;
                if ($this->isConnectionLost($e)) {
                    $this->reconnect();
                    $this->transactionLevel++;
                    return $this->getPdo()->beginTransaction();
                }
                throw $e;
            }
        }

        // Nested level: create savepoint
        $savepointName = 'trans_' . $this->transactionLevel;
        $this->savepointStack[] = $savepointName;
        $this->createSavepoint($savepointName);

        return true;
    }

    /**
     * Commit a transaction or release a savepoint.
     *
     * For nested transactions (level > 1), releases the savepoint.
     * For the outermost transaction (level 1), commits the transaction.
     *
     * @return bool True on success
     */
    public function commit(): bool
    {
        if ($this->transactionLevel === 0) {
            return false;
        }

        // Nested level: release savepoint
        if ($this->transactionLevel > 1) {
            $savepointName = array_pop($this->savepointStack);
            if ($savepointName !== null) {
                $this->releaseSavepoint($savepointName);
            }
            $this->transactionLevel--;
            return true;
        }

        // Outermost level: commit transaction
        $this->transactionLevel = 0;
        $this->savepointStack = [];
        return $this->getPdo()->commit();
    }

    /**
     * Rollback a transaction or rollback to a savepoint.
     *
     * For nested transactions (level > 1), rolls back to the savepoint.
     * For the outermost transaction (level 1), rolls back the entire transaction.
     *
     * @return bool True on success
     */
    public function rollback(): bool
    {
        if ($this->transactionLevel === 0) {
            return false;
        }

        // Nested level: rollback to savepoint
        if ($this->transactionLevel > 1) {
            $savepointName = array_pop($this->savepointStack);
            if ($savepointName !== null) {
                $this->rollbackToSavepoint($savepointName);
            }
            $this->transactionLevel--;
            return true;
        }

        // Outermost level: rollback transaction
        $this->transactionLevel = 0;
        $this->savepointStack = [];
        return $this->getPdo()->rollBack();
    }

    /**
     * Check if currently in a transaction.
     *
     * @return bool True if in transaction
     */
    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0 || $this->getPdo()->inTransaction();
    }

    /**
     * Get the current transaction nesting level.
     *
     * @return int Transaction level (0 = no transaction)
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Create a named savepoint.
     *
     * Savepoints allow partial rollback within a transaction.
     * Compatible with MySQL, PostgreSQL, SQLite.
     *
     * @param string $name Savepoint name (must be valid SQL identifier)
     * @return void
     * @throws QueryException On SQL error
     *
     * @example
     * ```php
     * $conn->beginTransaction();
     * // Do some work...
     * $conn->createSavepoint('before_risky_operation');
     * try {
     *     // Risky operation...
     * } catch (Exception $e) {
     *     $conn->rollbackToSavepoint('before_risky_operation');
     * }
     * $conn->commit();
     * ```
     */
    public function createSavepoint(string $name): void
    {
        $this->getPdo()->exec("SAVEPOINT {$name}");
    }

    /**
     * Release (delete) a savepoint.
     *
     * Releases the named savepoint, making it no longer available for rollback.
     * This is optional - savepoints are automatically released on commit.
     *
     * Note: SQLite does not support RELEASE SAVEPOINT, so we skip it silently.
     *
     * @param string $name Savepoint name
     * @return void
     */
    public function releaseSavepoint(string $name): void
    {
        $driver = $this->getDriverName();

        // SQLite doesn't support RELEASE SAVEPOINT
        if ($driver === 'sqlite') {
            return;
        }

        $this->getPdo()->exec("RELEASE SAVEPOINT {$name}");
    }

    /**
     * Rollback to a savepoint.
     *
     * Undoes all changes made after the savepoint was created.
     * The savepoint remains valid and can be rolled back to again.
     *
     * @param string $name Savepoint name
     * @return void
     * @throws QueryException On SQL error (e.g., savepoint doesn't exist)
     */
    public function rollbackToSavepoint(string $name): void
    {
        $this->getPdo()->exec("ROLLBACK TO SAVEPOINT {$name}");
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(?string $name = null): string
    {
        return $this->getPdo()->lastInsertId($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return $this->config['driver'] ?? 'mysql';
    }

    /**
     * Get SQL Grammar instance for the connection.
     *
     * Lazily creates Grammar based on driver type.
     * Grammar instance is cached for performance.
     *
     * @return GrammarInterface
     */
    public function getGrammar(): GrammarInterface
    {
        return $this->grammar ??= $this->createGrammar();
    }

    /**
     * Create SQL Grammar instance based on driver.
     *
     * Factory method that instantiates the appropriate Grammar
     * implementation based on the configured database driver.
     *
     * @return GrammarInterface
     * @throws ConnectionException If driver is not supported
     */
    protected function createGrammar(): GrammarInterface
    {
        $driver = $this->getDriverName();

        return match ($driver) {
            'mysql' => new MySQLGrammar(),
            'pgsql' => new PostgreSQLGrammar(),
            'sqlite' => new SQLiteGrammar(),
            'mongodb' => new MongoDBGrammar(),
            default => throw new ConnectionException("Unsupported driver for Grammar: {$driver}")
        };
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * {@inheritdoc}
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * Establish database connection.
     *
     * @return void
     * @throws ConnectionException
     */
    private function connect(): void
    {
        try {
            $dsn = $this->buildDsn();
            $username = $this->config['username'] ?? null;
            $password = $this->config['password'] ?? null;
            $options = $this->getDefaultOptions();

            $this->pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new ConnectionException(
                "Failed to connect to database: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Build DSN string based on driver.
     *
     * @return string
     */
    private function buildDsn(): string
    {
        $driver = $this->config['driver'] ?? 'mysql';

        return match ($driver) {
            'mysql' => $this->buildMysqlDsn(),
            'pgsql' => $this->buildPgsqlDsn(),
            'sqlite' => $this->buildSqliteDsn(),
            default => throw new ConnectionException("Unsupported driver: {$driver}")
        };
    }

    /**
     * Build MySQL DSN.
     *
     * @return string
     */
    private function buildMysqlDsn(): string
    {
        $host = $this->config['host'] ?? 'localhost';
        $port = $this->config['port'] ?? 3306;
        $database = $this->config['database'];
        $charset = $this->config['charset'] ?? 'utf8mb4';

        return "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    }

    /**
     * Build PostgreSQL DSN.
     *
     * @return string
     */
    private function buildPgsqlDsn(): string
    {
        $host = $this->config['host'] ?? 'localhost';
        $port = $this->config['port'] ?? 5432;
        $database = $this->config['database'];

        return "pgsql:host={$host};port={$port};dbname={$database}";
    }

    /**
     * Build SQLite DSN.
     *
     * @return string
     */
    private function buildSqliteDsn(): string
    {
        $database = $this->config['database'];
        return "sqlite:{$database}";
    }

    /**
     * Get default PDO options.
     *
     * @return array<int, mixed>
     */
    private function getDefaultOptions(): array
    {
        $defaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        return array_merge($defaults, $this->config['options'] ?? []);
    }

    /**
     * Get PDO parameter type for value.
     *
     * @param mixed $value
     * @return int PDO::PARAM_* constant
     */
    private function getPdoType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * Get connection configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Execute query in streaming mode for large datasets.
     *
     * This method enables unbuffered queries to reduce memory usage
     * when processing large result sets.
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @return \Generator<array> Generator yielding rows one by one
     * @throws QueryException
     */
    public function executeStreaming(string $query, array $bindings = []): \Generator
    {
        $this->ensureConnected();

        $originalBuffered = null;

        try {
            // Store original buffered query setting
            $originalBuffered = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);

            // Enable unbuffered queries for streaming
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

            // Execute query
            $statement = $this->execute($query, $bindings);

            // Yield rows one by one
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                yield $row;
            }
        } catch (PDOException $e) {
            throw new QueryException(
                "Streaming query execution failed: {$e->getMessage()}",
                $query,
                $bindings,
                $e
            );
        } finally {
            // Restore original buffered query setting
            if ($originalBuffered !== null) {
                $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $originalBuffered);
            }
        }
    }

    /**
     * Check if streaming is supported for current driver.
     *
     * @return bool
     */
    public function supportsStreaming(): bool
    {
        $driver = $this->config['driver'] ?? 'mysql';

        return match ($driver) {
            'mysql' => true,
            'pgsql' => true,  // PostgreSQL supports cursors
            'sqlite' => false, // SQLite doesn't benefit from streaming
            default => false
        };
    }

    /**
     * Execute a SELECT query and return all results.
     *
     * @param string $query SQL query.
     * @param array $bindings Parameter bindings.
     * @return array<array>
     */
    public function select(string $query, array $bindings = []): array
    {
        // Log query if query logging is enabled
        $startTime = null;
        if (QueryBuilder::isQueryLogEnabled()) {
            $startTime = microtime(true);
        }

        $statement = $this->execute($query, $bindings);
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Log query execution (important for window functions, subqueries, etc.)
        if ($startTime !== null) {
            $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            QueryBuilder::logQueryDirectly($query, $bindings, $executionTime);
        }

        return $results;
    }

    /**
     * Execute a SELECT query and return first result.
     *
     * @param string $query SQL query.
     * @param array $bindings Parameter bindings.
     * @return array|null
     */
    public function selectOne(string $query, array $bindings = []): ?array
    {
        // Log query if query logging is enabled
        $startTime = null;
        if (QueryBuilder::isQueryLogEnabled()) {
            $startTime = microtime(true);
        }

        $statement = $this->execute($query, $bindings);
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        // Log query execution
        if ($startTime !== null) {
            $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            QueryBuilder::logQueryDirectly($query, $bindings, $executionTime);
        }

        return $result ?: null;
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query.
     *
     * @param string $query SQL query.
     * @param array $bindings Parameter bindings.
     * @return int Number of affected rows.
     */
    public function affectingStatement(string $query, array $bindings = []): int
    {
        // Log query if query logging is enabled
        $startTime = null;
        if (QueryBuilder::isQueryLogEnabled()) {
            $startTime = microtime(true);
        }

        $statement = $this->execute($query, $bindings);
        $affected = $statement->rowCount();

        // Log query execution (important for insert/update/delete operations)
        if ($startTime !== null) {
            $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            QueryBuilder::logQueryDirectly($query, $bindings, $executionTime);
        }

        return $affected;
    }

    /**
     * {@inheritdoc}
     */
    public function unprepared(string $query): bool
    {
        return $this->pdo->exec($query) !== false;
    }

    /**
     * Get a query builder for the given table.
     *
     * This enables fluent query building:
     * $users = $connection->table('users')->where('active', true)->get();
     *
     * @param string $table Table name.
     * @return QueryBuilder
     */
    public function table(string $table): QueryBuilder
    {
        return (new QueryBuilder($this))->table($table);
    }
}
