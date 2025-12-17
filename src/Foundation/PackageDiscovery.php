<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

use Toporia\Framework\Log\Contracts\LoggerInterface;
use Toporia\Framework\Foundation\ServiceProvider;

/**
 * Class PackageDiscovery
 *
 * Discovers Toporia packages from vendor and local packages directories
 * by scanning composer.json files for the "extra.toporia" configuration.
 *
 * Features:
 * - Scans vendor directory for composer packages
 * - Scans local packages directory for development packages
 * - Extracts providers, config, migrations, and aliases
 * - Validates package configurations
 *
 * Performance:
 * - O(N) where N = number of composer.json files
 * - Reads JSON files once per discovery
 * - Uses directory iteration for efficiency
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Foundation
 * @since       2025-12-16
 */
final class PackageDiscovery
{
    /**
     * @param string $basePath Application base path
     * @param string $vendorPath Path to vendor directory
     * @param string $packagesPath Path to local packages directory
     * @param LoggerInterface|null $logger Optional logger for error reporting
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $vendorPath,
        private readonly string $packagesPath,
        private readonly ?LoggerInterface $logger = null
    ) {}

    /**
     * Discover all packages with Toporia configuration.
     *
     * @return array<string, array<string, mixed>> Package name => package config
     */
    public function discover(): array
    {
        $packages = [];

        // Discover from local packages first (higher priority)
        $localPackages = $this->discoverFromPackages();
        foreach ($localPackages as $name => $config) {
            $packages[$name] = $config;
        }

        // Discover from vendor (lower priority, won't override local)
        $vendorPackages = $this->discoverFromVendor();
        foreach ($vendorPackages as $name => $config) {
            if (!isset($packages[$name])) {
                $packages[$name] = $config;
            }
        }

        return $packages;
    }

    /**
     * Discover packages from vendor directory.
     *
     * @return array<string, array<string, mixed>>
     */
    private function discoverFromVendor(): array
    {
        $packages = [];

        if (!is_dir($this->vendorPath)) {
            return $packages;
        }

        // Get installed packages from composer
        $installedPath = $this->vendorPath . '/composer/installed.json';

        if (file_exists($installedPath)) {
            $packages = array_merge($packages, $this->discoverFromInstalledJson($installedPath));
        }

        return $packages;
    }

    /**
     * Discover packages from composer's installed.json.
     *
     * This is more efficient than scanning all vendor directories.
     *
     * @param string $installedPath Path to installed.json
     * @return array<string, array<string, mixed>>
     */
    private function discoverFromInstalledJson(string $installedPath): array
    {
        $packages = [];

        $content = file_get_contents($installedPath);

        if ($content === false) {
            $this->logger?->error('Failed to read installed.json', [
                'path' => $installedPath,
                'error' => error_get_last()['message'] ?? 'Unknown error'
            ]);
            return $packages;
        }

        $installed = json_decode($content, true);

        if (!is_array($installed)) {
            $this->logger?->warning('Invalid JSON in installed.json', [
                'path' => $installedPath,
                'error' => json_last_error_msg()
            ]);
            return $packages;
        }

        // Handle both Composer 1.x and 2.x formats
        $packageList = $installed['packages'] ?? $installed;

        if (!is_array($packageList)) {
            return $packages;
        }

        foreach ($packageList as $package) {
            if (!isset($package['name'])) {
                continue;
            }

            $packageName = $package['name'];

            // Skip ignored packages
            if ($this->shouldIgnore($packageName)) {
                continue;
            }

            // Check for toporia extra config
            if (!isset($package['extra']['toporia'])) {
                continue;
            }

            $toporiaConfig = $package['extra']['toporia'];

            // Get package install path
            $installPath = $this->vendorPath . '/' . $packageName;

            $parsedConfig = $this->parseToporiaConfig($toporiaConfig, $installPath, $package);

            if ($parsedConfig !== null) {
                $packages[$packageName] = $parsedConfig;
            }
        }

        return $packages;
    }

