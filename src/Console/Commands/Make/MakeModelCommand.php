<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeModelCommand
 *
 * Create a new model class for database entities.
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
final class MakeModelCommand extends GeneratorCommand
{
    protected string $signature = 'make:model {name : The name of the model} {--m|migration : Create a new migration file for the model} {--f|factory : Create a new factory for the model} {--s|seed : Create a new seeder file for the model} {--a|all : Generate migration, factory, and seeder}';

    protected string $description = 'Create a new ORM model class';

    protected string $type = 'Model';

    protected function getStub(): string
    {
        return $this->resolveStubPath('model.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Infrastructure\\Persistence\\Models';
    }

    protected function buildClass(string $name): string
    {
        $stub = $this->getStubContent();

        $this->replaceNamespace($stub, $name);
        $stub = $this->replaceClass($stub, $name);

        // Generate table name from class name
        $className = $this->getClassName($name);
        $tableName = $this->tableize($className);

        $searches = ['{{ table }}', '{{table}}'];
        $stub = str_replace($searches, $tableName, $stub);

        return $stub;
    }

    protected function afterCreate(string $className, string $path): void
    {
        $baseName = $this->getClassName($className);

        if ($this->option('all') || $this->option('migration')) {
            $this->createMigration($baseName);
        }

        if ($this->option('all') || $this->option('factory')) {
            $this->createFactory($baseName);
        }

        if ($this->option('all') || $this->option('seed')) {
            $this->createSeeder($baseName);
        }
    }

    private function createMigration(string $modelName): void
    {
        $tableName = $this->tableize($modelName);
        $migrationName = "create_{$tableName}_table";

        // Output info - actual creation would need MakeMigrationCommand
        $this->info("Migration [{$migrationName}] should be created.");
    }

    private function createFactory(string $modelName): void
    {
        $factoryName = $modelName . 'Factory';

        // Get full model class name
        $modelClass = $this->getDefaultNamespace() . '\\' . $modelName;

        // Create factory command instance
        $factoryCommand = new MakeFactoryCommand();

        // Set application if available
        if ($this->getApplication() !== null) {
            $factoryCommand->setApplication($this->getApplication());
        }

        // Manually set arguments and options by directly calling handle with internal methods
        // We'll use reflection to set protected properties or create a helper method
        try {
            // Use reflection to set name and model
            $reflection = new \ReflectionClass($factoryCommand);

            // Set name property
            $nameProperty = $reflection->getProperty('arguments');
            $nameProperty->setAccessible(true);
            $nameProperty->setValue($factoryCommand, ['name' => $factoryName]);

            // Set model option
            $optionProperty = $reflection->getProperty('options');
            $optionProperty->setAccessible(true);
            $currentOptions = $optionProperty->getValue($factoryCommand) ?? [];
            $currentOptions['model'] = $modelClass;
            $optionProperty->setValue($factoryCommand, $currentOptions);

            // Set output
            $factoryCommand->setOutput($this->output);

            // Execute command
            $factoryCommand->handle();
        } catch (\Exception $e) {
            // Fallback: just show info message
            $this->info("Factory [{$factoryName}] should be created manually with: php console make:factory {$factoryName} --model={$modelClass}");
        }
    }

    private function createSeeder(string $modelName): void
    {
        $seederName = $modelName . 'Seeder';

        // Create seeder command instance
        $seederCommand = new MakeSeederCommand();

        // Set application if available
        if ($this->getApplication() !== null) {
            $seederCommand->setApplication($this->getApplication());
        }

        try {
            // Use reflection to set name
            $reflection = new \ReflectionClass($seederCommand);

            // Set name property
            $nameProperty = $reflection->getProperty('arguments');
            $nameProperty->setAccessible(true);
            $nameProperty->setValue($seederCommand, ['name' => $seederName]);

            // Set output
            $seederCommand->setOutput($this->output);

            // Execute command
            $seederCommand->handle();
        } catch (\Exception $e) {
            // Fallback: just show info message
            $this->info("Seeder [{$seederName}] should be created manually with: php console make:seeder {$seederName}");
        }
    }

    private function tableize(string $className): string
    {
        // Remove Model suffix if present
        $name = preg_replace('/Model$/', '', $className);

        // Convert CamelCase to snake_case and pluralize
        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name));

        // Simple pluralization
        if (str_ends_with($snake, 'y')) {
            return substr($snake, 0, -1) . 'ies';
        }

        if (str_ends_with($snake, 's') || str_ends_with($snake, 'x') || str_ends_with($snake, 'ch') || str_ends_with($snake, 'sh')) {
            return $snake . 'es';
        }

        return $snake . 's';
    }
}
