<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Middleware;

use Toporia\Framework\Realtime\Contracts\ConnectionInterface;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Channel Middleware Pipeline
 *
 * Executes middleware chain for channel authorization.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ChannelMiddlewarePipeline
{
    /**
     * Built-in middleware aliases.
     *
     * Framework core does NOT provide built-in middleware.
     * All middleware are application-specific and should be registered in config.
     *
     * @var array<string, string>
     */
    private const BUILTIN_MIDDLEWARE = [];

    /**
     * Custom middleware registry (programmatically registered).
     *
     * @var array<string, string>
     */
    private static array $customMiddleware = [];

    /**
     * Config-based middleware aliases (loaded from config).
     *
     * @var array<string, string>|null
     */
    private ?array $configMiddleware = null;

    public function __construct(
        private readonly ?ContainerInterface $container = null
    ) {
        $this->loadConfigMiddleware();
    }

    /**
     * Register custom middleware alias.
     *
     * @param string $alias Middleware alias (e.g., 'premium')
     * @param string $class Middleware class name
     * @return void
     */
    public static function register(string $alias, string $class): void
    {
        self::$customMiddleware[$alias] = $class;
    }

    /**
     * Execute middleware pipeline.
     *
     * @param array<string> $middlewareList Middleware list (e.g., ['auth', 'role:admin'])
     * @param ConnectionInterface $connection Connection
     * @param string $channelName Channel name
     * @param callable $finalCallback Final callback if all middleware pass
     * @return bool True if authorized, false otherwise
     */
    public function execute(array $middlewareList, ConnectionInterface $connection, string $channelName, callable $finalCallback): bool
    {
        // Build middleware chain
        $pipeline = $this->buildPipeline($middlewareList, $finalCallback);

        // Execute pipeline
        return $pipeline($connection, $channelName);
    }

    /**
     * Build middleware pipeline (reverse order).
     *
     * @param array<string> $middlewareList Middleware list
     * @param callable $finalCallback Final callback
     * @return callable
     */
    private function buildPipeline(array $middlewareList, callable $finalCallback): callable
    {
        // Build pipeline in reverse order
        $pipeline = $finalCallback;

        foreach (array_reverse($middlewareList) as $middlewareDefinition) {
            $pipeline = function (ConnectionInterface $connection, string $channelName) use ($middlewareDefinition, $pipeline) {
                $middleware = $this->resolveMiddleware($middlewareDefinition);
                return $middleware->handle($connection, $channelName, $pipeline);
            };
        }

        return $pipeline;
    }

    /**
     * Resolve middleware instance from definition.
     *
     * Supports:
     * - Simple alias: 'auth'
     * - Alias with params: 'role:admin,moderator'
     * - Full class name: 'App\Middleware\CustomMiddleware'
     *
     * @param string $definition Middleware definition
     * @return ChannelMiddlewareInterface
     */
    private function resolveMiddleware(string $definition): ChannelMiddlewareInterface
    {
        // Parse middleware definition
        [$alias, $params] = $this->parseDefinition($definition);

        // Resolve middleware class
        $class = $this->resolveClass($alias);

        // Instantiate middleware
        return $this->instantiate($class, $params);
    }

    /**
     * Parse middleware definition into alias and parameters.
     *
     * Examples:
     * - 'auth' -> ['auth', []]
     * - 'role:admin' -> ['role', ['admin']]
     * - 'role:admin,moderator' -> ['role', ['admin', 'moderator']]
     *
     * @param string $definition Middleware definition
     * @return array{0: string, 1: array<string>}
     */
    private function parseDefinition(string $definition): array
    {
        if (!str_contains($definition, ':')) {
            return [$definition, []];
        }

        [$alias, $paramsString] = explode(':', $definition, 2);
        $params = array_map('trim', explode(',', $paramsString));

        return [$alias, $params];
    }

    /**
     * Load middleware from config.
     *
     * @return void
     */
    private function loadConfigMiddleware(): void
    {
        if ($this->container === null || !$this->container->has('config')) {
            $this->configMiddleware = [];
            return;
        }

        try {
            $config = $this->container->get('config');
            $this->configMiddleware = $config->get('realtime.channel_middleware', []);
        } catch (\Throwable $e) {
            $this->configMiddleware = [];
        }
    }

    /**
     * Resolve middleware class from alias.
     *
     * Priority:
     * 1. Config-based middleware (config/realtime.php)
     * 2. Programmatically registered (ChannelMiddlewarePipeline::register())
     * 3. Built-in middleware (auth, role, ratelimit)
     * 4. Assume full class name
     *
     * @param string $alias Middleware alias
     * @return string Middleware class name
     */
    private function resolveClass(string $alias): string
    {
        // 1. Check config-based middleware (highest priority)
        if ($this->configMiddleware !== null && isset($this->configMiddleware[$alias])) {
            return $this->configMiddleware[$alias];
        }

        // 2. Check programmatically registered middleware
        if (isset(self::$customMiddleware[$alias])) {
            return self::$customMiddleware[$alias];
        }

        // 3. Check built-in middleware
        if (isset(self::BUILTIN_MIDDLEWARE[$alias])) {
            return self::BUILTIN_MIDDLEWARE[$alias];
        }

        // 4. Assume it's a full class name
        return $alias;
    }

    /**
     * Instantiate middleware class.
     *
     * @param string $class Middleware class name
     * @param array<string> $params Constructor parameters
     * @return ChannelMiddlewareInterface
     */
    private function instantiate(string $class, array $params): ChannelMiddlewareInterface
    {
        // Try container resolution first
        if ($this->container !== null && empty($params)) {
            try {
                $instance = $this->container->get($class);
                if ($instance instanceof ChannelMiddlewareInterface) {
                    return $instance;
                }
            } catch (\Throwable $e) {
                // Fall through to manual instantiation
            }
        }

        // Manual instantiation with params
        return new $class(...$params);
    }

    /**
     * Get all registered middleware (built-in + programmatic + config).
     *
     * @return array<string, string>
     */
    public function getRegisteredMiddleware(): array
    {
        return array_merge(
            self::BUILTIN_MIDDLEWARE,
            self::$customMiddleware,
            $this->configMiddleware ?? []
        );
    }

    /**
     * Get built-in middleware.
     *
     * @return array<string, string>
     */
    public static function getBuiltinMiddleware(): array
    {
        return self::BUILTIN_MIDDLEWARE;
    }

    /**
     * Clear custom middleware (useful for testing).
     *
     * @return void
     */
    public static function clearCustomMiddleware(): void
    {
        self::$customMiddleware = [];
    }
}
