<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Middleware;

use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Http\{Request, Response};
use Toporia\Framework\Http\Middleware\ThrottleRequests;
use Toporia\Framework\RateLimit\Contracts\RateLimiterInterface;

/**
 * Class MiddlewarePipeline
 *
 * Builds and executes middleware chains. Implements the Chain of Responsibility pattern for HTTP middleware.
 * Follows Single Responsibility Principle by focusing only on pipeline building.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MiddlewarePipeline
{
    /**
     * @var array<string, string> Middleware aliases (short name => class name)
     */
    private array $aliases = [];

    /**
     * @param ContainerInterface $container DI container for resolving middleware.
     * @param array<string, string> $aliases Middleware aliases configuration.
     */
    public function __construct(
        private ContainerInterface $container,
        array $aliases = []
    ) {
        $this->aliases = $aliases;
    }

    /**
     * Build middleware pipeline around a core handler.
     *
     * Processes middleware in reverse order so they execute in declaration order.
     * Uses the Onion pattern - each middleware wraps the next layer.
     *
     * @param array<string|callable> $middlewareStack Middleware class names, aliases, or callable factories.
     * @param callable $coreHandler Core handler (controller/action).
     * @return callable Composed pipeline function.
     */
    public function build(array $middlewareStack, callable $coreHandler): callable
    {
        $pipeline = $coreHandler;

        // Build in reverse order for correct execution sequence
        foreach (array_reverse($middlewareStack) as $middlewareIdentifier) {
            $pipeline = $this->wrapMiddleware($middlewareIdentifier, $pipeline);
        }

        return $pipeline;
    }

    /**
     * Wrap a single middleware around the next handler.
     *
     * Supports both string identifiers (class names/aliases) and callable factories.
     * Also supports middleware parameters (e.g., 'throttle:api-per-user' or 'throttle:60,1').
     *
     * @param string|callable $middlewareIdentifier Middleware class name, alias, or callable factory.
     * @param callable $next Next handler in the pipeline.
     * @return callable Wrapped handler.
     */
    private function wrapMiddleware(string|callable $middlewareIdentifier, callable $next): callable
    {
        // If it's already a callable (factory) - but NOT a simple string (which could match a function name)
        // We only want to treat closures and array callables as middleware factories
        if (!is_string($middlewareIdentifier) && is_callable($middlewareIdentifier)) {
            $middlewareClass = $middlewareIdentifier;
            $parameters = null;
        } else {
            // Parse middleware identifier for parameters (e.g., 'throttle:api-per-user' or 'throttle:60,1')
            [$middlewareName, $parameters] = $this->parseMiddlewareIdentifier($middlewareIdentifier);
            $middlewareClass = $this->resolveMiddleware($middlewareName);
        }

        return function (Request $request, Response $response) use ($middlewareClass, $parameters, $next) {
            $middleware = $this->instantiateMiddleware($middlewareClass, $parameters);
            return $middleware->handle($request, $response, $next);
        };
    }

    /**
     * Parse middleware identifier to extract name and parameters.
     *
     * Examples:
     * - 'throttle:api-per-user' -> ['throttle', 'api-per-user']
     * - 'throttle:60,1' -> ['throttle', ['60', '1']]
     * - 'auth' -> ['auth', null]
     *
     * @param string $identifier Middleware identifier with optional parameters
     * @return array{0: string, 1: string|array|null} [middlewareName, parameters]
     */
    private function parseMiddlewareIdentifier(string $identifier): array
    {
        if (!str_contains($identifier, ':')) {
            return [$identifier, null];
        }

        [$name, $params] = explode(':', $identifier, 2);

        // Check if parameters are comma-separated (direct config) or single value (named limiter)
        if (str_contains($params, ',')) {
            // Direct config: 'throttle:60,1' -> ['60', '1']
            $parameters = explode(',', $params);
            $parameters = array_map('trim', $parameters);
        } else {
            // Named limiter or single parameter: 'throttle:api-per-user'
            $parameters = $params;
        }

        return [$name, $parameters];
    }

    /**
     * Resolve middleware identifier to full class name or callable.
     *
     * @param string $identifier Middleware alias or class name.
     * @return string|callable Full middleware class name or callable factory.
     */
    private function resolveMiddleware(string $identifier): string|callable
    {
        return $this->aliases[$identifier] ?? $identifier;
    }

    /**
     * Instantiate middleware from container with auto-wiring.
     *
     * Supports middleware parameters for flexible configuration.
     * For ThrottleRequests, parameters can be:
     * - Named limiter: 'api-per-user' -> uses RateLimiter::for('api-per-user')
     * - Direct config: ['60', '1'] -> maxAttempts=60, decayMinutes=1
     *
     * @param string|callable $middlewareClass Middleware class name or callable factory.
     * @param string|array|null $parameters Optional middleware parameters.
     * @return MiddlewareInterface Middleware instance.
     * @throws \RuntimeException If middleware doesn't implement MiddlewareInterface.
     */
    private function instantiateMiddleware(string|callable $middlewareClass, string|array|null $parameters = null): MiddlewareInterface
    {
        // If it's a callable (closure/array) - NOT a simple string which could match a function name
        if (!is_string($middlewareClass) && is_callable($middlewareClass)) {
            $middleware = $middlewareClass($this->container);
        } else {
            // Check if middleware needs parameters (e.g., ThrottleRequests)
            if ($parameters !== null && $this->needsParameters($middlewareClass)) {
                $middleware = $this->instantiateWithParameters($middlewareClass, $parameters);
            } else {
                // Standard auto-wiring - container resolves all dependencies
                $middleware = $this->container->get($middlewareClass);
            }
        }

        if (!$middleware instanceof MiddlewareInterface) {
            throw new \RuntimeException(
                sprintf(
                    'Middleware must implement %s, got %s',
                    MiddlewareInterface::class,
                    is_object($middleware) ? get_class($middleware) : gettype($middleware)
                )
            );
        }

        return $middleware;
    }

    /**
     * Check if middleware class supports parameters.
     *
     * @param string $middlewareClass
     * @return bool
     */
    private function needsParameters(string $middlewareClass): bool
    {
        // ThrottleRequests supports parameters
        return $middlewareClass === ThrottleRequests::class
            || str_ends_with($middlewareClass, 'ThrottleRequests');
    }

    /**
     * Instantiate middleware with parameters.
     *
     * @param string $middlewareClass
     * @param string|array $parameters
     * @return MiddlewareInterface
     */
    private function instantiateWithParameters(string $middlewareClass, string|array $parameters): MiddlewareInterface
    {
        // Get base rate limiter from container
        $limiter = $this->container->get(RateLimiterInterface::class);

        // Handle ThrottleRequests parameters
        if (
            $middlewareClass === ThrottleRequests::class
            || str_ends_with($middlewareClass, 'ThrottleRequests')
        ) {
            return $this->instantiateThrottleRequests($limiter, $parameters);
        }

        // Default: try to resolve with parameters as constructor arguments
        // This allows other middleware to accept parameters if needed
        return $this->container->get($middlewareClass);
    }

    /**
     * Instantiate ThrottleRequests with parameters.
     *
     * @param \Toporia\Framework\RateLimit\Contracts\RateLimiterInterface $limiter
     * @param string|array $parameters
     * @return \Toporia\Framework\Http\Middleware\ThrottleRequests
     */
    private function instantiateThrottleRequests(
        RateLimiterInterface $limiter,
        string|array $parameters
    ): ThrottleRequests {
        // If parameters is a string, it's a named limiter
        if (is_string($parameters)) {
            return new ThrottleRequests(
                $limiter,
                null, // maxAttempts
                null, // decayMinutes
                $parameters, // namedLimiter
                null  // prefix
            );
        }

        // If parameters is an array, it's direct config [maxAttempts, decayMinutes]
        if (is_array($parameters) && count($parameters) >= 2) {
            return new ThrottleRequests(
                $limiter,
                (int) $parameters[0], // maxAttempts
                (int) $parameters[1], // decayMinutes
                null, // namedLimiter
                null  // prefix
            );
        }

        // Fallback: use defaults
        return new ThrottleRequests($limiter);
    }

    /**
     * Add or update middleware aliases.
     *
     * @param array<string, string> $aliases Middleware aliases to add/update.
     * @return self
     */
    public function addAliases(array $aliases): self
    {
        $this->aliases = array_merge($this->aliases, $aliases);
        return $this;
    }

    /**
     * Get all registered aliases.
     *
     * @return array<string, string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }
}
