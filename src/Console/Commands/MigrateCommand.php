<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Database\Migration\Migrator;

/**
 * Class MigrateCommand
 *
 * Run database migrations with batch tracking and rollback support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MigrateCommand extends Command
{
    protected string $signature = 'migrate';
    protected string $description = 'Run database migrations';

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

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);

        // Print header
        $this->printHeader();

        try {
            $connectionProxy = $this->db->connection();
            $connection = $connectionProxy->getConnection();
            $migrator = new Migrator($connection);

            // Get migrations path
            $migrationsPath = $this->getBasePath() . '/database/migrations';

            if (!is_dir($migrationsPath)) {
                $this->printError('Migrations directory not found!');
                return 1;
            }

            // Check for pending migrations
            $status = $migrator->status($migrationsPath);

            if (empty($status['pending'])) {
                $this->printNothingToMigrate();
                return 0;
            }

            // Show pending migrations count
            $pendingCount = count($status['pending']);
            $this->printPendingInfo($pendingCount);

            // Run migrations with progress tracking
            $ranMigrations = $migrator->run($migrationsPath, function ($file, $status, $error = null) {
                $this->printMigrationStatus($file, $status, $error);
            });

            // Print summary
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->printSummary(count($ranMigrations), $duration);

            return 0;
        } catch (\Throwable $e) {
            $this->printException($e);
            return 1;
        }
    }

    /**
     * Print beautiful header.
     */
    private function printHeader(): void
    {
        echo self::COLOR_INFO;
        echo "\n";
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo "│                    DATABASE MIGRATIONS                           │\n";
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print pending migrations info.
     */
    private function printPendingInfo(int $count): void
    {
        echo self::COLOR_WARNING;
        echo "  ℹ  Pending migrations: " . self::COLOR_BOLD . $count . self::COLOR_RESET . self::COLOR_WARNING . "\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print nothing to migrate message.
     */
    private function printNothingToMigrate(): void
    {
        echo self::COLOR_SUCCESS;
        echo "  ✓  Nothing to migrate" . self::COLOR_RESET . "\n";
        echo self::COLOR_DIM;
        echo "     All migrations have been executed.\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print migration status.
     */
    private function printMigrationStatus(string $file, string $status, ?\Throwable $error = null): void
    {
        // Clean filename for display
        $displayName = $this->cleanMigrationName($file);

        match ($status) {
            'migrated' => $this->printMigrated($displayName),
            'failed' => $this->printFailed($displayName, $error),
            default => null
        };
    }

    /**
     * Print migrated status.
     */
    private function printMigrated(string $name): void
    {
        echo self::COLOR_SUCCESS;
        echo "  ✓  ";
        echo self::COLOR_RESET;
        echo self::COLOR_DIM . "Migrating:  " . self::COLOR_RESET;
        echo $name;
        echo self::COLOR_SUCCESS . "  [DONE]" . self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print failed status.
     */
    private function printFailed(string $name, ?\Throwable $error): void
    {
        echo self::COLOR_ERROR;
        echo "  ✗  ";
        echo self::COLOR_RESET;
        echo self::COLOR_DIM . "Migrating:  " . self::COLOR_RESET;
        echo $name;
        echo self::COLOR_ERROR . "  [FAILED]" . self::COLOR_RESET;
        echo "\n";

        if ($error) {
            echo self::COLOR_DIM;
            echo "     Error: " . $error->getMessage() . "\n";
            echo self::COLOR_RESET;
        }
    }

    /**
     * Print summary.
     */
    private function printSummary(int $count, float $duration): void
    {
        echo "\n";
        echo self::COLOR_INFO;
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo self::COLOR_RESET;

        // Migrated count
        echo self::COLOR_SUCCESS;
        echo "  ✓  " . self::COLOR_BOLD . "Migrated: " . $count . self::COLOR_RESET . self::COLOR_SUCCESS . " migrations\n";
        echo self::COLOR_RESET;

        // Duration
        echo self::COLOR_DIM;
        echo "     Duration: " . $duration . "ms\n";
        echo self::COLOR_RESET;

        echo self::COLOR_INFO;
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print error message.
     */
    private function printError(string $message): void
    {
        echo "\n";
        echo self::COLOR_ERROR;
        echo "  ✗  ERROR\n";
        echo "     " . $message . "\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print exception.
     */
    private function printException(\Throwable $e): void
    {
        echo "\n";
        echo self::COLOR_ERROR;
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo "│  ✗  MIGRATION FAILED                                            │\n";
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";

        echo self::COLOR_ERROR;
        echo "  Error: " . $e->getMessage() . "\n";
        echo self::COLOR_RESET;

        echo self::COLOR_DIM;
        echo "  File:  " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Clean migration name for display.
     *
     * Removes timestamp prefix and .php extension.
     */
    private function cleanMigrationName(string $file): string
    {
        // Remove .php extension
        $name = str_replace('.php', '', $file);

        // Remove timestamp prefix (YYYY_MM_DD_HHMMSS_)
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);

        return $name;
    }
}
