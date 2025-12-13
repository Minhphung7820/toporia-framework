<?php

declare(strict_types=1);

namespace Toporia\Framework\Console;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Class CommandDiscovery
 *
 * Auto-discovers command classes from specified directories.
 * Provides O(N) directory scan with caching for optimal performance.
 *
 * Features:
 * - Auto-discovery from directories
 * - Namespace mapping
 * - Command validation
 * - Cache support
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class CommandDiscovery
{
    /**
     * @param ContainerInterface $container For instantiating commands
     * @param string|null $cacheFile Cache file path for discovered commands
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?string $cacheFile = null
    ) {}

    /**
     * Discover commands from a directory
     *
     * @param string $directory Directory to scan
     * @param string $namespace Base namespace for the directory
     * @param bool $useCache Whether to use cache
     * @return array<string, class-string<Command>> ['command:name' => 'ClassName']
     */
    public function discover(string $directory, string $namespace, bool $useCache = true): array
    {
        // Try cache first
        if ($useCache && $this->cacheFile !== null) {
            $cached = $this->loadFromCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        // Discover commands
        $commands = $this->scanDirectory($directory, $namespace);

        // Save to cache
        if ($useCache && $this->cacheFile !== null && !empty($commands)) {
            $this->saveToCache($commands);
        }

        return $commands;
    }

    /**
     * Scan directory for command classes
     *
     * @param string $directory Directory to scan
     * @param string $namespace Base namespace
     * @return array<string, class-string<Command>>
     */
    private function scanDirectory(string $directory, string $namespace): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $commands = [];
        $namespace = rtrim($namespace, '\\');

        // Recursively scan directory
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Calculate class name from file path
            $relativePath = str_replace($directory, '', $file->getPathname());
            $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
            $className = $namespace . '\\' . str_replace('.php', '', $relativePath);

            // Validate command class
            if ($this->isValidCommandClass($className)) {
                try {
                    /** @var Command $instance */
                    $instance = $this->container->get($className);
                    $commandName = $instance->getName();

                    if ($commandName !== '') {
                        $commands[$commandName] = $className;
                    }
                } catch (\Throwable $e) {
                    // Skip commands that can't be instantiated
                    // (might have unmet dependencies or be abstract)
                    continue;
                }
            }
        }

        return $commands;
    }

    /**
     * Check if a class is a valid command
     *
     * @param string $className
     * @return bool
     */
    private function isValidCommandClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);

            // Must be concrete (not abstract or interface)
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                return false;
            }

            // Must extend Command class
            if (!$reflection->isSubclassOf(Command::class)) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Load commands from cache
     *
     * @return array<string, class-string<Command>>|null
     */
    private function loadFromCache(): ?array
    {
        if ($this->cacheFile === null || !file_exists($this->cacheFile)) {
            return null;
        }

        try {
            $cached = include $this->cacheFile;

            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // Cache corrupted, ignore
        }

        return null;
    }

    /**
     * Save commands to cache
     *
     * @param array<string, class-string<Command>> $commands
     * @return void
     */
    private function saveToCache(array $commands): void
    {
        if ($this->cacheFile === null) {
            return;
        }

        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $export = var_export($commands, true);
        $content = "<?php\n\nreturn {$export};\n";

        file_put_contents($this->cacheFile, $content, LOCK_EX);
    }

    /**
     * Clear command cache
     *
     * @return bool
     */
    public function clearCache(): bool
    {
        if ($this->cacheFile === null || !file_exists($this->cacheFile)) {
            return false;
        }

        return unlink($this->cacheFile);
    }
}
