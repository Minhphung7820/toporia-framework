<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Migration;

use Toporia\Framework\Database\Contracts\ConnectionInterface;

/**
 * Migration Repository
 *
 * Tracks executed migrations in database to prevent re-running.
 *
 * Performance:
 * - Uses indexed queries for O(log N) lookup
 * - Batch insert support
 * - Minimal memory footprint
 *
 * Architecture:
 * - Single Responsibility: Only migration tracking
 * - Database agnostic (works with MySQL, PostgreSQL, SQLite)
 * - Clean separation from migration execution
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Migration
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MigrationRepository
{
    private const TABLE = 'migrations';

    /**
     * @param ConnectionInterface $connection Database connection
     */
    public function __construct(
        private readonly ConnectionInterface $connection
    ) {
    }

    /**
     * Create migrations table if not exists.
     *
     * @return void
     */
    public function createRepository(): void
    {
        $sql = match ($this->connection->getDriverName()) {
            'mysql' => $this->getMySQLSchema(),
            'pgsql' => $this->getPostgreSQLSchema(),
            'sqlite' => $this->getSQLiteSchema(),
            default => throw new \RuntimeException("Unsupported database driver: {$this->connection->getDriverName()}")
        };

        $this->connection->execute($sql);
    }

    /**
     * Check if migrations table exists.
     *
     * @return bool
     */
    public function repositoryExists(): bool
    {
        try {
            $this->connection->execute("SELECT 1 FROM " . self::TABLE . " LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get all ran migrations.
     *
     * Performance: O(N) where N = number of migrations
     *
     * @return array Array of migration filenames
     */
    public function getRan(): array
    {
        $sql = "SELECT migration FROM " . self::TABLE . " ORDER BY batch, id";
        $results = $this->connection->query($sql);

        return array_column($results, 'migration');
    }

    /**
     * Get migrations for last batch.
     *
     * Used for rollback.
     *
     * @return array
     */
    public function getLast(): array
    {
        $lastBatch = $this->getLastBatchNumber();

        if ($lastBatch === 0) {
            return [];
        }

        $sql = "SELECT migration FROM " . self::TABLE . "
                WHERE batch = ?
                ORDER BY id DESC";

        $results = $this->connection->query($sql, [$lastBatch]);

        return array_column($results, 'migration');
    }

    /**
     * Get migrations for specific batch.
     *
     * @param int $batch Batch number
     * @return array
     */
    public function getMigrations(int $batch): array
    {
        $sql = "SELECT * FROM " . self::TABLE . "
                WHERE batch = ?
                ORDER BY id";

        return $this->connection->query($sql, [$batch]);
    }

    /**
     * Log migration as ran.
     *
     * @param string $file Migration filename
     * @param int $batch Batch number
     * @return void
     */
    public function log(string $file, int $batch): void
    {
        $sql = "INSERT INTO " . self::TABLE . " (migration, batch) VALUES (?, ?)";
        $this->connection->execute($sql, [$file, $batch]);
    }

    /**
     * Remove migration from log.
     *
     * @param string $file Migration filename
     * @return void
     */
    public function delete(string $file): void
    {
        $sql = "DELETE FROM " . self::TABLE . " WHERE migration = ?";
        $this->connection->execute($sql, [$file]);
    }

    /**
     * Get next batch number.
     *
     * @return int
     */
    public function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    /**
     * Get last batch number.
     *
     * Performance: O(1) with indexed query
     *
     * @return int
     */
    public function getLastBatchNumber(): int
    {
        $sql = "SELECT MAX(batch) as batch FROM " . self::TABLE;
        $result = $this->connection->query($sql);

        return (int) ($result[0]['batch'] ?? 0);
    }

    /**
     * Get all migration batches.
     *
     * @return array
     */
    public function getMigrationBatches(): array
    {
        $sql = "SELECT migration, batch FROM " . self::TABLE . " ORDER BY batch, id";
        $results = $this->connection->query($sql);

        $batches = [];
        foreach ($results as $row) {
            $batches[$row['migration']] = (int) $row['batch'];
        }

        return $batches;
    }

    /**
     * Get count of ran migrations.
     *
     * @return int
     */
    public function count(): int
    {
        $sql = "SELECT COUNT(*) as count FROM " . self::TABLE;
        $result = $this->connection->query($sql);

        return (int) $result[0]['count'];
    }

    /**
     * Delete all migration records.
     *
     * WARNING: Use with caution!
     *
     * @return void
     */
    public function reset(): void
    {
        $sql = "DELETE FROM " . self::TABLE;
        $this->connection->execute($sql);
    }

    /**
     * Get MySQL schema for migrations table.
     *
     * @return string
     */
    private function getMySQLSchema(): string
    {
        return "CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL,
            batch INT NOT NULL,
            INDEX idx_batch (batch),
            INDEX idx_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /**
     * Get PostgreSQL schema for migrations table.
     *
     * @return string
     */
    private function getPostgreSQLSchema(): string
    {
        return "CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id SERIAL PRIMARY KEY,
            migration VARCHAR(255) NOT NULL,
            batch INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_migrations_batch ON " . self::TABLE . " (batch);
        CREATE INDEX IF NOT EXISTS idx_migrations_migration ON " . self::TABLE . " (migration);";
    }

    /**
     * Get SQLite schema for migrations table.
     *
     * @return string
     */
    private function getSQLiteSchema(): string
    {
        return "CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration TEXT NOT NULL,
            batch INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_migrations_batch ON " . self::TABLE . " (batch);
        CREATE INDEX IF NOT EXISTS idx_migrations_migration ON " . self::TABLE . " (migration);";
    }
}
