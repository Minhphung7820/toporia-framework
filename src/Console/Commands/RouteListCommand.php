<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Routing\Router;

/**
 * Class RouteListCommand
 *
 * List all registered routes with details in table format.
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
final class RouteListCommand extends Command
{
    protected string $signature = 'route:list';
    protected string $description = 'List all registered routes';

    private const COLOR_RESET = "\033[0m";
    private const COLOR_INFO = "\033[36m";
    private const COLOR_SUCCESS = "\033[32m";
    private const COLOR_WARNING = "\033[33m";
    private const COLOR_DIM = "\033[2m";
    private const COLOR_BOLD = "\033[1m";

    // HTTP method colors
    private const METHOD_COLORS = [
        'GET' => "\033[32m",    // Green
        'POST' => "\033[33m",   // Yellow
        'PUT' => "\033[34m",    // Blue
        'PATCH' => "\033[35m",  // Magenta
        'DELETE' => "\033[31m", // Red
        'ANY' => "\033[36m",    // Cyan
    ];

    public function __construct(
        private Router $router
    ) {
    }

    public function handle(): int
    {
        $this->printHeader();

        // Get all routes
        $routes = $this->router->getRoutes()->all();

        if (empty($routes)) {
            echo self::COLOR_WARNING;
            echo "  ℹ  No routes registered\n";
            echo self::COLOR_RESET;
            echo "\n";
            return 0;
        }

        // Apply filters
        $routes = $this->applyFilters($routes);

        if (empty($routes)) {
            echo self::COLOR_WARNING;
            echo "  ℹ  No routes match the filter\n";
            echo self::COLOR_RESET;
            echo "\n";
            return 0;
        }

        // Print route table
        $this->printRouteTable($routes);

        // Print summary
        $this->printSummary(count($routes));

        return 0;
    }

    private function printHeader(): void
    {
        echo "\n";
        echo self::COLOR_INFO;
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo "│                    REGISTERED ROUTES                             │\n";
        echo "└─────────────────────────────────────────────────────────────────┘\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    private function applyFilters(array $routes): array
    {
        // Filter by path
        if ($path = $this->option('path')) {
            $routes = array_filter($routes, fn($route) => str_contains($route->getUri(), $path));
        }

        // Filter by name
        if ($name = $this->option('name')) {
            $routes = array_filter($routes, function($route) use ($name) {
                $routeName = $route->getName();
                return $routeName && str_contains($routeName, $name);
            });
        }

        // Filter by method
        if ($method = $this->option('method')) {
            $method = strtoupper($method);
            $routes = array_filter($routes, function($route) use ($method) {
                $methods = $route->getMethods();
                $methodsArray = is_array($methods) ? $methods : [$methods];
                return in_array($method, $methodsArray);
            });
        }

        return $routes;
    }

    private function printRouteTable(array $routes): void
    {
        // Column widths
        $methodWidth = 8;
        $pathWidth = 40;
        $nameWidth = 25;
        $middlewareWidth = 30;

        // Print table header
        echo self::COLOR_INFO;
        echo "  ┌─" . str_repeat("─", $methodWidth);
        echo "┬─" . str_repeat("─", $pathWidth);
        echo "┬─" . str_repeat("─", $nameWidth);
        echo "┬─" . str_repeat("─", $middlewareWidth) . "─┐\n";
        echo self::COLOR_RESET;

        echo self::COLOR_BOLD;
        echo "  │ " . str_pad("Method", $methodWidth);
        echo "│ " . str_pad("Path", $pathWidth);
        echo "│ " . str_pad("Name", $nameWidth);
        echo "│ " . str_pad("Middleware", $middlewareWidth) . " │\n";
        echo self::COLOR_RESET;

        echo self::COLOR_INFO;
        echo "  ├─" . str_repeat("─", $methodWidth);
        echo "┼─" . str_repeat("─", $pathWidth);
        echo "┼─" . str_repeat("─", $nameWidth);
        echo "┼─" . str_repeat("─", $middlewareWidth) . "─┤\n";
        echo self::COLOR_RESET;

        // Print routes
        foreach ($routes as $route) {
            $methods = $route->getMethods();
            $method = is_array($methods) ? implode('|', $methods) : $methods;
            $path = $route->getUri();
            $name = $route->getName() ?? '-';
            $middleware = $this->formatMiddleware($route->getMiddleware());

            // Method with color (use first method for color)
            $firstMethod = is_array($methods) ? $methods[0] : $methods;
            $methodColor = self::METHOD_COLORS[$firstMethod] ?? self::COLOR_RESET;
            $methodStr = $methodColor . str_pad($method, $methodWidth) . self::COLOR_RESET;

            echo "  │ " . $methodStr;
            echo "│ " . self::COLOR_DIM . str_pad($this->truncate($path, $pathWidth), $pathWidth) . self::COLOR_RESET;
            echo "│ " . str_pad($this->truncate($name, $nameWidth), $nameWidth);
            echo "│ " . self::COLOR_DIM . str_pad($this->truncate($middleware, $middlewareWidth), $middlewareWidth) . self::COLOR_RESET . " │\n";
        }

        // Print table footer
        echo self::COLOR_INFO;
        echo "  └─" . str_repeat("─", $methodWidth);
        echo "┴─" . str_repeat("─", $pathWidth);
        echo "┴─" . str_repeat("─", $nameWidth);
        echo "┴─" . str_repeat("─", $middlewareWidth) . "─┘\n";
        echo self::COLOR_RESET;
    }

    private function printSummary(int $count): void
    {
        echo "\n";
        echo self::COLOR_SUCCESS;
        echo "  ✓  " . self::COLOR_BOLD . $count . self::COLOR_RESET . self::COLOR_SUCCESS . " routes registered\n";
        echo self::COLOR_RESET;
        echo "\n";

        // Show filter options
        echo self::COLOR_DIM;
        echo "  Filters: --path=/api --name=products --method=GET\n";
        echo self::COLOR_RESET;
        echo "\n";
    }

    private function formatMiddleware(array $middleware): string
    {
        if (empty($middleware)) {
            return '-';
        }

        // Extract class names from middleware
        $names = array_map(function($m) {
            if (is_string($m)) {
                $parts = explode('\\', $m);
                return end($parts);
            }
            return 'Closure';
        }, $middleware);

        return implode(', ', $names);
    }

    private function truncate(string $str, int $maxLength): string
    {
        if (strlen($str) <= $maxLength) {
            return $str;
        }

        return substr($str, 0, $maxLength - 3) . '...';
    }
}
