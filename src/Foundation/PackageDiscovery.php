<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

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
     * @param string $vendorPath Path to vendor directory
     * @param string $packagesPath Path to local packages directory
     */
    public function __construct(
        private readonly string $vendorPath,
        private readonly string $packagesPath
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
            return $packages;
        }

        $installed = json_decode($content, true);

        if (!is_array($installed)) {
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

            // Check for toporia extra config
            if (!isset($package['extra']['toporia'])) {
                continue;
            }

            $toporiaConfig = $package['extra']['toporia'];

            // Get package install path
            $installPath = $this->vendorPath . '/' . $packageName;

            $parsedConfig = $this->parseToporiaConfig($toporiaConfig, $installPath);

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
            return null;
        }

        $composer = json_decode($content, true);

        if (!is_array($composer)) {
            return null;
        }

        // Must have a name
        if (!isset($composer['name'])) {
            return null;
        }

        // Check for toporia extra config
        if (!isset($composer['extra']['toporia'])) {
            return null;
        }

        $toporiaConfig = $composer['extra']['toporia'];
        $parsedConfig = $this->parseToporiaConfig($toporiaConfig, $packageDir);

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
     * @return array<string, mixed>|null
     */
    private function parseToporiaConfig(array $toporiaConfig, string $packageDir): ?array
    {
        $config = [];

        // Providers
        if (isset($toporiaConfig['providers']) && is_array($toporiaConfig['providers'])) {
            $config['providers'] = [];

            foreach ($toporiaConfig['providers'] as $provider) {
                if (is_string($provider) && class_exists($provider)) {
                    $config['providers'][] = $provider;
                } elseif (is_string($provider)) {
                    // Provider class might not be autoloaded yet, add anyway
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
                    $config['config'][$configKey] = $fullPath;
                }
            }
        }

        // Auto-discover migrations directory
        $migrationsDir = $packageDir . '/database/migrations';

        if (is_dir($migrationsDir)) {
            $config['migrations'] = [$migrationsDir];
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
                    $config['migrations'][] = $fullPath;
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

        // Don't track packages with no useful config
        if (empty($config['providers']) && empty($config['config']) && empty($config['migrations']) && empty($config['aliases'])) {
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
}
