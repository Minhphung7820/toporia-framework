<?php

declare(strict_types=1);

namespace Toporia\Framework\Config;


/**
 * Class Repository
 *
 * Core class for the Config layer providing essential functionality for
 * the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Config
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class Repository
{
    /**
     * @var array<string, mixed> Configuration items
     */
    private array $items = [];

    /**
     * @var string|null Configuration directory path (for lazy loading)
     */
    private ?string $configPath = null;

    /**
     * @var string|null Compiled config cache path
     */
    private ?string $cachePath = null;

    /**
     * @var array<string, bool> Track which config files have been loaded
     */
    private array $loaded = [];

    /**
     * @param array<string, mixed> $items Initial configuration items
     * @param string|null $cachePath Compiled config cache path
     */
    public function __construct(array $items = [], ?string $cachePath = null)
    {
        $this->items = $items;
        $this->cachePath = $cachePath;
    }

    /**
     * Get a configuration value using dot notation.
     *
     * Supports lazy loading: if config file not loaded yet, load it on first access.
     *
     * @param string $key Configuration key (e.g., 'app.name', 'database.default')
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Lazy load config file if needed
        $this->lazyLoadConfig($key);

        return data_get($this->items, $key, $default);
    }

    /**
     * Set a configuration value using dot notation.
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        data_set($this->items, $key, $value);
    }

    /**
     * Check if a configuration key exists.
     *
     * Supports lazy loading: if config file not loaded yet, load it on first access.
     *
     * @param string $key Configuration key
     * @return bool
     */
    public function has(string $key): bool
    {
        // Lazy load config file if needed
        $this->lazyLoadConfig($key);

        return data_get($this->items, $key) !== null;
    }

    /**
     * Get all configuration items.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Load configuration from a file.
     *
     * @param string $name Configuration name (file basename without .php)
     * @param string $path File path
     * @return void
     */
    public function load(string $name, string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $config = require $path;

        if (is_array($config)) {
            $this->items[$name] = $config;
            $this->loaded[$name] = true;
        }
    }

    /**
     * Load all configuration files from a directory.
     *
     * @param string $directory Configuration directory path
     * @param bool $eager If true, load all files immediately. If false, lazy load on access.
     * @return void
     */
    public function loadDirectory(string $directory, bool $eager = false): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $this->configPath = $directory;

        // Try to load from cache first
        if ($this->cachePath !== null && file_exists($this->cachePath)) {
            $this->loadFromCache();
            return;
        }

        if ($eager) {
            // Eager load all config files (current behavior)
            $files = glob($directory . '/*.php');
            foreach ($files as $file) {
                $name = basename($file, '.php');
                $this->load($name, $file);
            }
        }
        // If lazy, files will be loaded on first access via lazyLoadConfig()
    }

    /**
     * Load configuration from compiled cache file.
     *
     * @return void
     */
    private function loadFromCache(): void
    {
        if ($this->cachePath === null || !file_exists($this->cachePath)) {
            return;
        }

        $cached = require $this->cachePath;

        if (is_array($cached)) {
            $this->items = $cached;
            // Mark all as loaded
            foreach (array_keys($cached) as $name) {
                $this->loaded[$name] = true;
            }
        }
    }

    /**
     * Lazy load a configuration file if not already loaded.
     *
     * Extracts the config file name from the key (e.g., 'app.name' -> 'app').
     *
     * @param string $key Configuration key
     * @return void
     */
    private function lazyLoadConfig(string $key): void
    {
        if ($this->configPath === null) {
            return; // No config directory set
        }

        // Extract config file name from key (first segment before dot)
        $parts = explode('.', $key, 2);
        $configName = $parts[0];

        // Skip if already loaded
        if (isset($this->loaded[$configName])) {
            return;
        }

        // Load the config file
        $configFile = $this->configPath . '/' . $configName . '.php';
        if (file_exists($configFile)) {
            $this->load($configName, $configFile);
            $this->loaded[$configName] = true;
        }
    }
}
