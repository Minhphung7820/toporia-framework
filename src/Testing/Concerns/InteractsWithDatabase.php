<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Concerns;

use PDO;
use PDOException;


/**
 * Trait InteractsWithDatabase
 *
 * Trait providing reusable functionality for InteractsWithDatabase in the
 * Concerns layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait InteractsWithDatabase
{
    /**
     * Database connection instance.
     */
    protected ?PDO $db = null;

    /**
     * Indicates if we're using transactions.
     */
    protected bool $usingTransactions = true;

    /**
     * Setup database for testing.
     *
     * Performance: O(1) - Connection setup
     *
     * Supports multiple database drivers: mysql, pgsql, sqlite
     * Automatically detects driver from config and creates appropriate connection
     */
    protected function setUpDatabase(): void
    {
        // Get database config from container or environment
        $config = $this->getDatabaseConfig();
        $driver = $config['driver'] ?? 'mysql';

        // Create connection based on driver
        $this->db = $this->createDatabaseConnection($driver, $config);

        // Start transaction if using transactions (only for SQL databases)
        if ($this->usingTransactions && $this->db !== null) {
            try {
                $this->db->beginTransaction();
            } catch (\PDOException $e) {
                // Some databases or configurations may not support transactions
                // Silently continue without transactions
            }
        }
    }

    /**
     * Create database connection based on driver.
     *
     * @param string $driver Database driver (mysql, pgsql, sqlite)
     * @param array<string, mixed> $config Database configuration
     * @return PDO|null PDO instance or null for non-PDO databases (e.g., MongoDB)
     */
    protected function createDatabaseConnection(string $driver, array $config): ?PDO
    {
        return match ($driver) {
            'mysql' => $this->createMySQLConnection($config),
            'pgsql', 'postgresql' => $this->createPostgreSQLConnection($config),
            'sqlite' => $this->createSQLiteConnection($config),
            'mongodb' => null, // MongoDB uses different client, not PDO
            default => throw new \InvalidArgumentException("Unsupported database driver: {$driver}"),
        };
    }

    /**
     * Create MySQL connection.
     *
     * @param array<string, mixed> $config
     * @return PDO
     */
    protected function createMySQLConnection(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 3306,
            $config['database'] ?? 'test',
            $config['charset'] ?? 'utf8mb4'
        );

        return new PDO(
            $dsn,
            $config['username'] ?? 'root',
            $config['password'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    /**
     * Create PostgreSQL connection.
     *
     * @param array<string, mixed> $config
     * @return PDO
     */
    protected function createPostgreSQLConnection(array $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 5432,
            $config['database'] ?? 'test'
        );

        return new PDO(
            $dsn,
            $config['username'] ?? 'postgres',
            $config['password'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * Create SQLite connection.
     *
     * @param array<string, mixed> $config
     * @return PDO
     */
    protected function createSQLiteConnection(array $config): PDO
    {
        $database = $config['database'] ?? ':memory:';

        // If database path is provided, use it; otherwise use in-memory
        if ($database !== ':memory:' && !str_starts_with($database, 'sqlite:')) {
            // Ensure directory exists
            $dir = dirname($database);
            if ($dir && !is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $database = "sqlite:{$database}";
        } elseif ($database === ':memory:') {
            $database = 'sqlite::memory:';
        }

        return new PDO(
            $database,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * Get database configuration from container or environment.
     *
     * Supports multiple connections and automatically detects default connection.
     *
     * @return array<string, mixed>
     */
    protected function getDatabaseConfig(): array
    {
        // Try to get from container first
        try {
            if (method_exists($this, 'getContainer')) {
                $container = $this->getContainer();
                if ($container && $container->has('config')) {
                    $config = $container->get('config');

                    // Get default connection name
                    $defaultConnection = $config->get('database.default', 'mysql');

                    // Get connection config
                    $dbConfig = $config->get("database.connections.{$defaultConnection}", []);
                    if (!empty($dbConfig)) {
                        // Ensure driver is set
                        $dbConfig['driver'] = $dbConfig['driver'] ?? $defaultConnection;
                        return $dbConfig;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall back to environment variables
        }

        // Fall back to environment variables
        $driver = $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: 'mysql';

        return match ($driver) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
                'database' => $_ENV['DB_NAME'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_NAME') ?: getenv('DB_DATABASE') ?: 'test',
                'username' => $_ENV['DB_USER'] ?? $_ENV['DB_USERNAME'] ?? getenv('DB_USER') ?: getenv('DB_USERNAME') ?: 'root',
                'password' => $_ENV['DB_PASS'] ?? $_ENV['DB_PASSWORD'] ?? getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
            ],
            'pgsql', 'postgresql' => [
                'driver' => 'pgsql',
                'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 5432),
                'database' => $_ENV['DB_NAME'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_NAME') ?: getenv('DB_DATABASE') ?: 'test',
                'username' => $_ENV['DB_USER'] ?? $_ENV['DB_USERNAME'] ?? getenv('DB_USER') ?: getenv('DB_USERNAME') ?: 'postgres',
                'password' => $_ENV['DB_PASS'] ?? $_ENV['DB_PASSWORD'] ?? getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '',
            ],
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: ':memory:',
            ],
            default => [
                'driver' => $driver,
                'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
                'database' => $_ENV['DB_NAME'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_NAME') ?: getenv('DB_DATABASE') ?: 'test',
                'username' => $_ENV['DB_USER'] ?? $_ENV['DB_USERNAME'] ?? getenv('DB_USER') ?: getenv('DB_USERNAME') ?: 'root',
                'password' => $_ENV['DB_PASS'] ?? $_ENV['DB_PASSWORD'] ?? getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '',
            ],
        };
    }

    /**
     * Cleanup database after test.
     *
     * Performance: O(1) - Transaction rollback
     */
    protected function tearDownDatabase(): void
    {
        if ($this->db !== null) {
            if ($this->usingTransactions) {
                try {
                    $this->db->rollBack();
                } catch (\PDOException $e) {
                    // Some databases or configurations may not support transactions
                    // Silently continue
                }
            }
            $this->db = null;
        }
    }

    /**
     * Get database connection.
     *
     * Performance: O(1) - Direct access
     */
    protected function getDb(): ?PDO
    {
        if ($this->db === null) {
            $this->setUpDatabase();
        }

        return $this->db;
    }

    /**
     * Run a database query.
     *
     * Performance: O(N) where N = query complexity
     */
    protected function dbQuery(string $sql, array $params = []): \PDOStatement
    {
        $db = $this->getDb();
        if ($db === null) {
            throw new \RuntimeException('Database connection not available');
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Insert data into a table.
     *
     * Performance: O(1) - Single insert
     */
    protected function dbInsert(string $table, array $data): bool
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->getDb()->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        return $stmt->execute();
    }

    /**
     * Get data from a table.
     *
     * Performance: O(N) where N = number of rows
     */
    protected function dbGet(string $table, array $where = [], string $orderBy = null): array
    {
        $sql = "SELECT * FROM {$table}";

        if (!empty($where)) {
            $conditions = [];
            foreach (array_keys($where) as $key) {
                $conditions[] = "{$key} = :{$key}";
            }
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        if ($orderBy !== null) {
            $sql .= " ORDER BY {$orderBy}";
        }

        $stmt = $this->getDb()->prepare($sql);

        foreach ($where as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Assert that a record exists in the database.
     *
     * Performance: O(1) - Single query
     */
    protected function assertDatabaseHas(string $table, array $where, string $message = ''): void
    {
        $records = $this->dbGet($table, $where);
        $this->assertNotEmpty($records, $message ?: "Failed to find record in {$table}");
    }

    /**
     * Assert that a record doesn't exist in the database.
     *
     * Performance: O(1) - Single query
     */
    protected function assertDatabaseMissing(string $table, array $where, string $message = ''): void
    {
        $records = $this->dbGet($table, $where);
        $this->assertEmpty($records, $message ?: "Found unexpected record in {$table}");
    }

    /**
     * Assert that a record count matches.
     *
     * Performance: O(1) - COUNT query
     */
    protected function assertDatabaseCount(string $table, int $expected, array $where = [], string $message = ''): void
    {
        $sql = "SELECT COUNT(*) as count FROM {$table}";

        if (!empty($where)) {
            $conditions = [];
            foreach (array_keys($where) as $key) {
                $conditions[] = "{$key} = :{$key}";
            }
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $this->getDb()->prepare($sql);

        foreach ($where as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $actual = (int) $result['count'];

        $this->assertEquals($expected, $actual, $message ?: "Expected {$expected} records in {$table}, found {$actual}");
    }

    /**
     * Disable transactions for this test.
     */
    protected function withoutTransactions(): void
    {
        $this->usingTransactions = false;
    }
}