    /**
     * Discover packages from local packages directory.
     *
     * @return array<string, array<string, mixed>>
     */
    private function discoverFromPackages(): array
    {
        $packages = [];

        if (!is_dir($this->packagesPath)) {
            return $packages;
        }

        // Scan packages directory
        $iterator = new \DirectoryIterator($this->packagesPath);

        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $packageDir = $item->getPathname();
            $composerPath = $packageDir . '/composer.json';

            if (!file_exists($composerPath)) {
                continue;
            }

            $parsedConfig = $this->parseComposerJson($composerPath, $packageDir);

            if ($parsedConfig !== null) {
                $packages[$parsedConfig['name']] = $parsedConfig['config'];
            }
        }

        return $packages;
    }

    /**
     * Parse composer.json and extract Toporia configuration.
     *
     * @param string $composerPath Path to composer.json
     * @param string $packageDir Package directory path
     * @return array{name: string, config: array<string, mixed>}|null
     */
    private function parseComposerJson(string $composerPath, string $packageDir): ?array
    {
        $content = file_get_contents($composerPath);

        if ($content === false) {
            $this->logger?->warning('Failed to read composer.json', [
                'path' => $composerPath,
                'package_dir' => $packageDir,
                'error' => error_get_last()['message'] ?? 'Unknown error'
            ]);
            return null;
        }

        $composer = json_decode($content, true);

        if (!is_array($composer)) {
            $this->logger?->warning('Invalid JSON in composer.json', [
                'path' => $composerPath,
                'error' => json_last_error_msg()
            ]);
            return null;
        }

        // Must have a name
        if (!isset($composer['name'])) {
            $this->logger?->warning('Package composer.json missing "name" field', [
                'path' => $composerPath
            ]);
            return null;
        }

        // Skip ignored packages
        if ($this->shouldIgnore($composer['name'])) {
            return null;
        }

        // Check for toporia extra config
        if (!isset($composer['extra']['toporia'])) {
            return null;
        }

        $toporiaConfig = $composer['extra']['toporia'];
        $parsedConfig = $this->parseToporiaConfig($toporiaConfig, $packageDir, $composer);

        if ($parsedConfig === null) {
            return null;
        }

        return [
            'name' => $composer['name'],
            'config' => $parsedConfig,
        ];
    }

    /**
     * Parse Toporia configuration from package extra.
     *
     * @param array<string, mixed> $toporiaConfig The extra.toporia config
     * @param string $packageDir Package directory path
     * @param array<string, mixed> $composer Full composer.json data
     * @return array<string, mixed>|null
     */
    private function parseToporiaConfig(array $toporiaConfig, string $packageDir, array $composer): ?array
    {
        $config = [];

        // Providers
        if (isset($toporiaConfig['providers']) && is_array($toporiaConfig['providers'])) {
            $config['providers'] = [];

            foreach ($toporiaConfig['providers'] as $provider) {
                if (!is_string($provider)) {
                    $this->logger?->warning('Invalid provider type in package config', [
                        'provider' => gettype($provider),
                        'package' => $composer['name'] ?? 'unknown'
                    ]);
                    continue;
                }

                // Validate provider class if it exists
                if (class_exists($provider)) {
                    // Check if provider extends ServiceProvider
                    if (!is_subclass_of($provider, ServiceProvider::class)) {
                        $this->logger?->error('Provider does not extend ServiceProvider', [
                            'provider' => $provider,
                            'package' => $composer['name'] ?? 'unknown'
                        ]);
                        continue;
                    }
                    $config['providers'][] = $provider;
                } else {
                    // Provider class might not be autoloaded yet, add anyway
                    // Validation will happen when the class is actually loaded
                    $config['providers'][] = $provider;
                }
            }
        }

        // Config files
        if (isset($toporiaConfig['config']) && is_array($toporiaConfig['config'])) {
            $config['config'] = [];

            foreach ($toporiaConfig['config'] as $configKey => $configPath) {
                if (!is_string($configPath)) {
                    continue;
                }

                // Resolve relative path
                $fullPath = $packageDir . '/' . ltrim($configPath, '/');

                if (file_exists($fullPath)) {
                    // Store relative path from base directory
                    $config['config'][$configKey] = $this->makeRelativePath($fullPath);
                }
            }
        }

        // Auto-discover migrations directory
        $migrationsDir = $packageDir . '/database/migrations';

        if (is_dir($migrationsDir)) {
            $config['migrations'] = [$this->makeRelativePath($migrationsDir)];
        }

        // Explicit migrations config (overrides auto-discovery)
        if (isset($toporiaConfig['migrations']) && is_array($toporiaConfig['migrations'])) {
            $config['migrations'] = [];

            foreach ($toporiaConfig['migrations'] as $migrationPath) {
                if (!is_string($migrationPath)) {
                    continue;
                }

                // Resolve relative path
                $fullPath = $packageDir . '/' . ltrim($migrationPath, '/');

                if (is_dir($fullPath)) {
                    $config['migrations'][] = $this->makeRelativePath($fullPath);
                }
            }
        }

        // Aliases
        if (isset($toporiaConfig['aliases']) && is_array($toporiaConfig['aliases'])) {
            $config['aliases'] = [];

            foreach ($toporiaConfig['aliases'] as $alias => $class) {
                if (is_string($alias) && is_string($class)) {
                    $config['aliases'][$alias] = $class;
                }
            }
        }

        // Views
        if (isset($toporiaConfig['views']) && is_array($toporiaConfig['views'])) {
            $config['views'] = [];

            // View paths (simple array of paths)
            if (isset($toporiaConfig['views']['paths']) && is_array($toporiaConfig['views']['paths'])) {
                foreach ($toporiaConfig['views']['paths'] as $viewPath) {
                    if (!is_string($viewPath)) {
                        continue;
                    }

                    $fullPath = $packageDir . '/' . ltrim($viewPath, '/');
                    if (is_dir($fullPath)) {
                        $config['views']['paths'][] = $this->makeRelativePath($fullPath);
                    }
                }
            }

            // View namespaces (key => path)
            if (isset($toporiaConfig['views']['namespaces']) && is_array($toporiaConfig['views']['namespaces'])) {
                $config['views']['namespaces'] = [];

                foreach ($toporiaConfig['views']['namespaces'] as $namespace => $viewPath) {
                    if (!is_string($namespace) || !is_string($viewPath)) {
                        continue;
                    }

                    $fullPath = $packageDir . '/' . ltrim($viewPath, '/');
                    if (is_dir($fullPath)) {
                        $config['views']['namespaces'][$namespace] = $this->makeRelativePath($fullPath);
                    }
                }
            }
        }

        // Auto-discover views directory
        $viewsDir = $packageDir . '/resources/views';
        if (is_dir($viewsDir) && (!isset($toporiaConfig['views']) || empty($toporiaConfig['views']))) {
            // Extract package namespace from composer name (vendor/package => package)
            $packageName = $composer['name'] ?? '';
            $namespace = '';

            if (strpos($packageName, '/') !== false) {
                [, $namespace] = explode('/', $packageName, 2);
            }

            if ($namespace) {
                $config['views'] = [
                    'paths' => [$this->makeRelativePath($viewsDir)],
                    'namespaces' => [$namespace => $this->makeRelativePath($viewsDir)],
                ];
            }
        }

        // Routes
        if (isset($toporiaConfig['routes']) && is_array($toporiaConfig['routes'])) {
            $config['routes'] = [];

            foreach ($toporiaConfig['routes'] as $routeConfig) {
                if (is_string($routeConfig)) {
                    // Simple route file path
                    $fullPath = $packageDir . '/' . ltrim($routeConfig, '/');
                    if (file_exists($fullPath)) {
                        $config['routes'][] = [
                            'path' => $this->makeRelativePath($fullPath),
                            'middleware' => [],
                            'prefix' => '',
                            'namespace' => '',
                        ];
                    }
                } elseif (is_array($routeConfig)) {
                    // Route config with middleware, prefix, namespace
                    $routePath = $routeConfig['path'] ?? $routeConfig['file'] ?? null;
                    if ($routePath) {
                        $fullPath = $packageDir . '/' . ltrim($routePath, '/');
                        if (file_exists($fullPath)) {
                            $config['routes'][] = [
                                'path' => $this->makeRelativePath($fullPath),
                                'middleware' => $routeConfig['middleware'] ?? [],
                                'prefix' => $routeConfig['prefix'] ?? '',
                                'namespace' => $routeConfig['namespace'] ?? '',
                                'name' => $routeConfig['name'] ?? '',
                            ];
                        }
                    }
                }
            }
        }

        // Auto-discover routes directory
        $routesDir = $packageDir . '/routes';
        if (is_dir($routesDir) && (!isset($toporiaConfig['routes']) || empty($toporiaConfig['routes']))) {
            $config['routes'] = [];
            $routeFiles = ['web.php', 'api.php'];

            foreach ($routeFiles as $routeFile) {
                $fullPath = $routesDir . '/' . $routeFile;
                if (file_exists($fullPath)) {
                    $middleware = ($routeFile === 'api.php') ? ['api'] : ['web'];
                    $prefix = ($routeFile === 'api.php') ? 'api' : '';

                    $config['routes'][] = [
                        'path' => $this->makeRelativePath($fullPath),
                        'middleware' => $middleware,
                        'prefix' => $prefix,
                        'namespace' => '',
                        'name' => '',
                    ];
                }
            }
        }

        // Middleware
        if (isset($toporiaConfig['middleware']) && is_array($toporiaConfig['middleware'])) {
            $config['middleware'] = [
                'groups' => [],
                'aliases' => [],
            ];

            // Middleware groups
            if (isset($toporiaConfig['middleware']['groups']) && is_array($toporiaConfig['middleware']['groups'])) {
                foreach ($toporiaConfig['middleware']['groups'] as $groupName => $middlewareList) {
                    if (is_array($middlewareList)) {
                        $config['middleware']['groups'][$groupName] = $middlewareList;
                    }
                }
            }

            // Middleware aliases
            if (isset($toporiaConfig['middleware']['aliases']) && is_array($toporiaConfig['middleware']['aliases'])) {
                foreach ($toporiaConfig['middleware']['aliases'] as $alias => $class) {
                    if (is_string($alias) && is_string($class)) {
                        $config['middleware']['aliases'][$alias] = $class;
                    }
                }
            }
        }

        // Commands - Cache command name => class mapping for O(1) lookup
        if (isset($toporiaConfig['commands']) && is_array($toporiaConfig['commands'])) {
            $config['commands'] = [];

            foreach ($toporiaConfig['commands'] as $commandDef) {
                // Support two formats:
                // 1. Simple: "Vendor\\Package\\MyCommand"
                // 2. Optimized: ["name" => "my:command", "class" => "Vendor\\Package\\MyCommand"]

                if (is_array($commandDef) && isset($commandDef['name'], $commandDef['class'])) {
                    // Pre-mapped command (already has name => class)
                    $config['commands'][$commandDef['name']] = $commandDef['class'];
                } elseif (is_string($commandDef)) {
                    // Need to extract command name via reflection (done ONCE during manifest build)
                    // This is much better than reflecting every request!
                    $commandClass = $commandDef;

                    if (class_exists($commandClass)) {
                        try {
                            $reflection = new \ReflectionClass($commandClass);

                            if ($reflection->hasProperty('signature')) {
                                $defaultProps = $reflection->getDefaultProperties();
                                $signature = $defaultProps['signature'] ?? null;

                                if ($signature) {
                                    // Extract command name from signature
                                    $commandName = explode(' ', trim($signature))[0] ?? null;

                                    if ($commandName && !empty($commandName)) {
                                        // Store as name => class for O(1) lookup
                                        $config['commands'][$commandName] = $commandClass;
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            $this->logger?->warning('Failed to reflect command class', [
                                'class' => $commandClass,
                                'error' => $e->getMessage()
                            ]);
                            continue;
                        }
                    }
                }
            }
        }

        // Event Listeners - Map event => [listener1, listener2, ...]
        if (isset($toporiaConfig['events']) && is_array($toporiaConfig['events'])) {
            $config['events'] = [];

            foreach ($toporiaConfig['events'] as $event => $listeners) {
                if (!is_string($event)) {
                    $this->logger?->warning('Invalid event name in package config', [
                        'event' => gettype($event),
                        'package' => $composer['name'] ?? 'unknown'
                    ]);
                    continue;
                }

                // Listeners can be a single string or array of strings
                $listenerList = is_array($listeners) ? $listeners : [$listeners];

                foreach ($listenerList as $listener) {
                    if (!is_string($listener)) {
                        $this->logger?->warning('Invalid listener type in package config', [
                            'listener' => gettype($listener),
                            'event' => $event,
                            'package' => $composer['name'] ?? 'unknown'
                        ]);
                        continue;
                    }

                    $config['events'][$event][] = $listener;
                }
            }
        }

        // Translations
        if (isset($toporiaConfig['translations']) && is_array($toporiaConfig['translations'])) {
            $config['translations'] = [];

            foreach ($toporiaConfig['translations'] as $locale => $translationPath) {
                if (!is_string($locale) || !is_string($translationPath)) {
                    continue;
                }

                $fullPath = $packageDir . '/' . ltrim($translationPath, '/');
                if (is_dir($fullPath)) {
                    $config['translations'][$locale] = $this->makeRelativePath($fullPath);
                }
            }
        }

        // Auto-discover translations directory (lang/)
        $langDir = $packageDir . '/lang';
        if (is_dir($langDir) && (!isset($toporiaConfig['translations']) || empty($toporiaConfig['translations']))) {
            $config['translations'] = [];

            // Scan for locale directories (e.g., lang/en, lang/vi)
            $iterator = new \DirectoryIterator($langDir);
            foreach ($iterator as $item) {
                if ($item->isDot() || !$item->isDir()) {
                    continue;
                }

                $locale = $item->getFilename();
                $config['translations'][$locale] = $this->makeRelativePath($item->getPathname());
            }
        }

        // Don't track packages with no useful config
        if (
            empty($config['providers']) &&
            empty($config['config']) &&
            empty($config['migrations']) &&
            empty($config['aliases']) &&
            empty($config['routes']) &&
            empty($config['middleware']['groups']) &&
            empty($config['middleware']['aliases']) &&
            empty($config['commands']) &&
            empty($config['events']) &&
            empty($config['translations'])
        ) {
            return null;
        }

        return $config;
    }

    /**
     * Get list of package names that should NOT be auto-discovered.
     *
     * These are core packages that are always loaded or have special handling.
     *
     * @return array<string>
     */
    public function getIgnoredPackages(): array
    {
        return [
            'toporia/framework',  // Core framework, always loaded
        ];
    }

    /**
     * Check if a package should be ignored.
     *
     * @param string $packageName
     * @return bool
     */
    public function shouldIgnore(string $packageName): bool
    {
        return in_array($packageName, $this->getIgnoredPackages(), true);
    }

    /**
     * Convert absolute path to relative path from base directory.
     *
     * This ensures manifest paths work across different environments (HOST vs Docker).
     *
     * @param string $absolutePath Absolute path
     * @return string Relative path from base directory
     */
    private function makeRelativePath(string $absolutePath): string
    {
        // Normalize paths (remove trailing slashes, resolve . and ..)
        $absolutePath = rtrim(realpath($absolutePath) ?: $absolutePath, '/');
        $basePath = rtrim(realpath($this->basePath) ?: $this->basePath, '/');

        // Check if path is within base directory
        if (str_starts_with($absolutePath, $basePath . '/')) {
            return substr($absolutePath, strlen($basePath) + 1);
        }

        // Path is outside base directory - return as-is (shouldn't happen normally)
        return $absolutePath;
    }
}
