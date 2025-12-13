<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Vite;

/**
 * Class Vite
 *
 * Vite Asset Loader - Integrates Vite with Toporia Framework for seamless asset management.
 * Supports both development (HMR) and production (manifest) modes.
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
 * Features:
 * - Automatic dev/prod mode detection
 * - Hot Module Replacement (HMR) in development
 * - Manifest-based asset loading in production
 * - CSS extraction and injection
 * - Multiple entry points support
 *
 * Usage:
 * ```php
 * // In Blade/View template
 * {!! vite('resources/js/app.js') !!}
 * {!! vite_css('resources/js/app.js') !!}
 * ```
 *
 * Performance:
 * - O(1) manifest lookup in production
 * - Zero overhead in development (direct Vite server)
 * - Lazy manifest loading (only when needed)
 */
final class Vite
{
    private ?Manifest $manifest = null;
    private bool $isDevelopment;

    public function __construct(
        private readonly string $manifestPath,
        private readonly string $devServerUrl,
        private readonly bool $devServerEnabled,
        private readonly array $entrypoints,
        private readonly string $buildPath
    ) {
        $this->isDevelopment = $this->detectDevelopmentMode();
    }

    /**
     * Generate script tag for Vite entry point.
     *
     * In development: Returns Vite dev server script
     * In production: Returns manifest-based script
     *
     * @param string $entry Entry point file (e.g., 'resources/js/app.js')
     * @param array $attributes Additional HTML attributes
     * @return string HTML script tag
     */
    public function script(string $entry, array $attributes = []): string
    {
        if ($this->isDevelopment) {
            return $this->devScript($entry, $attributes);
        }

        return $this->productionScript($entry, $attributes);
    }

    /**
     * Generate CSS link tags for Vite entry point.
     *
     * In development: Returns empty (CSS handled by Vite)
     * In production: Returns manifest-based CSS links
     *
     * @param string $entry Entry point file
     * @param array $attributes Additional HTML attributes
     * @return string HTML link tags
     */
    public function css(string $entry, array $attributes = []): string
    {
        if ($this->isDevelopment) {
            return ''; // CSS handled by Vite dev server
        }

        return $this->productionCss($entry, $attributes);
    }

    /**
     * Generate both script and CSS tags.
     *
     * @param string $entry Entry point file
     * @param array $scriptAttributes Script tag attributes
     * @param array $cssAttributes CSS link tag attributes
     * @return string Combined HTML tags
     */
    public function assets(
        string $entry,
        array $scriptAttributes = [],
        array $cssAttributes = []
    ): string {
        return $this->css($entry, $cssAttributes) . $this->script($entry, $scriptAttributes);
    }

    /**
     * Check if running in development mode.
     *
     * @return bool
     */
    public function isDevelopment(): bool
    {
        return $this->isDevelopment;
    }

    /**
     * Get manifest instance (lazy loaded).
     *
     * @return Manifest
     * @throws \RuntimeException If manifest not found
     */
    private function getManifest(): Manifest
    {
        if ($this->manifest === null) {
            if (!file_exists($this->manifestPath)) {
                throw new \RuntimeException(
                    "Vite manifest not found: {$this->manifestPath}. " .
                        "Run 'npm run build' to generate the manifest."
                );
            }

            $this->manifest = new Manifest($this->manifestPath);
        }

        return $this->manifest;
    }

    /**
     * Detect if running in development mode.
     *
     * Checks:
     * 1. dev_server_enabled config
     * 2. Manifest file existence (if missing, assume dev)
     * 3. Environment variable
     *
     * @return bool
     */
    private function detectDevelopmentMode(): bool
    {
        // Explicit config override
        if (!$this->devServerEnabled) {
            return false;
        }

        // Check if manifest exists (production has manifest)
        if (!file_exists($this->manifestPath)) {
            return true; // No manifest = development
        }

        // Check environment
        $env = $_ENV['APP_ENV'] ?? 'production';
        return $env === 'local' || $env === 'development';
    }

    /**
     * Generate development script tag (Vite dev server).
     *
     * @param string $entry Entry point
     * @param array $attributes HTML attributes
     * @return string
     */
    private function devScript(string $entry, array $attributes): string
    {
        $url = rtrim($this->devServerUrl, '/') . '/' . ltrim($entry, '/');
        $attrs = $this->buildAttributes(array_merge([
            'type' => 'module',
            'src' => $url,
        ], $attributes));

        return "<script{$attrs}></script>";
    }

    /**
     * Generate production script tag (from manifest).
     *
     * @param string $entry Entry point
     * @param array $attributes HTML attributes
     * @return string
     */
    private function productionScript(string $entry, array $attributes): string
    {
        $manifest = $this->getManifest();
        $asset = $manifest->get($entry);

        if ($asset === null) {
            throw new \RuntimeException("Vite entry point not found in manifest: {$entry}");
        }

        $url = $this->buildPath . '/' . $asset['file'];
        $attrs = $this->buildAttributes(array_merge([
            'type' => 'module',
            'src' => $url,
        ], $attributes));

        return "<script{$attrs}></script>";
    }

    /**
     * Generate production CSS link tags (from manifest).
     *
     * @param string $entry Entry point
     * @param array $attributes HTML attributes
     * @return string
     */
    private function productionCss(string $entry, array $attributes): string
    {
        $manifest = $this->getManifest();
        $asset = $manifest->get($entry);

        if ($asset === null) {
            return '';
        }

        $cssFiles = $asset['css'] ?? [];
        $html = '';

        foreach ($cssFiles as $cssFile) {
            $url = $this->buildPath . '/' . $cssFile;
            $attrs = $this->buildAttributes(array_merge([
                'rel' => 'stylesheet',
                'href' => $url,
            ], $attributes));

            $html .= "<link{$attrs}>" . PHP_EOL;
        }

        return $html;
    }

    /**
     * Build HTML attributes string.
     *
     * @param array $attributes
     * @return string
     */
    private function buildAttributes(array $attributes): string
    {
        $html = [];

        foreach ($attributes as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $html[] = $key;
            } else {
                $html[] = $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        return empty($html) ? '' : ' ' . implode(' ', $html);
    }
}
