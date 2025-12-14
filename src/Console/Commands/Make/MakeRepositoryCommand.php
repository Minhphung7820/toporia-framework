<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Command;

/**
 * Class MakeRepositoryCommand
 *
 * Create a new repository class for data access.
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
final class MakeRepositoryCommand extends Command
{
    protected string $signature = 'make:repository {name : The name of the repository} {--entity= : The entity class name}';

    protected string $description = 'Create a new repository interface and implementation';

    public function handle(): int
    {
        $name = $this->argument('name');

        if (empty($name)) {
            $this->error('Repository name is required.');
            return 1;
        }

        // Ensure it ends with Repository
        if (!str_ends_with($name, 'Repository')) {
            $name .= 'Repository';
        }

        $entityName = $this->option('entity') ?: str_replace('Repository', '', $name);

        // Create interface
        $interfaceCreated = $this->createInterface($name, $entityName);

        // Create implementation
        $implementationCreated = $this->createImplementation($name, $entityName);

        if ($interfaceCreated && $implementationCreated) {
            $this->info("Don't forget to bind the interface to implementation in a service provider.");
            return 0;
        }

        return 1;
    }

    private function createInterface(string $name, string $entityName): bool
    {
        $stubPath = $this->resolveStubPath('repository.interface.stub');

        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: {$stubPath}");
            return false;
        }

        $stubContent = file_get_contents($stubPath);

        // Replace placeholders
        $namespace = 'App\\Domain\\' . $entityName;
        $entityClass = 'App\\Domain\\' . $entityName . '\\' . $entityName;
        $entityShortClass = $entityName;

        $stubContent = str_replace(['{{ namespace }}', '{{namespace}}'], $namespace, $stubContent);
        $stubContent = str_replace(['{{ class }}', '{{class}}'], $name, $stubContent);
        $stubContent = str_replace(['{{ entityClass }}', '{{entityClass}}'], $entityClass, $stubContent);
        $stubContent = str_replace(['{{ entityShortClass }}', '{{entityShortClass}}'], $entityShortClass, $stubContent);

        // Generate path
        $path = $this->getBasePath() . '/app/Domain/' . $entityName;

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filePath = $path . '/' . $name . '.php';

        if (file_exists($filePath)) {
            $this->warn("Interface [{$name}] already exists, skipping.");
            return true;
        }

        if (file_put_contents($filePath, $stubContent) === false) {
            $this->error("Failed to write interface file: {$filePath}");
            return false;
        }

        $relativePath = str_replace($this->getBasePath() . '/', '', $filePath);
        $this->success("Interface [{$relativePath}] created successfully.");

        return true;
    }

    private function createImplementation(string $name, string $entityName): bool
    {
        $stubPath = $this->resolveStubPath('repository.stub');

        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: {$stubPath}");
            return false;
        }

        $stubContent = file_get_contents($stubPath);

        // Replace placeholders
        $namespace = 'App\\Infrastructure\\Repositories';
        $implementationName = 'InMemory' . $name;
        $repositoryInterface = 'App\\Domain\\' . $entityName . '\\' . $name;
        $entityClass = 'App\\Domain\\' . $entityName . '\\' . $entityName;

        $stubContent = str_replace(['{{ namespace }}', '{{namespace}}'], $namespace, $stubContent);
        $stubContent = str_replace(['{{ class }}', '{{class}}'], $implementationName, $stubContent);
        $stubContent = str_replace(['{{ repositoryInterface }}', '{{repositoryInterface}}'], $repositoryInterface, $stubContent);
        $stubContent = str_replace(['{{ repositoryInterfaceShort }}', '{{repositoryInterfaceShort}}'], $name, $stubContent);
        $stubContent = str_replace(['{{ entityClass }}', '{{entityClass}}'], $entityClass, $stubContent);
        $stubContent = str_replace(['{{ entityShortClass }}', '{{entityShortClass}}'], $entityName, $stubContent);

        // Generate path
        $path = $this->getBasePath() . '/app/Infrastructure/Repositories';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filePath = $path . '/' . $implementationName . '.php';

        if (file_exists($filePath)) {
            $this->warn("Implementation [{$implementationName}] already exists, skipping.");
            return true;
        }

        if (file_put_contents($filePath, $stubContent) === false) {
            $this->error("Failed to write implementation file: {$filePath}");
            return false;
        }

        $relativePath = str_replace($this->getBasePath() . '/', '', $filePath);
        $this->success("Implementation [{$relativePath}] created successfully.");

        return true;
    }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
