<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

use Toporia\Framework\Log\Contracts\LoggerInterface;

/**
 * Class PackageManifest
 *
 * Manages the cached package manifest for auto-discovery of service providers,
 * configurations, and migrations from installed packages.
 *
 * Features:
 * - Caches discovered packages for O(1) lookup
 * - Auto-rebuilds when composer.lock changes
 * - Supports both vendor packages and local packages directory
 * - Thread-safe file operations
 *
 * Performance:
 * - O(1) manifest read after caching
 * - O(N) discovery only on cache rebuild
 * - Minimal memory footprint
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Foundation
 * @since       2025-12-16
 */
final class PackageManifest
{
    /**
     * Cached manifest data.
     *
     * @var array<string, mixed>|null
     */
    private ?array $manifest = null;

    /**
     * @param string $manifestPath Path to cached manifest file (bootstrap/cache/packages.php)
     * @param string $basePath Application base path
     * @param string $vendorPath Path to vendor directory
     * @param string $packagesPath Path to local packages directory
     * @param LoggerInterface|null $logger Optional logger for error reporting
     */
    public function __construct(
        private readonly string $manifestPath,
        private readonly string $basePath,
        private readonly string $vendorPath,
        private readonly string $packagesPath,
        private readonly ?LoggerInterface $logger = null
    ) {}

    /**
     * Get all discovered service providers.
     *
     * Only returns providers whose classes exist and are autoloadable.
     * This provides graceful degradation when packages are discovered
     * but not properly installed or autoloaded.
     *
     * @return array<string> Provider class names
     */
    public function providers(): array
    {
        $providers = $this->getManifest()['providers'] ?? [];

        // Filter to only providers that exist and are autoloadable
        return array_values(array_filter($providers, function (string $provider): bool {
            return class_exists($provider);
        }));
    }

    /**
     * Get all discovered configuration files.
     *
     * Resolves relative paths to absolute paths at runtime.
     *
     * @return array<string, string> Config key => absolute config file path
     */
    public function config(): array
    {
        $configs = $this->getManifest()['config'] ?? [];

        // Resolve relative paths to absolute
        return array_map(fn($path) => $this->resolveAbsolutePath($path), $configs);
    }

    /**
     * Get all discovered migration paths.
     *
     * Resolves relative paths to absolute paths at runtime.
     *
     * @return array<string> Absolute migration directory paths
     */
    public function migrations(): array
    {
        $migrations = $this->getManifest()['migrations'] ?? [];

        // Resolve relative paths to absolute
        return array_map(fn($path) => $this->resolveAbsolutePath($path), $migrations);
    }

    /**
     * Get all discovered aliases.
     *
     * @return array<string, string> Alias => class
     */
    public function aliases(): array
    {
        return $this->getManifest()['aliases'] ?? [];
    }

    /**
     * Get all discovered route files from packages.
     *
     * Returns route configurations organized by package, including:
     * - Route file paths (resolved to absolute)
     * - Middleware groups to apply
     * - Route prefix
     * - Route namespace
     *
     * @return array<string, array<string, mixed>> Package name => route config
     */
    public function routes(): array
    {
        $routes = $this->getManifest()['routes'] ?? [];

        // Resolve 'path' in each route config to absolute path
        return array_map(function ($packageRoutes) {
            return array_map(function ($routeConfig) {
                if (isset($routeConfig['path'])) {
                    $routeConfig['path'] = $this->resolveAbsolutePath($routeConfig['path']);
                }
                return $routeConfig;
            }, $packageRoutes);
        }, $routes);
    }

    /**
     * Get all discovered view paths and namespaces from packages.
     *
     * Returns view configurations organized by package, including:
     * - View directory paths (for global view search) - resolved to absolute
     * - View namespaces (for package::view syntax) - resolved to absolute
     *
     * Format:
     * [
     *     'package-name' => [
     *         'paths' => ['/absolute/path/to/views'],
     *         'namespaces' => ['pkg' => '/absolute/path/to/views']
     *     ]
     * ]
     *
     * @return array<string, array<string, mixed>> Package name => view config
     */
    public function views(): array
    {
        $views = $this->getManifest()['views'] ?? [];

        // Resolve paths and namespaces to absolute
        return array_map(function ($viewConfig) {
            if (isset($viewConfig['paths'])) {
                $viewConfig['paths'] = array_map(fn($path) => $this->resolveAbsolutePath($path), $viewConfig['paths']);
            }
            if (isset($viewConfig['namespaces'])) {
                $viewConfig['namespaces'] = array_map(fn($path) => $this->resolveAbsolutePath($path), $viewConfig['namespaces']);
            }
            return $viewConfig;
        }, $views);
    }

