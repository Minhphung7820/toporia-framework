<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Command;

/**
 * Class MakeSeederCommand
 *
 * Create a new database seeder class.
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
final class MakeSeederCommand extends Command
{
    protected string $signature = 'make:seeder {name : The name of the seeder}';

    protected string $description = 'Create a new database seeder class';

    public function handle(): int
    {
        $name = $this->argument('name');

        if (empty($name)) {
            $this->error('Seeder name is required.');
            return 1;
        }

        // Ensure it ends with Seeder
        $baseName = $name;
        if (!str_ends_with($name, 'Seeder')) {
            $name .= 'Seeder';
        }

        $stubPath = $this->getStubPath('seeder.stub');

        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: {$stubPath}");
            return 1;
        }

        $stubContent = file_get_contents($stubPath);

        // Guess factory and table name from seeder name
        $factoryName = $this->guessFactoryName($baseName);
        $tableName = $this->guessTableName($baseName);
        $factoryUse = $this->generateFactoryUse($factoryName);

        // Replace placeholders
        $namespace = 'Database\\Seeders';

        $replacements = [
            '{{ namespace }}' => $namespace,
            '{{namespace}}' => $namespace,
            '{{ class }}' => $name,
            '{{class}}' => $name,
            '{{ factoryName }}' => $factoryName,
            '{{factoryName}}' => $factoryName,
            '{{ tableName }}' => $tableName,
            '{{tableName}}' => $tableName,
            '{{ factoryUse }}' => $factoryUse,
            '{{factoryUse}}' => $factoryUse,
        ];

        foreach ($replacements as $placeholder => $replacement) {
            $stubContent = str_replace($placeholder, $replacement, $stubContent);
        }

        // Generate path
        $path = $this->getBasePath() . '/database/seeders';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filePath = $path . '/' . $name . '.php';

        if (file_exists($filePath)) {
            $this->error("Seeder [{$name}] already exists!");
            return 1;
        }

        if (file_put_contents($filePath, $stubContent) === false) {
            $this->error("Failed to write seeder file: {$filePath}");
            return 1;
        }

        $relativePath = str_replace($this->getBasePath() . '/', '', $filePath);
        $this->success("Seeder [{$relativePath}] created successfully.");

        return 0;
    }

    /**
     * Guess factory name from seeder name.
     *
     * @param string $seederName
     * @return string
     */
    private function guessFactoryName(string $seederName): string
    {
        // Remove 'Seeder' suffix if present
        $baseName = preg_replace('/Seeder$/', '', $seederName);

        // Try to find factory
        $factoryClass = "Database\\Factories\\{$baseName}Factory";
        if (class_exists($factoryClass)) {
            return $factoryClass . '::class';
        }

        // Return pattern for user to fill
        return "{$baseName}Factory::class";
    }

    /**
     * Guess table name from seeder name.
     *
     * @param string $seederName
     * @return string
     */
    private function guessTableName(string $seederName): string
    {
        // Remove 'Seeder' suffix if present
        $baseName = preg_replace('/Seeder$/', '', $seederName);

        // Convert PascalCase to snake_case and pluralize
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $baseName));

        // Pluralize (simple version)
        if (!str_ends_with($tableName, 's')) {
            $tableName .= 's';
        }

        return $tableName;
    }

    /**
     * Generate factory use statement.
     *
     * @param string $factoryName
     * @return string
     */
    private function generateFactoryUse(string $factoryName): string
    {
        // Extract class name from pattern like "ProductFactory::class"
        $factoryClass = preg_replace('/::class$/', '', $factoryName);

        // If it's a full class name, generate use statement
        if (str_contains($factoryClass, '\\')) {
            if (class_exists($factoryClass)) {
                return "use {$factoryClass};";
            }
        } else {
            // Try to find in Database\Factories namespace
            $fullClass = "Database\\Factories\\{$factoryClass}";
            if (class_exists($fullClass)) {
                return "use {$fullClass};";
            }
        }

        return '';
    }

        return getcwd() ?: dirname(__DIR__, 5);
    }

    /**
     * Resolve stub file path.
     */
    private function getStubPath(string $stub): string
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
}
