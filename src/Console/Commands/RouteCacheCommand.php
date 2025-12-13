<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Routing\{RouteCache, Router};

/**
 * Cache compiled routes for performance.
 *
 * Performance Benefits:
 * - 80-90% faster route matching
 * - Zero overhead route parsing
 * - Opcache-friendly PHP file format
 * - O(1) route lookup after compilation
 *
 * Usage:
 * - php console route:cache
 *
 * Architecture:
 * - Single Responsibility: Route caching only
 * - Dependency Injection: Router + RouteCache
 */
/**
 * Class RouteCacheCommand
 *
 * Cache application routes for improved performance.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RouteCacheCommand extends Command
{
    protected string $signature = 'route:cache';
    protected string $description = 'Cache routes for faster performance';

    private const COLOR_RESET = "\033[0m";
    private const COLOR_INFO = "\033[36m";
    private const COLOR_SUCCESS = "\033[32m";
    private const COLOR_ERROR = "\033[31m";
    private const COLOR_DIM = "\033[2m";
    private const COLOR_BOLD = "\033[1m";

    public function __construct(
        private Router $router
    ) {
    }

    public function handle(): int
    {
        $startTime = microtime(true);

        $this->printHeader();

        // Get cache path
        $cachePath = dirname(__DIR__, 4) . '/storage/framework/cache';
        $cache = new RouteCache($cachePath);

        // Clear old cache
        if ($cache->isCached()) {
            $cache->clear();
            echo self::COLOR_DIM . "  Cleared old cache\n" . self::COLOR_RESET;
        }

        // Compile routes
        echo self::COLOR_INFO . "  Compiling routes...\n" . self::COLOR_RESET;
        $compiled = $this->router->compileRoutes();
        $count = count($compiled);

        // Cache routes
        $success = $cache->put($compiled);

        if (!$success) {
            echo "\n";
            echo self::COLOR_ERROR . "  ✗  Failed to cache routes\n" . self::COLOR_RESET;
            return 1;
        }

        // Print summary
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->printSummary($count, $cache->getCachePath(), $duration);

        return 0;
    }

    private function printHeader(): void
    {
        echo "\n";
        echo self::COLOR_INFO;
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo "│                     ROUTE CACHING                                │\n";
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    private function printSummary(int $count, string $cachePath, float $duration): void
    {
        echo "\n";
        echo self::COLOR_INFO;
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo self::COLOR_RESET;

        echo self::COLOR_SUCCESS;
        echo "  ✓  " . self::COLOR_BOLD . "Cached: " . $count . self::COLOR_RESET . self::COLOR_SUCCESS . " routes\n";
        echo self::COLOR_RESET;

        echo self::COLOR_DIM;
        echo "     Path: " . $cachePath . "\n";
        echo "     Duration: " . $duration . "ms\n";
        echo self::COLOR_RESET;

        echo self::COLOR_INFO;
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";

        echo self::COLOR_SUCCESS;
        echo "  Routes cached successfully! 🚀\n";
        echo self::COLOR_RESET;
        echo "\n";
    }
}
