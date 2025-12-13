<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Database;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Database\Migration\Migrator;
use Toporia\Framework\Database\SeederManager;

/**
 * Class MigrateFreshCommand
 *
 * Drop all tables and re-run all migrations from scratch.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Database
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MigrateFreshCommand extends Command
{
    protected string $signature = 'migrate:fresh {--seed : Indicates if the seed task should be run} {--force : Force the operation to run in production}';

    protected string $description = 'Drop all tables and re-run all migrations';

    private const COLOR_RESET = "\033[0m";
    private const COLOR_INFO = "\033[36m";      // Cyan
    private const COLOR_SUCCESS = "\033[32m";   // Green
    private const COLOR_WARNING = "\033[33m";   // Yellow
    private const COLOR_ERROR = "\033[31m";     // Red
    private const COLOR_DIM = "\033[2m";        // Dim
    private const COLOR_BOLD = "\033[1m";       // Bold

    public function __construct(
        private DatabaseManager $db
    ) {}

    public function handle(): int
    {
        if (!$this->confirmToProceed()) {
            return 1;
        }

        $startTime = microtime(true);

        // Print header
        $this->printHeader();

        try {
            $connectionProxy = $this->db->connection();
            $connection = $connectionProxy->getConnection();

            // Step 1: Drop all tables
            $this->info("  Dropping all tables...");
            $this->dropAllTables($connection);
            $this->success("  ✓ All tables dropped");

            // Step 2: Run migrations
            $this->newLine();
            $this->info("  Running migrations...");
            $migrator = new Migrator($connection);
            // Get migrations path (5 levels up from this file: src/Framework/Console/Commands/Database -> root)
            $migrationsPath = dirname(__DIR__, 5) . '/database/migrations';

            if (!is_dir($migrationsPath)) {
                $this->error("  ✗ Migrations directory not found: {$migrationsPath}");
                return 1;
            }

            $ranMigrations = $migrator->run($migrationsPath, function ($migration, $status) {
                if ($status === 'running') {
                    $this->line("  {$this->colorDim('Migrating:')} {$migration}");
                } elseif ($status === 'done') {
                    $this->line("  {$this->colorSuccess('✓')} {$this->colorDim('Migrating:')} {$migration} {$this->colorSuccess('[DONE]')}");
                } elseif ($status === 'failed') {
                    $this->line("  {$this->colorError('✗')} {$this->colorDim('Migrating:')} {$migration} {$this->colorError('[FAILED]')}");
                }
            });

            if (empty($ranMigrations)) {
                $this->line($this->colorDim("  No migrations to run"));
            } else {
                $this->line($this->colorSuccess("  ✓ Migrated: " . count($ranMigrations) . " migrations"));
            }

            // Step 3: Run seeders if requested
            if ($this->option('seed')) {
                $this->newLine();
                $this->info("  Running seeders...");
                $seederManager = new SeederManager($this->db);
                $seederManager->run();
                $this->success("  ✓ Seeding completed");
            }

            $duration = number_format((microtime(true) - $startTime) * 1000, 2);
            $this->newLine();
            $this->printSuccessBox("Fresh migration completed in {$duration}ms");

            return 0;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->printErrorBox($e->getMessage());
            $this->line($this->colorDim("  File: " . $e->getFile() . ":" . $e->getLine()));
            return 1;
        }
    }

    /**
     * Drop all tables from database.
     */
    private function dropAllTables($connection): void
    {
        $driver = $connection->getDriverName();
        // Database name not needed for getting tables

        // Disable foreign key checks for MySQL
        if ($driver === 'mysql') {
            $connection->execute('SET FOREIGN_KEY_CHECKS = 0');
        }

        try {
            // Get all table names
            $tables = $this->getAllTables($connection, $driver);

            if (empty($tables)) {
                return;
            }

            // Drop each table
            foreach ($tables as $table) {
                $tableName = $this->quoteTableName($table, $driver);
                $connection->execute("DROP TABLE IF EXISTS {$tableName}");
            }
        } finally {
            // Re-enable foreign key checks for MySQL
            if ($driver === 'mysql') {
                $connection->execute('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }

    /**
     * Get all table names from database.
     */
    private function getAllTables($connection, string $driver): array
    {
        $sql = match ($driver) {
            'mysql' => "SHOW TABLES",
            'pgsql' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
            default => throw new \RuntimeException("Unsupported driver: {$driver}")
        };

        $results = $connection->select($sql);

        if (empty($results)) {
            return [];
        }

        // Extract table names from results
        $tables = [];
        foreach ($results as $row) {
            // MySQL returns array with key like "Tables_in_database"
            // PostgreSQL returns array with "tablename" key
            // SQLite returns array with "name" key
            $value = array_values($row)[0] ?? null;
            if ($value && $value !== 'migrations') {
                $tables[] = $value;
            }
        }

        return $tables;
    }

    /**
     * Quote table name for SQL.
     */
    private function quoteTableName(string $table, string $driver): string
    {
        return match ($driver) {
            'mysql' => "`{$table}`",
            'pgsql' => "\"{$table}\"",
            'sqlite' => "\"{$table}\"",
            default => $table
        };
    }

    /**
     * Print header.
     */
    private function printHeader(): void
    {
        $this->line($this->colorInfo(""));
        $this->line($this->colorInfo("┌─────────────────────────────────────────────────────────────────┐"));
        $this->line($this->colorInfo("│                    FRESH MIGRATIONS                              │"));
        $this->line($this->colorInfo("└─────────────────────────────────────────────────────────────────┘"));
        $this->newLine();
    }

    /**
     * Print success box.
     */
    private function printSuccessBox(string $message): void
    {
        $this->line($this->colorSuccess("┌─────────────────────────────────────────────────────────────────┐"));
        $this->line($this->colorSuccess("  ✓ " . $this->colorBold($message)));
        $this->line($this->colorSuccess("└─────────────────────────────────────────────────────────────────┘"));
    }

    /**
     * Print error box.
     */
    private function printErrorBox(string $message): void
    {
        $this->line($this->colorError("┌─────────────────────────────────────────────────────────────────┐"));
        $this->line($this->colorError("  ✗ " . $this->colorBold("MIGRATION FAILED")));
        $this->line($this->colorError("└─────────────────────────────────────────────────────────────────┘"));
        $this->newLine();
        $this->error("  Error: " . $message);
    }

    /**
     * Color helpers.
     */
    private function colorInfo(string $text): string
    {
        return self::COLOR_INFO . $text . self::COLOR_RESET;
    }

    private function colorSuccess(string $text): string
    {
        return self::COLOR_SUCCESS . $text . self::COLOR_RESET;
    }

    private function colorError(string $text): string
    {
        return self::COLOR_ERROR . $text . self::COLOR_RESET;
    }

    private function colorWarning(string $text): string
    {
        return self::COLOR_WARNING . $text . self::COLOR_RESET;
    }

    private function colorDim(string $text): string
    {
        return self::COLOR_DIM . $text . self::COLOR_RESET;
    }

    private function colorBold(string $text): string
    {
        return self::COLOR_BOLD . $text . self::COLOR_RESET;
    }

    private function confirmToProceed(): bool
    {
        $env = env('APP_ENV', 'production');

        if ($env === 'production' && !$this->option('force')) {
            $this->warn('Application is in production!');
            return $this->confirm('Do you really wish to run this command?', false);
        }

        return true;
    }
}
