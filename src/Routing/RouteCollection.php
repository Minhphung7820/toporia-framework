<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing;

use Toporia\Framework\Routing\Contracts\{RouteCollectionInterface, RouteInterface};

/**
 * Class RouteCollection
 *
 * Collection of routes with efficient lookup. Provides optimized route matching
 * by indexing routes by HTTP method and separating exact routes from pattern routes.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Routing
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RouteCollection implements RouteCollectionInterface
{
    /**
     * @var array<RouteInterface> All routes (for backward compatibility).
     */
    private array $routes = [];

    /**
     * @var array<string, RouteInterface> Named routes.
     */
    private array $namedRoutes = [];

    /**
     * @var array<string, array<RouteInterface>> Routes indexed by HTTP method.
     * Format: ['GET' => [route1, route2], 'POST' => [route3]]
     */
    private array $routesByMethod = [];

    /**
     * @var array<string, array<string, RouteInterface>> Exact routes indexed by method and URI.
     * Format: ['GET' => ['/api/users' => $route], 'POST' => ['/api/users' => $route]]
     * O(1) lookup for exact matches.
     */
    private array $exactRoutes = [];

    /**
     * @var bool Whether indexes need to be rebuilt.
     */
    private bool $needsIndexing = false;

    /**
     * {@inheritdoc}
     */
    public function add(RouteInterface $route): void
    {
        $this->routes[] = $route;

        // Index by name if available
        if ($route->getName() !== null) {
            $this->namedRoutes[$route->getName()] = $route;
        }

        // Mark that indexes need rebuilding
        $this->needsIndexing = true;
    }

    /**
     * {@inheritdoc}
     */
    public function match(string $method, string $uri): ?array
    {
        // Rebuild indexes if needed (lazy indexing)
        if ($this->needsIndexing) {
            $this->buildIndexes();
        }

        // Fast rejection: No routes for this method
        if (!isset($this->routesByMethod[$method])) {
            return null;
        }

        // Fast path: Try exact match first (O(1) lookup)
        if (isset($this->exactRoutes[$method][$uri])) {
            $route = $this->exactRoutes[$method][$uri];
            return [
                'route' => $route,
                'parameters' => []
            ];
        }

        // Pattern matching: Only check routes for this method (O(M) where M = routes for method)
        $routesForMethod = $this->routesByMethod[$method];
        foreach ($routesForMethod as $route) {
            // Skip exact routes (already checked above)
            $routeUri = $route->getUri();
            if (str_contains($routeUri, '{')) {
                // This is a pattern route
                $parameters = $route->matches($method, $uri);

                if ($parameters !== null) {
                    return [
                        'route' => $route,
                        'parameters' => $parameters
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Check if a path exists for any HTTP method.
     *
     * This is used to determine if we should return 405 (Method Not Allowed)
     * instead of 404 (Not Found) when the path exists but method doesn't match.
     *
     * @param string $uri URI path to check
     * @return bool True if path exists for any method
     */
    public function pathExists(string $uri): bool
    {
        // Rebuild indexes if needed
        if ($this->needsIndexing) {
            $this->buildIndexes();
        }

        // Check all methods for this path
        $allMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

        foreach ($allMethods as $method) {
            // Fast path: Check exact match
            if (isset($this->exactRoutes[$method][$uri])) {
                return true;
            }

            // Pattern matching: Check routes for this method
            if (isset($this->routesByMethod[$method])) {
                foreach ($this->routesByMethod[$method] as $route) {
                    $routeUri = $route->getUri();
                    if (str_contains($routeUri, '{')) {
                        // This is a pattern route
                        $parameters = $route->matches($method, $uri);
                        if ($parameters !== null) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get allowed HTTP methods for a given path.
     *
     * Used for 405 Method Not Allowed responses to include Allow header.
     *
     * @param string $uri URI path to check
     * @return array<string> Array of allowed HTTP methods
     */
    public function getAllowedMethods(string $uri): array
    {
        // Rebuild indexes if needed
        if ($this->needsIndexing) {
            $this->buildIndexes();
        }

        $allowedMethods = [];
        $allMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

        foreach ($allMethods as $method) {
            // Fast path: Check exact match
            if (isset($this->exactRoutes[$method][$uri])) {
                $allowedMethods[] = $method;
                continue;
            }

            // Pattern matching: Check routes for this method
            if (isset($this->routesByMethod[$method])) {
                foreach ($this->routesByMethod[$method] as $route) {
                    $routeUri = $route->getUri();
                    if (str_contains($routeUri, '{')) {
                        // This is a pattern route
                        $parameters = $route->matches($method, $uri);
                        if ($parameters !== null) {
                            $allowedMethods[] = $method;
                            break; // Found match for this method, move to next
                        }
                    }
                }
            }
        }

        return $allowedMethods;
    }

    /**
     * Build indexes for fast route lookup.
     *
     * This method is called lazily when first match() is called after routes are added.
     * This ensures we don't waste time indexing if routes are never matched.
     */
    private function buildIndexes(): void
    {
        // Reset indexes
        $this->routesByMethod = [];
        $this->exactRoutes = [];

        foreach ($this->routes as $route) {
            $methods = $route->getMethods();
            $methods = is_array($methods) ? $methods : [$methods];
            $uri = $route->getUri();

            // Check if this is an exact route (no parameters)
            $isExactRoute = !str_contains($uri, '{');

            foreach ($methods as $method) {
                // Index by method
                if (!isset($this->routesByMethod[$method])) {
                    $this->routesByMethod[$method] = [];
                }
                $this->routesByMethod[$method][] = $route;

                // Index exact routes separately for O(1) lookup
                if ($isExactRoute) {
                    if (!isset($this->exactRoutes[$method])) {
                        $this->exactRoutes[$method] = [];
                    }
                    $this->exactRoutes[$method][$uri] = $route;
                }
            }
        }

        $this->needsIndexing = false;
    }

    /**
     * {@inheritdoc}
     */
    public function getByName(string $name): ?RouteInterface
    {
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function all(): array
    {
        return $this->routes;
    }
}
