<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Database\Migration\Migrator;

/**
 * Class MigrateStatusCommand
 *
 * Show migration status displaying executed and pending migrations.
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
final class MigrateStatusCommand extends Command
{
    protected string $signature = 'migrate:status';
    protected string $description = 'Show migration status';

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
        // Print header
        $this->printHeader();

        try {
            $connection = $this->db->connection();
            $migrator = new Migrator($connection);

            // Get migrations path
            $migrationsPath = $this->getBasePath() . '/database/migrations';

            if (!is_dir($migrationsPath)) {
                $this->printError('Migrations directory not found!');
                return 1;
            }

            // Get migration status
            $status = $migrator->status($migrationsPath);

            $ran = $status['ran'];
            $pending = $status['pending'];

            if (empty($ran) && empty($pending)) {
                $this->printNoMigrations();
                return 0;
            }

            // Print status table
            $this->printStatusTable($ran, $pending);

            // Print summary
            $this->printSummary(count($ran), count($pending));

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
        echo "│                   MIGRATION STATUS                               │\n";
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print status table.
     */
    private function printStatusTable(array $ran, array $pending): void
    {
        // Calculate column widths
        $maxNameLength = 40;
        $batchWidth = 8;
        $statusWidth = 10;

        // Print table header
        echo self::COLOR_INFO;
        echo "  ┌─" . str_repeat("─", $maxNameLength + 2);
        echo "┬─" . str_repeat("─", $batchWidth);
        echo "┬─" . str_repeat("─", $statusWidth) . "─┐\n";
        echo self::COLOR_RESET;

        echo self::COLOR_BOLD;
        echo "  │ " . str_pad("Migration", $maxNameLength + 2);
        echo "│ " . str_pad("Batch", $batchWidth);
        echo "│ " . str_pad("Status", $statusWidth) . " │\n";
        echo self::COLOR_RESET;

        echo self::COLOR_INFO;
        echo "  ├─" . str_repeat("─", $maxNameLength + 2);
        echo "┼─" . str_repeat("─", $batchWidth);
        echo "┼─" . str_repeat("─", $statusWidth) . "─┤\n";
        echo self::COLOR_RESET;

        // Print ran migrations
        foreach ($ran as $migration) {
            $name = $this->cleanMigrationName($migration['migration']);
            $batch = (string) $migration['batch'];

            echo "  │ ";
            echo self::COLOR_DIM . str_pad($this->truncate($name, $maxNameLength), $maxNameLength + 2) . self::COLOR_RESET;
            echo "│ " . str_pad($batch, $batchWidth);
            echo "│ " . self::COLOR_SUCCESS . str_pad("Ran", $statusWidth) . self::COLOR_RESET . " │\n";
        }

        // Print pending migrations
        foreach ($pending as $migration) {
            $name = $this->cleanMigrationName($migration);

            echo "  │ ";
            echo self::COLOR_DIM . str_pad($this->truncate($name, $maxNameLength), $maxNameLength + 2) . self::COLOR_RESET;
            echo "│ " . str_pad("-", $batchWidth);
            echo "│ " . self::COLOR_WARNING . str_pad("Pending", $statusWidth) . self::COLOR_RESET . " │\n";
        }

        // Print table footer
        echo self::COLOR_INFO;
        echo "  └─" . str_repeat("─", $maxNameLength + 2);
        echo "┴─" . str_repeat("─", $batchWidth);
        echo "┴─" . str_repeat("─", $statusWidth) . "─┘\n";
        echo self::COLOR_RESET;
    }

    /**
     * Print summary.
     */
    private function printSummary(int $ranCount, int $pendingCount): void
    {
        echo "\n";

        if ($ranCount > 0) {
            echo self::COLOR_SUCCESS;
            echo "  ✓  " . self::COLOR_BOLD . $ranCount . self::COLOR_RESET . self::COLOR_SUCCESS . " migrations executed\n";
            echo self::COLOR_RESET;
        }

        if ($pendingCount > 0) {
            echo self::COLOR_WARNING;
            echo "  ℹ  " . self::COLOR_BOLD . $pendingCount . self::COLOR_RESET . self::COLOR_WARNING . " migrations pending\n";
            echo self::COLOR_RESET;
        }

        echo "\n";
    }

    /**
     * Print no migrations message.
     */
    private function printNoMigrations(): void
    {
        echo self::COLOR_DIM;
        echo "  No migration files found.\\n";
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
        echo "│  ✗  ERROR                                                       │\n";
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

    /**
     * Truncate string to max length.
     */
    private function truncate(string $str, int $maxLength): string
    {
        if (strlen($str) <= $maxLength) {
            return $str;
        }

        return substr($str, 0, $maxLength - 3) . '...';
    }
}
