<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Foundation\PackageManifest;

/**
 * Class PackageDiscoverCommand
 *
 * Rebuild the cached package manifest by scanning all packages
 * for their Toporia configuration (providers, config, migrations).
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands
 * @since       2025-12-16
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class PackageDiscoverCommand extends Command
{
    protected string $signature = 'package:discover';
    protected string $description = 'Rebuild the cached package manifest';

    private const COLOR_RESET = "\033[0m";
    private const COLOR_INFO = "\033[36m";      // Cyan
    private const COLOR_SUCCESS = "\033[32m";   // Green
    private const COLOR_DIM = "\033[2m";        // Dim
    private const COLOR_BOLD = "\033[1m";       // Bold

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);

        $this->printHeader();

        try {
            $basePath = $this->getBasePath();

            // Logger not available in standalone commands
            $manifest = new PackageManifest(
                $basePath . '/bootstrap/cache/packages.php',
                $basePath,
                $basePath . '/vendor',
                $basePath . '/packages',
                null
            );

            // Force rebuild
            $manifest->build();

            // Get discovered items
            $providers = $manifest->providers();
            $configs = $manifest->config();
            $migrations = $manifest->migrations();

            // Print discovered providers
            if (!empty($providers)) {
                echo self::COLOR_SUCCESS;
                echo "  Discovered Providers:\n";
                echo self::COLOR_RESET;

                foreach ($providers as $provider) {
                    echo self::COLOR_DIM;
                    echo "    - {$provider}\n";
                    echo self::COLOR_RESET;
                }
                echo "\n";
            }

            // Print discovered configs
            if (!empty($configs)) {
                echo self::COLOR_SUCCESS;
                echo "  Discovered Configs:\n";
                echo self::COLOR_RESET;

                foreach ($configs as $key => $path) {
                    $shortPath = $this->shortenPath($path, $basePath);
                    echo self::COLOR_DIM;
                    echo "    - {$key} => {$shortPath}\n";
                    echo self::COLOR_RESET;
                }
                echo "\n";
            }

            // Print discovered migrations
            if (!empty($migrations)) {
                echo self::COLOR_SUCCESS;
                echo "  Discovered Migration Paths:\n";
                echo self::COLOR_RESET;

                foreach ($migrations as $path) {
                    $shortPath = $this->shortenPath($path, $basePath);
                    echo self::COLOR_DIM;
                    echo "    - {$shortPath}\n";
                    echo self::COLOR_RESET;
                }
                echo "\n";
            }

            // Print summary
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->printSummary(count($providers), count($configs), count($migrations), $duration);

            return 0;
        } catch (\Throwable $e) {
            $this->printError($e->getMessage());
            return 1;
        }
    }

    /**
     * Print header.
     */
    private function printHeader(): void
    {
        echo self::COLOR_INFO;
        echo "\n";
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo "│                    PACKAGE DISCOVERY                             │\n";
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print summary.
     */
    private function printSummary(int $providers, int $configs, int $migrations, float $duration): void
    {
        echo self::COLOR_INFO;
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo self::COLOR_RESET;

        echo self::COLOR_SUCCESS;
        echo "  ✓  Package manifest generated successfully!\n";
        echo self::COLOR_RESET;

        echo self::COLOR_DIM;
        echo "     Providers: {$providers}\n";
        echo "     Configs: {$configs}\n";
        echo "     Migration Paths: {$migrations}\n";
        echo "     Duration: {$duration}ms\n";
        echo self::COLOR_RESET;

        echo self::COLOR_INFO;
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    /**
     * Print error.
     */
    private function printError(string $message): void
    {
        echo "\n";
        echo "\033[31m";  // Red
        echo "  ✗  Error: {$message}\n";
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
