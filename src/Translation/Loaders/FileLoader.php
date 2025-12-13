<?php

declare(strict_types=1);

namespace Toporia\Framework\Translation\Loaders;

use Toporia\Framework\Translation\Contracts\LoaderInterface;
use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class FileLoader
 *
 * Loads translations from JSON or PHP array files.
 * Supports nested keys and multiple namespaces.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Translation\Loaders
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class FileLoader implements LoaderInterface
{
    /**
     * @var array<string, string> Namespace => path mapping
     */
    private array $namespaces = [];

    /**
     * @var array<string, array<string, array>> Loaded translations cache
     * Format: ['locale.namespace' => ['key' => 'value']]
     */
    private array $loaded = [];

    /**
     * @param string $path Base path to translation files
     * @param CacheInterface|null $cache Optional cache for loaded translations
     */
    public function __construct(
        private string $path,
        private ?CacheInterface $cache = null
    ) {
        // Default namespace (empty) points to base path
        $this->addNamespace('', $path);
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $locale, string $namespace): array
    {
        $cacheKey = "trans.{$locale}.{$namespace}";

        // Check memory cache first (fastest)
        if (isset($this->loaded[$cacheKey])) {
            return $this->loaded[$cacheKey];
        }

        // Check persistent cache (if available)
        if ($this->cache !== null && $this->cache->has($cacheKey)) {
            $translations = $this->cache->get($cacheKey);
            if (is_array($translations)) {
                $this->loaded[$cacheKey] = $translations;
                return $translations;
            }
        }

        // Load from file
        $translations = $this->loadFromFile($locale, $namespace);

        // Cache in memory
        $this->loaded[$cacheKey] = $translations;

        // Cache persistently (if cache available)
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, $translations, 3600); // 1 hour
        }

        return $translations;
    }

    /**
     * Load translations from file.
     *
     * Supports both JSON and PHP array formats:
     * - JSON: resources/lang/{locale}/{namespace}.json
     * - PHP: resources/lang/{locale}/{namespace}.php (returns array)
     *
     * @param string $locale Locale code
     * @param string $namespace Namespace (empty string for default)
     * @return array<string, mixed> Translation array
     */
    private function loadFromFile(string $locale, string $namespace): array
    {
        $namespacePath = $this->namespaces[$namespace] ?? $this->path;
        $basePath = rtrim($namespacePath, DIRECTORY_SEPARATOR);
        $localePath = $basePath . DIRECTORY_SEPARATOR . $locale;

        // If namespace is empty, try to load default file
        if ($namespace === '') {
            // Try PHP file first (default.php)
            $phpPath = $localePath . DIRECTORY_SEPARATOR . 'default.php';
            if (file_exists($phpPath)) {
                return $this->loadPhp($phpPath);
            }

            // Try JSON file (default.json)
            $jsonPath = $localePath . DIRECTORY_SEPARATOR . 'default.json';
            if (file_exists($jsonPath)) {
                return $this->loadJson($jsonPath);
            }

            // Return empty array if no default file found
            return [];
        }

        // Try PHP file first (for complex translations with logic)
        $phpPath = $localePath . DIRECTORY_SEPARATOR . $namespace . '.php';
        if (file_exists($phpPath)) {
            return $this->loadPhp($phpPath);
        }

        // Try JSON file (preferred for simple translations)
        $jsonPath = $localePath . DIRECTORY_SEPARATOR . $namespace . '.json';
        if (file_exists($jsonPath)) {
            return $this->loadJson($jsonPath);
        }

        // Return empty array if file not found
        return [];
    }

    /**
     * Load translations from JSON file.
     *
     * @param string $path File path
     * @return array<string, mixed> Translation array
     */
    private function loadJson(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * Load translations from PHP file.
     *
     * PHP files must return an array.
     *
     * @param string $path File path
     * @return array<string, mixed> Translation array
     */
    private function loadPhp(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $translations = require $path;
        if (!is_array($translations)) {
            return [];
        }

        return $translations;
    }

    /**
     * {@inheritdoc}
     */
    public function addNamespace(string $namespace, string $path): void
    {
        $this->namespaces[$namespace] = rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * {@inheritdoc}
     */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    /**
     * Clear loaded translations cache.
     *
     * Useful for testing or when translations are updated.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->loaded = [];
    }
}
