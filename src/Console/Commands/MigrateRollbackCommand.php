<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Database\Migration\Migrator;

/**
 * Class MigrateRollbackCommand
 *
 * Rollback database migrations with batch-based or step-based control.
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
final class MigrateRollbackCommand extends Command
{
    protected string $signature = 'migrate:rollback';
    protected string $description = 'Rollback database migrations';

    private const COLOR_RESET = "\033[0m";
    private const COLOR_INFO = "\033[36m";      // Cyan
    private const COLOR_SUCCESS = "\033[32m";   // Green
    private const COLOR_WARNING = "\033[33m";   // Yellow
    private const COLOR_ERROR = "\033[31m";     // Red
    private const COLOR_DIM = "\033[2m";        // Dim
    private const COLOR_BOLD = "\033[1m";       // Bold

    public function __construct(
        private DatabaseManager $db
    ) {
    }

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);

        // Print header
        $this->printHeader();

        try {
            $connection = $this->db->connection();
            $migrator = new Migrator($connection);

            // Get migrations path
            $migrationsPath = dirname(__DIR__, 4) . '/database/migrations';

            if (!is_dir($migrationsPath)) {
                $this->printError('Migrations directory not found!');
                return 1;
            }

            // Get step option
            $step = (int) $this->option('step', 1);

            // Check if there are migrations to rollback
            $status = $migrator->status($migrationsPath);

            if (empty($status['ran'])) {
                $this->printNothingToRollback();
                return 0;
            }

            // Show migrations to rollback count
            $lastBatch = $migrator->getRepository()->getLastBatchNumber();
            $this->printRollbackInfo($lastBatch, $step);

            // Rollback migrations with progress tracking
            $rolledBack = [];
            for ($i = 0; $i < $step; $i++) {
                $batch = $migrator->rollback($migrationsPath, function($file, $status, $error = null) {
                    $this->printMigrationStatus($file, $status, $error);
                });

                if (empty($batch)) {
                    break; // No more migrations to rollback
                }

                $rolledBack = array_merge($rolledBack, $batch);
            }

            // Print summary
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->printSummary(count($rolledBack), $duration);

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
        echo "│                  ROLLBACK MIGRATIONS                             │\n";
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print rollback info.
     */
    private function printRollbackInfo(int $lastBatch, int $step): void
    {
        echo self::COLOR_WARNING;
        echo "  ℹ  Rolling back batch: " . self::COLOR_BOLD . $lastBatch . self::COLOR_RESET . self::COLOR_WARNING;
        if ($step > 1) {
            echo " (last {$step} batches)";
        }
        echo "\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print nothing to rollback message.
     */
    private function printNothingToRollback(): void
    {
        echo self::COLOR_SUCCESS;
        echo "  ✓  Nothing to rollback" . self::COLOR_RESET . "\n";
        echo self::COLOR_DIM;
        echo "     No migrations have been executed yet.\\n";
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
            'rolledback' => $this->printRolledBack($displayName),
            'failed' => $this->printFailed($displayName, $error),
            default => null
        };
    }

    /**
     * Print rolled back status.
     */
    private function printRolledBack(string $name): void
    {
        echo self::COLOR_SUCCESS;
        echo "  ✓  ";
        echo self::COLOR_RESET;
        echo self::COLOR_DIM . "Rolling back:  " . self::COLOR_RESET;
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
        echo self::COLOR_DIM . "Rolling back:  " . self::COLOR_RESET;
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

        // Rolled back count
        echo self::COLOR_SUCCESS;
        echo "  ✓  " . self::COLOR_BOLD . "Rolled back: " . $count . self::COLOR_RESET . self::COLOR_SUCCESS . " migrations\n";
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
        echo "│  ✗  ROLLBACK FAILED                                             │\n";
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
