<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Command;

/**
 * Class MakeFactoryCommand
 *
 * Create a new model factory class.
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
final class MakeFactoryCommand extends Command
{
    protected string $signature = 'make:factory {name : The name of the factory} {--model= : The name of the model}';

    protected string $description = 'Create a new model factory class';

    public function handle(): int
    {
        $name = $this->argument('name');

        if (empty($name)) {
            $this->error('Factory name is required.');
            return 1;
        }

        // Ensure it ends with Factory
        $baseName = $name;
        if (!str_ends_with($name, 'Factory')) {
            $name .= 'Factory';
        }

        // Get model name from option or guess from factory name
        $modelClass = $this->option('model');
        if (empty($modelClass)) {
            $modelClass = $this->guessModelName($baseName);
        } else {
            // Normalize model class (ensure full namespace)
            $modelClass = $this->normalizeModelClass($modelClass);
        }

        $stubPath = $this->getStubPath('factory.stub');

        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: {$stubPath}");
            return 1;
        }

        $stubContent = file_get_contents($stubPath);

        // Replace placeholders
        $namespace = 'Database\\Factories';
        $modelName = $this->class_basename($modelClass);

        $replacements = [
            '{{ namespace }}' => $namespace,
            '{{namespace}}' => $namespace,
            '{{ class }}' => $name,
            '{{class}}' => $name,
            '{{ modelName }}' => $modelName,
            '{{modelName}}' => $modelName,
        ];

        foreach ($replacements as $placeholder => $replacement) {
            $stubContent = str_replace($placeholder, $replacement, $stubContent);
        }

        // Generate path
        $path = $this->getBasePath() . '/database/factories';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filePath = $path . '/' . $name . '.php';

        if (file_exists($filePath)) {
            $this->error("Factory [{$name}] already exists!");
            return 1;
        }

        if (file_put_contents($filePath, $stubContent) === false) {
            $this->error("Failed to write factory file: {$filePath}");
            return 1;
        }

        $relativePath = str_replace($this->getBasePath() . '/', '', $filePath);
        $this->success("Factory [{$relativePath}] created successfully.");
        $this->info("Model: {$modelClass}");

        return 0;
    }

    /**
     * Guess model name from factory name.
     *
     * @param string $factoryName
     * @return string
     */
    private function guessModelName(string $factoryName): string
    {
        // Remove 'Factory' suffix if present
        $baseName = preg_replace('/Factory$/', '', $factoryName);

        // Try common patterns (most common first)
        $patterns = [
            "App\\Infrastructure\\Persistence\\Models\\{$baseName}Model",
            "App\\Infrastructure\\Persistence\\Models\\{$baseName}",
            "App\\Domain\\{$baseName}\\{$baseName}Model",
            "App\\Domain\\{$baseName}\\{$baseName}",
            "App\\Models\\{$baseName}Model",
            "App\\Models\\{$baseName}",
        ];

        // Check if any pattern exists
        foreach ($patterns as $pattern) {
            if (class_exists($pattern)) {
                return $pattern;
            }
        }

        // Default pattern (most common in this codebase)
        return "App\\Infrastructure\\Persistence\\Models\\{$baseName}Model";
    }

    /**
     * Normalize model class name.
     *
     * @param string $modelClass
     * @return string
     */
    private function normalizeModelClass(string $modelClass): string
    {
        // If already fully qualified, return as is
        if (str_contains($modelClass, '\\')) {
            if (class_exists($modelClass)) {
                return $modelClass;
            }
            // Return as is even if not found (user will fix)
            return $modelClass;
        } else {
            // Try common namespaces (most common first)
            $patterns = [
                "App\\Infrastructure\\Persistence\\Models\\{$modelClass}Model",
                "App\\Infrastructure\\Persistence\\Models\\{$modelClass}",
                "App\\Domain\\{$modelClass}\\{$modelClass}Model",
                "App\\Domain\\{$modelClass}\\{$modelClass}",
                "App\\Models\\{$modelClass}Model",
                "App\\Models\\{$modelClass}",
            ];

            foreach ($patterns as $pattern) {
                if (class_exists($pattern)) {
                    return $pattern;
                }
            }
        }

        // Default to most common pattern
        return "App\\Infrastructure\\Persistence\\Models\\{$modelClass}Model";
    }

    /**
     * Extract state name from factory name.
     *
     * @param string $factoryName
     * @return string
     */
    private function extractStateName(string $factoryName): string
    {
        // Convert PascalCase to camelCase
        return lcfirst($factoryName);
    }

    /**
     * Get class basename helper.
     *
     * @param string $class
     * @return string
     */
    private function class_basename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }

    private function getBasePath(): string
    {
        if (defined('APP_BASE_PATH')) {
            return constant('APP_BASE_PATH');
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
