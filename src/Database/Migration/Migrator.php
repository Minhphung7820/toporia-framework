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
 * - Multiple migration paths support (app + packages)
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
 * @version     2.0.0
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
     * Additional migration paths (from packages).
     *
     * @var array<string>
     */
    private array $paths = [];

    /**
     * Mapping of migration filename to its source path.
     *
     * @var array<string, string>
     */
    private array $migrationPathMap = [];

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
     * Register an additional migration path.
     *
     * Used by ServiceProviders to register package migrations.
     *
     * @param string $path Path to migrations directory
     * @return void
     */
    public function path(string $path): void
    {
        $path = rtrim($path, '/\\');

        if (!in_array($path, $this->paths, true) && is_dir($path)) {
            $this->paths[] = $path;
        }
    }

    /**
     * Get all registered migration paths.
     *
     * @return array<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * Run pending migrations from all paths.
     *
     * @param string|null $path Specific path (null = all paths)
     * @param callable|null $callback Progress callback (migration name, status)
     * @param bool $pretend If true, dump SQL without executing
     * @return array Ran migrations
     */
    public function run(?string $path = null, ?callable $callback = null, bool $pretend = false): array
    {
        // Ensure repository exists (not needed in pretend mode)
        if (!$pretend) {
            $this->ensureRepositoryExists();
        }

        // Get pending migrations from specified path or all paths
        $allFiles = $this->getMigrationFilesFromPaths($path);

        if (!$pretend) {
            $ran = $this->repository->getRan();
            $pending = array_diff(array_keys($allFiles), $ran);
        } else {
            // In pretend mode, show all migrations (can't check database)
            $pending = array_keys($allFiles);
        }

        if (empty($pending)) {
            return [];
        }

        // Sort pending migrations chronologically
        sort($pending);

        // Get next batch number
        $batch = $pretend ? 0 : $this->repository->getNextBatchNumber();

        // Run pending migrations
        $ranMigrations = [];

        foreach ($pending as $file) {
            $migrationPath = $allFiles[$file];

            if ($pretend) {
                $this->pretendMigration($migrationPath, $file, $callback);
            } else {
                $this->runMigration($migrationPath, $file, $batch, $callback);
            }
            $ranMigrations[] = $file;
        }

        return $ranMigrations;
    }

    /**
     * Rollback last batch of migrations.
     *
     * @param string|null $path Specific path (null = all paths)
     * @param callable|null $callback Progress callback
     * @return array Rolled back migrations
     */
    public function rollback(?string $path = null, ?callable $callback = null): array
    {
        if (!$this->repository->repositoryExists()) {
            return [];
        }

        // Get last batch
        $migrations = $this->repository->getLast();

        if (empty($migrations)) {
            return [];
        }

        // Build path map for all paths
        $allFiles = $this->getMigrationFilesFromPaths($path);

        // Rollback migrations
        $rolledBack = [];

        foreach ($migrations as $file) {
            // Find the path for this migration
            $migrationPath = $allFiles[$file] ?? null;

            if ($migrationPath === null) {
                // Migration file not found in any path, skip but warn
                if ($callback) {
                    $callback($file, 'not_found');
                }
                continue;
            }

            $this->rollbackMigration($migrationPath, $file, $callback);
            $rolledBack[] = $file;
        }

        return $rolledBack;
    }

    /**
     * Reset all migrations.
     *
     * @param string|null $path Specific path (null = all paths)
     * @param callable|null $callback Progress callback
     * @return array All migrations
     */
    public function reset(?string $path = null, ?callable $callback = null): array
    {
        if (!$this->repository->repositoryExists()) {
            return [];
        }

        // Build path map for all paths
        $allFiles = $this->getMigrationFilesFromPaths($path);

        $migrations = array_reverse($this->repository->getRan());
        $resetMigrations = [];

        foreach ($migrations as $file) {
            $migrationPath = $allFiles[$file] ?? null;

            if ($migrationPath === null) {
                if ($callback) {
                    $callback($file, 'not_found');
                }
                continue;
            }

            $this->rollbackMigration($migrationPath, $file, $callback);
            $resetMigrations[] = $file;
        }

        return $resetMigrations;
    }

    /**
     * Get migration status from all paths.
     *
     * @param string|null $path Specific path (null = all paths)
     * @return array Status array with 'ran' and 'pending' keys
     */
    public function status(?string $path = null): array
    {
        $allFiles = $this->getMigrationFilesFromPaths($path);
        $files = array_keys($allFiles);

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
                    'path' => $allFiles[$file],
                ];
            } else {
                $pending[] = [
                    'migration' => $file,
                    'path' => $allFiles[$file],
                ];
            }
        }

        return [
            'ran' => $ran,
            'pending' => $pending,
        ];
    }

    /**
     * Get migration files from all registered paths.
     *
     * Returns a map of filename => source path for locating migrations.
     *
     * @param string|null $specificPath If provided, only scan this path
     * @return array<string, string> Filename => source directory path
     */
    private function getMigrationFilesFromPaths(?string $specificPath = null): array
    {
        $allFiles = [];

        if ($specificPath !== null) {
            // Only scan specific path
            $files = $this->getMigrationFiles($specificPath);
            foreach ($files as $file) {
                $allFiles[$file] = $specificPath;
            }
        } else {
            // Scan all registered paths
            foreach ($this->paths as $migrationPath) {
                $files = $this->getMigrationFiles($migrationPath);
                foreach ($files as $file) {
                    // First registered path wins (app migrations take priority)
                    if (!isset($allFiles[$file])) {
                        $allFiles[$file] = $migrationPath;
                    }
                }
            }
        }

        // Sort by filename (chronological order)
        ksort($allFiles);

        return $allFiles;
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
     * Pretend to run migration (show SQL without executing).
     *
     * Note: This is a simplified pretend mode that shows the migration class name
     * and basic info. Full SQL query preview would require Schema Builder modifications.
     *
     * @param string $path Path to migrations directory
     * @param string $file Migration filename
     * @param callable|null $callback Progress callback
     * @return void
     */
    private function pretendMigration(string $path, string $file, ?callable $callback = null): void
    {
        try {
            // Load migration to verify it's valid
            $migration = $this->resolveMigration($path, $file);

            // Extract migration class info
            $className = get_class($migration);
            $isAnonymous = str_contains($className, 'class@anonymous');

            $info = [
                'file' => $file,
                'path' => $path,
                'class' => $isAnonymous ? 'Anonymous Migration Class' : $className,
                'message' => 'Would execute migration (pretend mode - no SQL preview available)',
            ];

            // Callback with pretend info
            if ($callback) {
                $callback($file, 'pretend', $info);
            }
        } catch (\Throwable $e) {
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

        // Execute migration file and capture return value
        // This supports both anonymous classes (Laravel 8+ style) and named classes
        $migration = require $filePath;

        // Check if file returned an anonymous class instance (Laravel 8+ pattern)
        if ($migration instanceof Migration) {
            return $migration;
        }

        // Fallback to named class resolution (backward compatibility)
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

        throw new \RuntimeException("Migration class not found for file: {$file}. Use either 'return new class extends Migration' or a named class matching the filename.");
    }

    /**
     * Get all migration files from a single directory.
     *
     * Performance: O(N) where N = number of files
     *
     * @param string $path Path to migrations directory
     * @return array<string> Sorted array of migration filenames
     */
    private function getMigrationFiles(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = scandir($path);

        if ($files === false) {
            return [];
        }

        $migrations = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            // Only accept properly formatted migration files: YYYY_MM_DD_HHMMSS_ClassName.php
            // This prevents accidentally running non-migration PHP files
            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_\w+\.php$/', $file)) {
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

    /**
     * Check if any migrations have been run.
     *
     * @return bool
     */
    public function hasRunMigrations(): bool
    {
        if (!$this->repository->repositoryExists()) {
            return false;
        }

        return count($this->repository->getRan()) > 0;
    }

    /**
     * Get count of pending migrations.
     *
     * @param string|null $path Specific path (null = all paths)
     * @return int
     */
    public function pendingCount(?string $path = null): int
    {
        $status = $this->status($path);

        return count($status['pending']);
    }
}
