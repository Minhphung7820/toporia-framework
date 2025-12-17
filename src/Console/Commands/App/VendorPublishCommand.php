<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\App;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Foundation\PackageManifest;

/**
 * Class VendorPublishCommand
 *
 * Publish any publishable assets from vendor packages.
 * Supports both ServiceProvider::publishes() and auto-discovered configs.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\App
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class VendorPublishCommand extends Command
{
    protected string $signature = 'vendor:publish {--provider= : The service provider to publish} {--tag= : The tag to publish} {--force : Overwrite existing files} {--all : Publish all publishable assets}';

    protected string $description = 'Publish any publishable assets from vendor packages';

    private const COLOR_RESET = "\033[0m";
    private const COLOR_INFO = "\033[36m";      // Cyan
    private const COLOR_SUCCESS = "\033[32m";   // Green
    private const COLOR_WARNING = "\033[33m";   // Yellow
    private const COLOR_ERROR = "\033[31m";     // Red
    private const COLOR_DIM = "\033[2m";        // Dim
    private const COLOR_BOLD = "\033[1m";       // Bold

    /**
     * Built-in publishables for known packages.
     * Used as fallback when packages don't register via ServiceProvider::publishes().
     *
     * @var array<string, array{source: string, destination: string}>
     */
    private array $builtinPublishables = [
        'dominion-config' => [
            'source' => 'packages/dominion/config/dominion.php',
            'destination' => 'config/dominion.php',
        ],
        'mongodb-config' => [
            'source' => 'packages/mongodb/config/mongodb.php',
            'destination' => 'config/mongodb.php',
        ],
        'socialite-config' => [
            'source' => 'packages/socialite/config/socialite.php',
            'destination' => 'config/socialite.php',
        ],
        'webhook-config' => [
            'source' => 'packages/webhook/config/webhook.php',
            'destination' => 'config/webhook.php',
        ],
        'tenancy-config' => [
            'source' => 'packages/tenancy/config/tenancy.php',
            'destination' => 'config/tenancy.php',
        ],
        'audit-config' => [
            'source' => 'packages/audit/config/audit.php',
            'destination' => 'config/audit.php',
        ],
        'api-config' => [
            'source' => 'packages/api-versioning/config/api.php',
            'destination' => 'config/api.php',
        ],
    ];

    public function handle(): int
    {
        $this->printHeader();

        $provider = $this->option('provider');
        $tag = $this->option('tag');
        $force = (bool) $this->option('force');
        $all = (bool) $this->option('all');

        // Get paths from ServiceProvider
        $providerPaths = ServiceProvider::pathsToPublish($provider, $tag);

        // Get paths from manifest configs
        $manifestPaths = $this->getManifestConfigPaths();

        // Merge all paths
        $allPaths = array_merge($providerPaths, $manifestPaths);

        // If no paths from ServiceProvider, check builtin tags
        if (empty($allPaths) && $tag) {
            $allPaths = $this->getBuiltinPaths($tag);
        }

        // If still no paths and --all, get all builtin
        if (empty($allPaths) && $all) {
            $allPaths = $this->getAllBuiltinPaths();
        }

        // If no provider, no tag, and not all - show available options
        if (!$provider && !$tag && !$all) {
            $this->showAvailableOptions($providerPaths, $manifestPaths);
            return 0;
        }

        if (empty($allPaths)) {
            echo self::COLOR_WARNING;
            echo "  No publishable resources found.\n";
            echo self::COLOR_RESET;

            if ($provider) {
                echo self::COLOR_DIM;
                echo "  Provider: {$provider}\n";
                echo self::COLOR_RESET;
            }

            if ($tag) {
                echo self::COLOR_DIM;
                echo "  Tag: {$tag}\n";
                echo self::COLOR_RESET;
            }

            echo "\n";
            return 0;
        }

        // Publish each path
        $published = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($allPaths as $source => $destination) {
            $result = $this->publishPath($source, $destination, $force);

            match ($result) {
                'published' => $published++,
                'skipped' => $skipped++,
                'failed' => $failed++,
                default => null
            };
        }

        // Print summary
        $this->printSummary($published, $skipped, $failed);

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Get config paths from package manifest.
     *
     * @return array<string, string>
     */
    private function getManifestConfigPaths(): array
    {
        $basePath = $this->getBasePath();

        // Logger not available in standalone commands
        $manifest = new PackageManifest(
            $basePath . '/bootstrap/cache/packages.php',
            $basePath,
            $basePath . '/vendor',
            $basePath . '/packages',
            null
        );

        $configs = $manifest->config();
        $paths = [];

        foreach ($configs as $key => $sourcePath) {
            $destPath = $basePath . '/config/' . $key . '.php';
            $paths[$sourcePath] = $destPath;
        }

        return $paths;
    }

    /**
     * Get builtin paths for a specific tag.
     *
     * @param string $tag
     * @return array<string, string>
     */
    private function getBuiltinPaths(string $tag): array
    {
        if (!isset($this->builtinPublishables[$tag])) {
            return [];
        }

        $basePath = $this->getBasePath();
        $config = $this->builtinPublishables[$tag];

        $sourcePath = $basePath . '/' . $config['source'];
        $destPath = $basePath . '/' . $config['destination'];

        // Also check vendor path
        if (!file_exists($sourcePath)) {
            $vendorSource = str_replace('packages/', 'vendor/toporia/', $config['source']);
            $vendorPath = $basePath . '/' . $vendorSource;
            if (file_exists($vendorPath)) {
                $sourcePath = $vendorPath;
            }
        }

        return [$sourcePath => $destPath];
    }

    /**
     * Get all builtin paths.
     *
     * @return array<string, string>
     */
    private function getAllBuiltinPaths(): array
    {
        $allPaths = [];

        foreach (array_keys($this->builtinPublishables) as $tag) {
            $paths = $this->getBuiltinPaths($tag);
            $allPaths = array_merge($allPaths, $paths);
        }

        return $allPaths;
    }

    /**
     * Publish a single path.
     *
     * @param string $source Source path
     * @param string $destination Destination path
     * @param bool $force Overwrite existing files
     * @return string Result status
     */
    private function publishPath(string $source, string $destination, bool $force): string
    {
        $basePath = $this->getBasePath();

        // Make destination path absolute if relative
        if (!str_starts_with($destination, '/')) {
            $destination = $basePath . '/' . $destination;
        }

        // Check if source exists
        if (!file_exists($source)) {
            $this->printPathStatus($source, $destination, 'not_found');
            return 'failed';
        }

        // Check if destination exists
        if (file_exists($destination) && !$force) {
            $this->printPathStatus($source, $destination, 'exists');
            return 'skipped';
        }

        // Create destination directory if needed
        $destDir = is_dir($source) ? $destination : dirname($destination);

        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true)) {
                $this->printPathStatus($source, $destination, 'mkdir_failed');
                return 'failed';
            }
        }

        // Copy
        $success = is_dir($source)
            ? $this->copyDirectory($source, $destination)
            : copy($source, $destination);

        if ($success) {
            $this->printPathStatus($source, $destination, 'published');
            return 'published';
        }

        $this->printPathStatus($source, $destination, 'copy_failed');
        return 'failed';
    }

    /**
     * Recursively copy a directory.
     *
     * @param string $source Source directory
     * @param string $destination Destination directory
     * @return bool
     */
    private function copyDirectory(string $source, string $destination): bool
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destPath = $destination . '/' . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                if (!copy($item->getPathname(), $destPath)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Show available publish options.
     */
    private function showAvailableOptions(array $providerPaths, array $manifestPaths): void
    {
        $basePath = $this->getBasePath();

        // Show manifest configs
        if (!empty($manifestPaths)) {
            echo self::COLOR_SUCCESS;
            echo "  Discovered Config Files:\n";
            echo self::COLOR_RESET;

            foreach ($manifestPaths as $source => $dest) {
                $shortSource = $this->shortenPath($source, $basePath);
                $shortDest = $this->shortenPath($dest, $basePath);
                echo self::COLOR_DIM;
                echo "    {$shortSource} → {$shortDest}\n";
                echo self::COLOR_RESET;
            }
            echo "\n";
        }

        // Show builtin tags
        echo self::COLOR_SUCCESS;
        echo "  Available Tags:\n";
        echo self::COLOR_RESET;

        foreach ($this->builtinPublishables as $tag => $config) {
            $sourcePath = $basePath . '/' . $config['source'];
            $exists = file_exists($sourcePath);

            echo self::COLOR_DIM;
            echo "    {$tag}";

            if (!$exists) {
                echo " (not installed)";
            }

            echo "\n";
            echo self::COLOR_RESET;
        }

        echo "\n";
        echo self::COLOR_INFO;
        echo "  Usage:\n";
        echo self::COLOR_RESET;
        echo "    php console vendor:publish --tag=dominion-config\n";
        echo "    php console vendor:publish --all\n";
        echo "    php console vendor:publish --all --force\n";
        echo "\n";
    }

    /**
     * Print path publish status.
     */
    private function printPathStatus(string $source, string $destination, string $status): void
    {
        $basePath = $this->getBasePath();
        $shortSource = $this->shortenPath($source, $basePath);
        $shortDest = $this->shortenPath($destination, $basePath);

        match ($status) {
            'published' => $this->printPublished($shortSource, $shortDest),
            'exists' => $this->printExists($shortDest),
            'not_found' => $this->printNotFound($shortSource),
            'mkdir_failed' => $this->printMkdirFailed($shortDest),
            'copy_failed' => $this->printCopyFailed($shortSource, $shortDest),
            default => null
        };
    }

    /**
     * Print published status.
     */
    private function printPublished(string $source, string $destination): void
    {
        echo self::COLOR_SUCCESS;
        echo "  ✓  ";
        echo self::COLOR_RESET;
        echo "Copied: {$source}\n";
        echo self::COLOR_DIM;
        echo "       → {$destination}\n";
        echo self::COLOR_RESET;
    }

    /**
     * Print exists status.
     */
    private function printExists(string $destination): void
    {
        echo self::COLOR_WARNING;
        echo "  !  ";
        echo self::COLOR_RESET;
        echo "Skipped (exists): {$destination}\n";
        echo self::COLOR_DIM;
        echo "       Use --force to overwrite\n";
        echo self::COLOR_RESET;
    }

    /**
     * Print not found status.
     */
    private function printNotFound(string $source): void
    {
        echo self::COLOR_ERROR;
        echo "  ✗  ";
        echo self::COLOR_RESET;
        echo "Source not found: {$source}\n";
    }

    /**
     * Print mkdir failed status.
     */
    private function printMkdirFailed(string $destination): void
    {
        echo self::COLOR_ERROR;
        echo "  ✗  ";
        echo self::COLOR_RESET;
        echo "Failed to create directory: {$destination}\n";
    }

    /**
     * Print copy failed status.
     */
    private function printCopyFailed(string $source, string $destination): void
    {
        echo self::COLOR_ERROR;
        echo "  ✗  ";
        echo self::COLOR_RESET;
        echo "Failed to copy: {$source} → {$destination}\n";
    }

    /**
     * Print header.
     */
    private function printHeader(): void
    {
        echo self::COLOR_INFO;
        echo "\n";
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo "│                    VENDOR PUBLISH                                │\n";
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print summary.
     */
    private function printSummary(int $published, int $skipped, int $failed): void
    {
        echo "\n";
        echo self::COLOR_INFO;
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo self::COLOR_RESET;

        if ($published > 0) {
            echo self::COLOR_SUCCESS;
            echo "  ✓  Published: {$published} file(s)\n";
            echo self::COLOR_RESET;
        }

        if ($skipped > 0) {
            echo self::COLOR_WARNING;
            echo "  !  Skipped: {$skipped} file(s)\n";
            echo self::COLOR_RESET;
        }

        if ($failed > 0) {
            echo self::COLOR_ERROR;
            echo "  ✗  Failed: {$failed} file(s)\n";
            echo self::COLOR_RESET;
        }

        echo self::COLOR_INFO;
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Shorten path for display.
     */
    private function shortenPath(string $path, string $basePath): string
    {
        if (str_starts_with($path, $basePath)) {
            return substr($path, strlen($basePath) + 1);
        }

        return $path;
    }
}
