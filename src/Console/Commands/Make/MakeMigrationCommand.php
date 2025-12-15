<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Command;

/**
 * Class MakeMigrationCommand
 *
 * Create a new database migration file.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Make
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MakeMigrationCommand extends Command
{
    protected string $signature = 'make:migration {name : The name of the migration} {--create= : The table to be created} {--table= : The table to be modified} {--path= : The location where the migration file should be created} {--anonymous : Create anonymous class migration (Laravel 8+ style)}';

    protected string $description = 'Create a new migration file';

    public function handle(): int
    {
        $name = $this->argument('name');

        if (empty($name)) {
            $this->error('Migration name is required.');
            return 1;
        }

        $table = $this->option('create') ?: $this->option('table');
        $create = (bool) $this->option('create');
        $anonymous = (bool) $this->option('anonymous');

        // Determine table name from migration name if not provided
        if (empty($table)) {
            if (preg_match('/^create_(\w+)_table$/', $name, $matches)) {
                $table = $matches[1];
                $create = true;
            } elseif (preg_match('/^add_\w+_to_(\w+)_table$/', $name, $matches)) {
                $table = $matches[1];
            } elseif (preg_match('/^remove_\w+_from_(\w+)_table$/', $name, $matches)) {
                $table = $matches[1];
            }
        }

        // Select stub based on anonymous flag and operation type
        if ($anonymous) {
            $stub = $create ? 'migration.anonymous.stub' : 'migration.update.anonymous.stub';
        } else {
            $stub = $create ? 'migration.stub' : 'migration.update.stub';
        }

        $stubPath = $this->resolveStubPath($stub);

        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: {$stubPath}");
            return 1;
        }

        $stubContent = file_get_contents($stubPath);

        // Generate class name from migration name (e.g., create_users_table -> CreateUsersTable)
        $className = $this->generateClassName($name);

        // Generate description
        $description = $this->generateDescription($name);

        // Replace placeholders
        $stubContent = str_replace(['{{ table }}', '{{table}}'], $table ?: 'table_name', $stubContent);
        $stubContent = str_replace(['{{ class }}', '{{class}}'], $className, $stubContent);
        $stubContent = str_replace(['{{ description }}', '{{description}}'], $description, $stubContent);

        // Generate timestamp prefix (YYYY_MM_DD_HHMMSS format)
        $timestamp = $this->generateTimestamp();

        // Generate filename with timestamp prefix
        $filename = "{$timestamp}_{$className}.php";

        // Determine path
        $path = $this->option('path') ?: $this->getBasePath() . '/database/migrations';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filePath = $path . '/' . $filename;

        // Check if file already exists
        if (file_exists($filePath)) {
            $this->error("Migration [{$className}] already exists!");
            return 1;
        }

        if (file_put_contents($filePath, $stubContent) === false) {
            $this->error("Failed to write migration file: {$filePath}");
            return 1;
        }

        $relativePath = str_replace($this->getBasePath() . '/', '', $filePath);
        $this->success("Migration [{$relativePath}] created successfully.");

        return 0;
    }

    /**
     * Generate class name from migration name.
     * E.g., create_users_table -> CreateUsersTable
     */
    private function generateClassName(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }

    /**
     * Generate description from migration name.
     */
    private function generateDescription(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name)) . '.';
    }

    /**
     * Generate timestamp prefix for migration filename.
     * Format: YYYY_MM_DD_HHMMSS (e.g., 2024_01_01_000001)
     */
    private function generateTimestamp(): string
    {
        $now = new \DateTime();
        $date = $now->format('Y_m_d');

        // Get the next sequence number for this date
        $sequence = $this->getNextSequenceNumber($date);

        return sprintf('%s_%06d', $date, $sequence);
    }

    /**
     * Get the next sequence number for migrations on the same date.
     * This ensures migrations are ordered correctly even if created on the same day.
     */
    private function getNextSequenceNumber(string $date): int
    {
        $path = $this->option('path') ?: $this->getBasePath() . '/database/migrations';

        if (!is_dir($path)) {
            return 1;
        }

        $files = scandir($path);
        $maxSequence = 0;
        $pattern = '/^' . preg_quote($date, '/') . '_(\d{6})_/';

        foreach ($files as $file) {
            if (preg_match($pattern, $file, $matches)) {
                $sequence = (int) $matches[1];
                if ($sequence > $maxSequence) {
                    $maxSequence = $sequence;
                }
            }
        }

        return $maxSequence + 1;
    }

    /**
     * Resolve stub file path.
     */
    private function resolveStubPath(string $stub): string
    {
        $stubPath = dirname(__DIR__, 2) . '/stubs/' . $stub;

        if (file_exists($stubPath)) {
            return $stubPath;
        }

        // Fallback to alternative paths
        $alternativePaths = [
            dirname(__DIR__, 3) . '/stubs/' . $stub,
            $this->getBasePath() . '/stubs/' . $stub,
        ];

        foreach ($alternativePaths as $altPath) {
            if (file_exists($altPath)) {
                return $altPath;
            }
        }

        return $stubPath;
    }

    /**
     * Get base path of the application.
     */
    private function getBasePath(): string
    {
        return getcwd() ?: dirname(__DIR__, 5);
    }
}
