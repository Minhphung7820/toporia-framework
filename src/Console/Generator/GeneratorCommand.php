<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Generator;

use Toporia\Framework\Console\Command;


/**
 * Abstract Class GeneratorCommand
 *
 * Base console command class providing CLI interface with colored output,
 * user interaction, argument/option parsing, and progress indicators.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Generator
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class GeneratorCommand extends Command
{
    /**
     * The type of class being generated (used in messages).
     */
    protected string $type = 'class';

    /**
     * Get the stub file path for the generator.
     */
    abstract protected function getStub(): string;

    /**
     * Get the default namespace for the class.
     */
    abstract protected function getDefaultNamespace(): string;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->getNameInput();

        if (empty($name)) {
            $this->error('Name argument is required.');
            return 1;
        }

        $className = $this->qualifyClass($name);
        $path = $this->getPath($className);

        // Check if file already exists
        if ($this->alreadyExists($path)) {
            $this->error("{$this->type} [{$className}] already exists!");
            return 1;
        }

        // Create directory if it doesn't exist
        $this->makeDirectory($path);

        // Build the class from stub
        $stub = $this->buildClass($className);

        // Write file
        if (file_put_contents($path, $stub) === false) {
            $this->error("Failed to write file: {$path}");
            return 1;
        }

        $relativePath = $this->getRelativePath($path);
        $this->success("{$this->type} [{$relativePath}] created successfully.");

        $this->afterCreate($className, $path);

        return 0;
    }

    /**
     * Get the name input from the command.
     */
    protected function getNameInput(): string
    {
        return trim((string) $this->argument('name'));
    }

    /**
     * Parse the class name and format according to the root namespace.
     */
    protected function qualifyClass(string $name): string
    {
        $name = str_replace('/', '\\', $name);

        $rootNamespace = $this->rootNamespace();

        if (str_starts_with($name, $rootNamespace)) {
            return $name;
        }

        return $this->qualifyClass(
            $this->getDefaultNamespace() . '\\' . $name
        );
    }

    /**
     * Get the root namespace for the class.
     */
    protected function rootNamespace(): string
    {
        return 'App\\';
    }

    /**
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        $name = str_replace($this->rootNamespace(), '', $name);
        $name = str_replace('\\', '/', $name);

        return $this->getBasePath() . '/app/' . $name . '.php';
    }

    /**
     * Get base path of the application.
     */
    protected function getBasePath(): string
    {
        // Use APP_BASE_PATH if defined, otherwise assume current working directory
        if (defined('APP_BASE_PATH')) {
            return constant('APP_BASE_PATH');
        }

        return getcwd() ?: dirname(__DIR__, 4);
    }

    /**
     * Get the framework stubs directory path.
     */
    protected function getFrameworkStubPath(): string
    {
        return dirname(__DIR__) . '/stubs';
    }

    /**
     * Resolve stub path with custom stub support.
     * Checks custom stubs in app root first, then falls back to framework stubs.
     */
    protected function resolveStubPath(string $stubName): string
    {
        // Check custom stubs first (allows user customization)
        $customPath = $this->getBasePath() . '/stubs/' . $stubName;
        if (file_exists($customPath)) {
            return $customPath;
        }

        // Fall back to framework stubs
        return $this->getFrameworkStubPath() . '/' . $stubName;
    }

    /**
     * Check if the class already exists.
     */
    protected function alreadyExists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Create directory for the class if necessary.
     */
    protected function makeDirectory(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass(string $name): string
    {
        $stub = $this->getStubContent();

        return $this->replaceNamespace($stub, $name)
            ->replaceClass($stub, $name);
    }

    /**
     * Get the stub file content.
     */
    protected function getStubContent(): string
    {
        $stubPath = $this->getStub();

        if (!file_exists($stubPath)) {
            throw new \RuntimeException("Stub file not found: {$stubPath}");
        }

        return file_get_contents($stubPath);
    }

    /**
     * Replace the namespace for the given stub.
     */
    protected function replaceNamespace(string &$stub, string $name): static
    {
        $namespace = $this->getNamespace($name);

        $searches = [
            '{{ namespace }}',
            '{{namespace}}',
            'DummyNamespace',
        ];

        $stub = str_replace($searches, $namespace, $stub);

        return $this;
    }

    /**
     * Get the full namespace for a given class.
     */
    protected function getNamespace(string $name): string
    {
        return trim(implode('\\', array_slice(explode('\\', $name), 0, -1)), '\\');
    }

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string &$stub, string $name): string
    {
        $class = $this->getClassName($name);

        $searches = [
            '{{ class }}',
            '{{class}}',
            'DummyClass',
        ];

        return str_replace($searches, $class, $stub);
    }

    /**
     * Get the class name from the fully qualified name.
     */
    protected function getClassName(string $name): string
    {
        return class_basename($name);
    }

    /**
     * Get the relative path from base.
     */
    protected function getRelativePath(string $path): string
    {
        $basePath = $this->getBasePath();

        if (str_starts_with($path, $basePath)) {
            return ltrim(substr($path, strlen($basePath)), '/\\');
        }

        return $path;
    }

    /**
     * Hook called after file creation.
     */
    protected function afterCreate(string $className, string $path): void
    {
        // Override in subclasses to perform additional actions
    }

    /**
     * Replace a variable in the stub.
     */
    protected function replaceVariable(string &$stub, string $key, string $value): static
    {
        $searches = [
            "{{ {$key} }}",
            "{{{$key}}}",
        ];

        $stub = str_replace($searches, $value, $stub);

        return $this;
    }

    /**
     * Sort imports in a stub alphabetically.
     */
    protected function sortImports(string $stub): string
    {
        // Match use statements at the beginning
        if (preg_match_all('/^use [^;]+;$/m', $stub, $matches)) {
            $imports = $matches[0];
            sort($imports);

            // Find the position of first use statement
            $firstUse = strpos($stub, 'use ');
            if ($firstUse === false) {
                return $stub;
            }

            // Find the position after last use statement
            $lastUseEnd = 0;
            foreach ($matches[0] as $match) {
                $pos = strpos($stub, $match);
                $end = $pos + strlen($match);
                if ($end > $lastUseEnd) {
                    $lastUseEnd = $end;
                }
            }

            // Remove all use statements
            $stubWithoutImports = preg_replace('/^use [^;]+;\n*/m', '', $stub);

            // Add sorted imports back
            $importsString = implode("\n", $imports) . "\n";

            // Find namespace declaration
            $namespacePos = strpos($stubWithoutImports, 'namespace ');
            if ($namespacePos !== false) {
                $namespaceEnd = strpos($stubWithoutImports, ';', $namespacePos);
                if ($namespaceEnd !== false) {
                    $before = substr($stubWithoutImports, 0, $namespaceEnd + 1);
                    $after = ltrim(substr($stubWithoutImports, $namespaceEnd + 1));
                    return $before . "\n\n" . $importsString . "\n" . $after;
                }
            }
        }

        return $stub;
    }
}

/**
 * Get the class basename.
 */
if (!function_exists('class_basename')) {
    function class_basename(string $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;
        return basename(str_replace('\\', '/', $class));
    }
}
