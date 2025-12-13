<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Migration;

use Toporia\Framework\Database\Contracts\ConnectionInterface;
use Toporia\Framework\Database\Schema\SchemaBuilder;

/**
 * Migrator
 *
 * Orchestrates database migrations with comprehensive functionality.
 *
 * Features:
 * - Batch tracking (only run new migrations)
 * - Rollback support
 * - Status reporting
 * - Transaction support for safety
 *
 * Performance:
 * - O(N) migration discovery
 * - O(log N) ran check (indexed)
 * - Minimal memory usage
 *
 * Architecture:
 * - Single Responsibility: Migration orchestration
 * - Dependency Injection: Repository, Connection
 * - Open/Closed: Extensible via events
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
final class Migrator
{
    private MigrationRepository $repository;
    private SchemaBuilder $schema;

    /**
     * @param ConnectionInterface $connection Database connection
     */
    public function __construct(
        private readonly ConnectionInterface $connection
    ) {
        $this->repository = new MigrationRepository($connection);
        $this->schema = new SchemaBuilder($connection);
    }

    /**
     * Run pending migrations.
     *
     * @param string $path Path to migrations directory
     * @param callable|null $callback Progress callback (migration name, status)
     * @return array Ran migrations
     */
    public function run(string $path, ?callable $callback = null): array
    {
        // Ensure repository exists
        $this->ensureRepositoryExists();

        // Get pending migrations
        $files = $this->getMigrationFiles($path);
        $ran = $this->repository->getRan();
        $pending = array_diff($files, $ran);

        if (empty($pending)) {
            return [];
        }

        // Get next batch number
        $batch = $this->repository->getNextBatchNumber();

        // Run pending migrations
        $ranMigrations = [];

        foreach ($pending as $file) {
            $this->runMigration($path, $file, $batch, $callback);
            $ranMigrations[] = $file;
        }

        return $ranMigrations;
    }

    /**
     * Rollback last batch of migrations.
     *
     * @param string $path Path to migrations directory
     * @param callable|null $callback Progress callback
     * @return array Rolled back migrations
     */
    public function rollback(string $path, ?callable $callback = null): array
    {
        if (!$this->repository->repositoryExists()) {
            return [];
        }

        // Get last batch
        $migrations = $this->repository->getLast();

        if (empty($migrations)) {
            return [];
        }

        // Rollback migrations
        $rolledBack = [];

        foreach ($migrations as $file) {
            $this->rollbackMigration($path, $file, $callback);
            $rolledBack[] = $file;
        }

        return $rolledBack;
    }

    /**
     * Reset all migrations.
     *
     * @param string $path Path to migrations directory
     * @param callable|null $callback Progress callback
     * @return array All migrations
     */
    public function reset(string $path, ?callable $callback = null): array
    {
        if (!$this->repository->repositoryExists()) {
            return [];
        }

        $migrations = array_reverse($this->repository->getRan());
        $reset = [];

        foreach ($migrations as $file) {
            $this->rollbackMigration($path, $file, $callback);
            $reset[] = $file;
        }

        return $reset;
    }

    /**
     * Get migration status.
     *
     * @param string $path Path to migrations directory
     * @return array Status array with 'ran' and 'pending' keys
     */
    public function status(string $path): array
    {
        $files = $this->getMigrationFiles($path);

        if (!$this->repository->repositoryExists()) {
            return [
                'ran' => [],
                'pending' => $files,
            ];
        }

        $batches = $this->repository->getMigrationBatches();
        $ran = [];
        $pending = [];

        foreach ($files as $file) {
            if (isset($batches[$file])) {
                $ran[] = [
                    'migration' => $file,
                    'batch' => $batches[$file],
                ];
            } else {
                $pending[] = $file;
            }
        }

        return [
            'ran' => $ran,
            'pending' => $pending,
        ];
    }

    /**
     * Run single migration.
     *
     * @param string $path Path to migrations directory
     * @param string $file Migration filename
     * @param int $batch Batch number
     * @param callable|null $callback Progress callback
     * @return void
     */
    private function runMigration(string $path, string $file, int $batch, ?callable $callback = null): void
    {
        try {
            // Load migration
            $migration = $this->resolveMigration($path, $file);

            // Set schema
            $migration->setSchema($this->schema);

            // Run up() method (DDL - no transaction needed, MySQL auto-commits DDL)
            $migration->up();

            // Log migration (DML - wrap in transaction)
            $this->connection->beginTransaction();
            $this->repository->log($file, $batch);
            $this->connection->commit();

            // Callback
            if ($callback) {
                $callback($file, 'migrated');
            }
        } catch (\Throwable $e) {
            // Rollback transaction if active
            if ($this->connection->inTransaction()) {
                $this->connection->rollback();
            }

            // Callback
            if ($callback) {
                $callback($file, 'failed', $e);
            }

            throw $e;
        }
    }

    /**
     * Rollback single migration.
     *
     * @param string $path Path to migrations directory
     * @param string $file Migration filename
     * @param callable|null $callback Progress callback
     * @return void
     */
    private function rollbackMigration(string $path, string $file, ?callable $callback = null): void
    {
        try {
            // Load migration
            $migration = $this->resolveMigration($path, $file);

            // Set schema
            $migration->setSchema($this->schema);

            // Run down() method (DDL - no transaction needed, MySQL auto-commits DDL)
            $migration->down();

            // Remove from log (DML - wrap in transaction)
            $this->connection->beginTransaction();
            $this->repository->delete($file);
            $this->connection->commit();

            // Callback
            if ($callback) {
                $callback($file, 'rolledback');
            }
        } catch (\Throwable $e) {
            // Rollback transaction if active
            if ($this->connection->inTransaction()) {
                $this->connection->rollback();
            }

            // Callback
            if ($callback) {
                $callback($file, 'failed', $e);
            }

            throw $e;
        }
    }

    /**
     * Resolve migration instance from file.
     *
     * @param string $path Path to migrations directory
     * @param string $file Migration filename
     * @return Migration
     */
    private function resolveMigration(string $path, string $file): Migration
    {
        $filePath = rtrim($path, '/') . '/' . $file;

        if (!file_exists($filePath)) {
            throw new \RuntimeException("Migration file not found: {$filePath}");
        }

        require_once $filePath;

        // Extract class name from filename
        // Format: YYYY_MM_DD_HHMMSS_CreateUsersTable.php -> CreateUsersTable
        $className = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $file);
        $className = str_replace('.php', '', $className);

        // Try to find class (might be namespaced)
        $possibleClasses = [
            $className,
            "\\{$className}",
            "App\\Database\\Migrations\\{$className}",
            "Database\\Migrations\\{$className}",
        ];

        foreach ($possibleClasses as $class) {
            if (class_exists($class)) {
                return new $class();
            }
        }

        throw new \RuntimeException("Migration class not found for file: {$file}");
    }

    /**
     * Get all migration files.
     *
     * Performance: O(N) where N = number of files
     *
     * @param string $path Path to migrations directory
     * @return array Sorted array of migration filenames
     */
    private function getMigrationFiles(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = scandir($path);
        $migrations = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (str_ends_with($file, '.php')) {
                $migrations[] = $file;
            }
        }

        // Sort chronologically
        sort($migrations);

        return $migrations;
    }

    /**
     * Ensure migration repository exists.
     *
     * @return void
     */
    private function ensureRepositoryExists(): void
    {
        if (!$this->repository->repositoryExists()) {
            $this->repository->createRepository();
        }
    }

    /**
     * Get migration repository.
     *
     * @return MigrationRepository
     */
    public function getRepository(): MigrationRepository
    {
        return $this->repository;
    }
}
