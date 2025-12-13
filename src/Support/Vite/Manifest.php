<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Vite;

/**
 * Class Manifest
 *
 * Vite Manifest Parser - Parses and caches Vite's manifest.json file.
 * Provides fast asset lookup for production builds.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Vite
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * Manifest Format:
 * ```json
 * {
 *   "resources/js/app.js": {
 *     "file": "assets/app-abc123.js",
 *     "src": "resources/js/app.js",
 *     "isEntry": true,
 *     "css": ["assets/app-def456.css"]
 *   }
 * }
 * ```
 *
 * Performance:
 * - O(1) lookup after initial parse
 * - Single file read (cached)
 * - Lazy loading (only when needed)
 */
final class Manifest
{
    private ?array $manifest = null;
    private string $manifestPath;

    public function __construct(string $manifestPath)
    {
        $this->manifestPath = $manifestPath;
    }

    /**
     * Get asset information for an entry point.
     *
     * @param string $entry Entry point path (e.g., 'resources/js/app.js')
     * @return array|null Asset data or null if not found
     */
    public function get(string $entry): ?array
    {
        $manifest = $this->load();

        // Try exact match first
        if (isset($manifest[$entry])) {
            return $manifest[$entry];
        }

        // Try with leading slash
        $entryWithSlash = '/' . ltrim($entry, '/');
        if (isset($manifest[$entryWithSlash])) {
            return $manifest[$entryWithSlash];
        }

        // Try without leading slash
        $entryWithoutSlash = ltrim($entry, '/');
        if (isset($manifest[$entryWithoutSlash])) {
            return $manifest[$entryWithoutSlash];
        }

        return null;
    }

    /**
     * Check if entry exists in manifest.
     *
     * @param string $entry Entry point path
     * @return bool
     */
    public function has(string $entry): bool
    {
        return $this->get($entry) !== null;
    }

    /**
     * Get all entries in manifest.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->load();
    }

    /**
     * Load and parse manifest file.
     *
     * @return array
     * @throws \RuntimeException If manifest is invalid
     */
    private function load(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (!file_exists($this->manifestPath)) {
            throw new \RuntimeException("Vite manifest file not found: {$this->manifestPath}");
        }

        $content = file_get_contents($this->manifestPath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read Vite manifest: {$this->manifestPath}");
        }

        $manifest = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "Invalid JSON in Vite manifest: " . json_last_error_msg()
            );
        }

        if (!is_array($manifest)) {
            throw new \RuntimeException("Vite manifest must be a JSON object");
        }

        $this->manifest = $manifest;

        return $this->manifest;
    }
}