    /**
     * Get all discovered middleware from packages.
     *
     * @return array<string, array<string, mixed>> Middleware groups and aliases
     */
    public function middleware(): array
    {
        return $this->getManifest()['middleware'] ?? [];
    }

    /**
     * Get all discovered commands from packages.
     *
     * @return array<string> Command class names
     */
    public function commands(): array
    {
        return $this->getManifest()['commands'] ?? [];
    }

    /**
     * Get all discovered event listeners from packages.
     *
     * @return array<string, array<string>> Event => [Listener classes]
     */
    public function events(): array
    {
        return $this->getManifest()['events'] ?? [];
    }

    /**
     * Get all discovered translations from packages.
     *
     * Resolves relative paths to absolute paths at runtime.
     *
     * @return array<string, array<string, string>> Package => [Locale => Path]
     */
    public function translations(): array
    {
        $translations = $this->getManifest()['translations'] ?? [];

        // Resolve relative paths to absolute
        return array_map(function ($packageTranslations) {
            return array_map(fn($path) => $this->resolveAbsolutePath($path), $packageTranslations);
        }, $translations);
    }

    /**
     * Get the entire manifest.
     *
     * @return array<string, mixed>
     */
    public function getManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        // Check if we need to rebuild the manifest
        if ($this->shouldRecompile()) {
            $this->build();
        }

        // Load from cache
        if (file_exists($this->manifestPath)) {
            $this->manifest = require $this->manifestPath;
        } else {
            $this->manifest = $this->getDefaultManifest();
        }

