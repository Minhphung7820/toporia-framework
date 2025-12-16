<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

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
     */
    public function __construct(
        private readonly string $manifestPath,
        private readonly string $basePath,
        private readonly string $vendorPath,
        private readonly string $packagesPath
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
     * @return array<string, string> Config key => config file path
     */
    public function config(): array
    {
        return $this->getManifest()['config'] ?? [];
    }

    /**
     * Get all discovered migration paths.
     *
     * @return array<string> Migration directory paths
     */
    public function migrations(): array
    {
        return $this->getManifest()['migrations'] ?? [];
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
            $this->vendorPath,
            $this->packagesPath
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
}
