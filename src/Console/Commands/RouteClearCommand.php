<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Routing\RouteCache;

/**
 * Clear cached routes.
 *
 * Usage:
 * - php console route:clear
 */
/**
 * Class RouteClearCommand
 *
 * Clear cached routes.
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
final class RouteClearCommand extends Command
{
    protected string $signature = 'route:clear';
    protected string $description = 'Clear cached routes';

    private const COLOR_RESET = "\033[0m";
    private const COLOR_INFO = "\033[36m";
    private const COLOR_SUCCESS = "\033[32m";
    private const COLOR_WARNING = "\033[33m";
    private const COLOR_ERROR = "\033[31m";
    private const COLOR_DIM = "\033[2m";

    public function handle(): int
    {
        $this->printHeader();

        // Get cache path
        $cachePath = dirname(__DIR__, 4) . '/storage/framework/cache';
        $cache = new RouteCache($cachePath);

        // Check if cached
        if (!$cache->isCached()) {
            echo self::COLOR_WARNING;
            echo "  ℹ  No route cache found\n";
            echo self::COLOR_RESET;
            echo "\n";
            return 0;
        }

        // Clear cache
        $success = $cache->clear();

        if (!$success) {
            echo self::COLOR_ERROR . "  ✗  Failed to clear cache\n" . self::COLOR_RESET;
            return 1;
        }

        // Print success
        echo self::COLOR_SUCCESS;
        echo "  ✓  Route cache cleared\n";
        echo self::COLOR_RESET;

        echo self::COLOR_DIM;
        echo "     Path: " . $cache->getCachePath() . "\n";
        echo self::COLOR_RESET;
        echo "\n";

        return 0;
    }

    private function printHeader(): void
    {
        echo "\n";
        echo self::COLOR_INFO;
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo "│                   CLEAR ROUTE CACHE                              │\n";
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }
}