        return $this->manifest;
    }

    /**
     * Build and cache the package manifest.
     *
     * Discovers all packages from vendor and local packages directories,
     * extracts their Toporia configuration, and caches the result.
     *
     * @return void
     */
    public function build(): void
    {
        $discovery = new PackageDiscovery(
            $this->basePath,
            $this->vendorPath,
            $this->packagesPath,
            $this->logger
        );

        $packages = $discovery->discover();

        // Merge all package configurations
        $manifest = $this->getDefaultManifest();

        foreach ($packages as $packageName => $packageConfig) {
            // Providers
            if (isset($packageConfig['providers']) && is_array($packageConfig['providers'])) {
                foreach ($packageConfig['providers'] as $provider) {
                    if (!in_array($provider, $manifest['providers'], true)) {
                        $manifest['providers'][] = $provider;
                    }
                }
            }

            // Config files
            if (isset($packageConfig['config']) && is_array($packageConfig['config'])) {
                foreach ($packageConfig['config'] as $configKey => $configPath) {
                    $manifest['config'][$configKey] = $configPath;
                }
            }

            // Migration paths
            if (isset($packageConfig['migrations']) && is_array($packageConfig['migrations'])) {
                foreach ($packageConfig['migrations'] as $migrationPath) {
                    if (!in_array($migrationPath, $manifest['migrations'], true)) {
                        $manifest['migrations'][] = $migrationPath;
                    }
                }
            }

            // Aliases
            if (isset($packageConfig['aliases']) && is_array($packageConfig['aliases'])) {
                foreach ($packageConfig['aliases'] as $alias => $class) {
                    $manifest['aliases'][$alias] = $class;
                }
            }

            // Routes
            if (isset($packageConfig['routes']) && is_array($packageConfig['routes'])) {
                $manifest['routes'][$packageName] = $packageConfig['routes'];
            }

            // Middleware
            if (isset($packageConfig['middleware']) && is_array($packageConfig['middleware'])) {
                // Merge middleware groups
                if (isset($packageConfig['middleware']['groups'])) {
                    foreach ($packageConfig['middleware']['groups'] as $groupName => $middlewareList) {
                        if (!isset($manifest['middleware']['groups'][$groupName])) {
                            $manifest['middleware']['groups'][$groupName] = [];
                        }
                        $manifest['middleware']['groups'][$groupName] = array_merge(
                            $manifest['middleware']['groups'][$groupName],
                            $middlewareList
                        );
                    }
                }

                // Merge middleware aliases
                if (isset($packageConfig['middleware']['aliases'])) {
                    foreach ($packageConfig['middleware']['aliases'] as $alias => $class) {
                        $manifest['middleware']['aliases'][$alias] = $class;
                    }
                }
            }

            // Commands - Merge as associative array (name => class)
            if (isset($packageConfig['commands']) && is_array($packageConfig['commands'])) {
                // Commands should be already mapped as ['command:name' => 'Class'] from PackageDiscovery
                foreach ($packageConfig['commands'] as $commandName => $commandClass) {
                    // Only add if not already exists (prevent duplicates)
                    if (!isset($manifest['commands'][$commandName])) {
                        $manifest['commands'][$commandName] = $commandClass;
                    }
                }
            }

            // Event Listeners - Merge as event => [listeners]
            if (isset($packageConfig['events']) && is_array($packageConfig['events'])) {
                foreach ($packageConfig['events'] as $event => $listeners) {
                    if (!isset($manifest['events'][$event])) {
                        $manifest['events'][$event] = [];
                    }
                    $manifest['events'][$event] = array_merge(
                        $manifest['events'][$event],
                        $listeners
                    );
                }
            }

            // Translations - Merge as locale => path
            if (isset($packageConfig['translations']) && is_array($packageConfig['translations'])) {
                foreach ($packageConfig['translations'] as $locale => $translationPath) {
                    if (!isset($manifest['translations'][$packageName])) {
                        $manifest['translations'][$packageName] = [];
                    }
                    $manifest['translations'][$packageName][$locale] = $translationPath;
                }
            }
        }

        // Write manifest to cache
        $this->writeManifest($manifest);

        // Update in-memory cache
        $this->manifest = $manifest;
    }

    /**
     * Check if the manifest needs to be recompiled.
     *
     * Triggers recompile if:
     * - Manifest file doesn't exist
     * - composer.lock has been modified since last build
     *
     * @return bool
     */
    private function shouldRecompile(): bool
    {
        // No manifest file exists
        if (!file_exists($this->manifestPath)) {
            return true;
        }

        // Check if composer.lock changed
        $composerLockPath = $this->basePath . '/composer.lock';

        if (!file_exists($composerLockPath)) {
            return false;
        }

        $lockMtime = filemtime($composerLockPath);
        $manifestMtime = filemtime($this->manifestPath);

        // Rebuild if lock file is newer
        if ($lockMtime !== false && $manifestMtime !== false) {
            return $lockMtime > $manifestMtime;
        }

        return false;
    }

    /**
     * Write manifest to cache file.
     *
     * @param array<string, mixed> $manifest
     * @return void
     */
    private function writeManifest(array $manifest): void
    {
        // Ensure cache directory exists
        $cacheDir = dirname($this->manifestPath);

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // Generate PHP code
        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * Package Manifest (Auto-generated)\n";
        $content .= " *\n";
        $content .= " * This file is auto-generated by Toporia Framework.\n";
        $content .= " * Do not edit manually. Run 'php console package:discover' to regenerate.\n";
        $content .= " *\n";
        $content .= " * Generated at: " . date('Y-m-d H:i:s') . "\n";
        $content .= " */\n\n";
        $content .= "return " . $this->exportArray($manifest) . ";\n";

        // Write with atomic operation (write to temp, then rename)
        $tempPath = $this->manifestPath . '.tmp.' . getmypid();

        file_put_contents($tempPath, $content, LOCK_EX);
        rename($tempPath, $this->manifestPath);

        // Make sure opcache is cleared for this file
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->manifestPath, true);
        }
    }

    /**
     * Export array to PHP code.
     *
     * @param array<string, mixed> $array
     * @param int $indent
     * @return string
     */
    private function exportArray(array $array, int $indent = 0): string
    {
        $padding = str_repeat('    ', $indent);
        $innerPadding = str_repeat('    ', $indent + 1);

        $lines = ["["];

        $isAssoc = array_keys($array) !== range(0, count($array) - 1);

        foreach ($array as $key => $value) {
            $keyPart = $isAssoc ? var_export($key, true) . ' => ' : '';

            if (is_array($value)) {
                $valuePart = $this->exportArray($value, $indent + 1);
            } else {
                $valuePart = var_export($value, true);
            }

            $lines[] = $innerPadding . $keyPart . $valuePart . ',';
        }

        $lines[] = $padding . ']';

        return implode("\n", $lines);
    }

    /**
     * Get default empty manifest structure.
     *
     * @return array<string, mixed>
     */
    private function getDefaultManifest(): array
    {
        return [
            'providers' => [],
            'config' => [],
            'migrations' => [],
            'aliases' => [],
            'routes' => [],
            'middleware' => [
                'groups' => [],
                'aliases' => [],
            ],
            'commands' => [],
            'events' => [],
            'translations' => [],
        ];
    }

    /**
     * Clear the cached manifest.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->manifest = null;

        if (file_exists($this->manifestPath)) {
            unlink($this->manifestPath);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($this->manifestPath, true);
            }
        }
    }

    /**
     * Get the manifest file path.
     *
     * @return string
     */
    public function getManifestPath(): string
    {
        return $this->manifestPath;
    }

    /**
     * Resolve relative path to absolute path at runtime.
     *
     * This allows manifest to store relative paths, making it portable across environments
     * (HOST vs Docker containers with different mount points).
     *
     * @param string $path Relative or absolute path
     * @return string Absolute path
     */
    private function resolveAbsolutePath(string $path): string
    {
        // Already absolute path
        if ($path[0] === '/') {
            return $path;
        }

        // Relative path - prepend base path
        return $this->basePath . '/' . $path;
    }
}
