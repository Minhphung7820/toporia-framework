<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing\Contracts;


/**
 * Interface RouterInterface
 *
 * Contract defining the interface for RouterInterface implementations in
 * the HTTP routing and URL generation layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Routing\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface RouterInterface
{
    /**
     * Register a GET route.
     *
     * @param string $path URI pattern.
     * @param mixed $handler Route handler.
     * @param array<string> $middleware Middleware classes.
     * @return RouteInterface
     */
    public function get(string $path, mixed $handler, array $middleware = []): RouteInterface;

    /**
     * Register a POST route.
     *
     * @param string $path URI pattern.
     * @param mixed $handler Route handler.
     * @param array<string> $middleware Middleware classes.
     * @return RouteInterface
     */
    public function post(string $path, mixed $handler, array $middleware = []): RouteInterface;

    /**
     * Register a PUT route.
     *
     * @param string $path URI pattern.
     * @param mixed $handler Route handler.
     * @param array<string> $middleware Middleware classes.
     * @return RouteInterface
     */
    public function put(string $path, mixed $handler, array $middleware = []): RouteInterface;

    /**
     * Register a PATCH route.
     *
     * @param string $path URI pattern.
     * @param mixed $handler Route handler.
     * @param array<string> $middleware Middleware classes.
     * @return RouteInterface
     */
    public function patch(string $path, mixed $handler, array $middleware = []): RouteInterface;

    /**
     * Register a DELETE route.
     *
     * @param string $path URI pattern.
     * @param mixed $handler Route handler.
     * @param array<string> $middleware Middleware classes.
     * @return RouteInterface
     */
    public function delete(string $path, mixed $handler, array $middleware = []): RouteInterface;

    /**
     * Register a route for any HTTP method.
     *
     * @param string $path URI pattern.
     * @param mixed $handler Route handler.
     * @param array<string> $middleware Middleware classes.
     * @return RouteInterface
     */
    public function any(string $path, mixed $handler, array $middleware = []): RouteInterface;

    /**
     * Register a fallback handler for unmatched routes (404 handler).
     *
     * This handler will be called automatically when no route matches the request.
     * It acts as a global 404 handler, not a catch-all route.
     *
     * @param mixed $handler Fallback handler (controller, closure, etc.)
     * @return self
     */
    public function fallback(mixed $handler): self;

    /**
     * Dispatch the current request and execute the matched route.
     *
     * @return void
     */
    public function dispatch(): void;

    /**
     * Create a route group with shared attributes
     *
     * @param array<string, mixed> $attributes Group attributes (prefix, middleware, namespace, name)
     * @param callable $callback Callback to define routes
     * @return void
     */
    public function group(array $attributes, callable $callback): void;

    /**
     * Get current prefix (for group nesting)
     *
     * @return string
     */
    public function getCurrentPrefix(): string;

    /**
     * Set current prefix (for group nesting)
     *
     * @param string $prefix
     * @return void
     */
    public function setCurrentPrefix(string $prefix): void;

    /**
     * Get current middleware stack (for group nesting)
     *
     * @return array<string>
     */
    public function getCurrentMiddleware(): array;

    /**
     * Set current middleware stack (for group nesting)
     *
     * @param array<string> $middleware
     * @return void
     */
    public function setCurrentMiddleware(array $middleware): void;

    /**
     * Get current namespace (for group nesting)
     *
     * @return string|null
     */
    public function getCurrentNamespace(): ?string;

    /**
     * Set current namespace (for group nesting)
     *
     * @param string|null $namespace
     * @return void
     */
    public function setCurrentNamespace(?string $namespace): void;

    /**
     * Get current name prefix (for group nesting)
     *
     * @return string
     */
    public function getCurrentNamePrefix(): string;

    /**
     * Set current name prefix (for group nesting)
     *
     * @param string $prefix
     * @return void
     */
    public function setCurrentNamePrefix(string $prefix): void;
}
