<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime;

use Toporia\Framework\Realtime\Contracts\ConnectionInterface;

/**
 * Channel Route Builder
 *
 * Fluent API for defining realtime channel authorization and middleware.
 *
 * Usage:
 *   // Basic usage
 *   ChannelRoute::channel('user.{userId}', function($user, $userId) {
 *       return $user['id'] === (int) $userId;
 *   })->middleware(['auth']);
 *
 *   // With guards option (Toporia-style)
 *   ChannelRoute::channel('orders.{orderId}', function($user, $orderId) {
 *       return (int) $user['id'] === (int) Order::find($orderId)->user_id;
 *   }, ['guards' => ['web', 'admin']]);
 *
 *   // Guard with callback receiving guard name
 *   ChannelRoute::channel('admin.dashboard', function($user, $guard = null) {
 *       return $guard === 'admin';
 *   }, ['guards' => ['admin']]);
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
final class ChannelRoute
{
    /**
     * Channel definitions registry.
     *
     * @var array<string, array{pattern: string, callback: callable, middleware: array, guards: array}>
     */
    private static array $channels = [];

    /**
     * Current channel pattern (for fluent API).
     *
     * @var string|null
     */
    private ?string $currentPattern = null;

    /**
     * Define a channel route.
     *
     * @param string $pattern Channel pattern (supports wildcards like 'user.{userId}' or 'private-*')
     * @param callable $callback Authorization callback($user, ...$params, $guard = null): bool|array
     * @param array $options Options including 'guards' => ['web', 'api', 'admin']
     * @return self
     */
    public static function channel(string $pattern, callable $callback, array $options = []): self
    {
        $instance = new self();
        $instance->currentPattern = $pattern;

        // Extract guards from options (default: all guards allowed)
        $guards = $options['guards'] ?? [];

        // Register channel
        self::$channels[$pattern] = [
            'pattern' => $pattern,
            'callback' => $callback,
            'middleware' => [],
            'guards' => $guards, // Empty array = all guards allowed
        ];

        return $instance;
    }

    /**
     * Attach middleware to the current channel.
     *
     * @param array<string> $middleware Middleware names (e.g., ['auth', 'role:admin'])
     * @return self
     */
    public function middleware(array $middleware): self
    {
        if ($this->currentPattern !== null && isset(self::$channels[$this->currentPattern])) {
            self::$channels[$this->currentPattern]['middleware'] = $middleware;
        }

        return $this;
    }

    /**
     * Set allowed guards for the current channel (fluent API).
     *
     * Usage:
     *   ChannelRoute::channel('orders.{id}', $callback)->guards(['web', 'admin']);
     *
     * @param array<string> $guards Guard names (e.g., ['web', 'api', 'admin'])
     * @return self
     */
    public function guards(array $guards): self
    {
        if ($this->currentPattern !== null && isset(self::$channels[$this->currentPattern])) {
            self::$channels[$this->currentPattern]['guards'] = $guards;
        }

        return $this;
    }

    /**
     * Check if a guard is allowed for a channel definition.
     *
     * @param array $channelDef Channel definition
     * @param string|null $guardName Guard name to check
     * @return bool True if guard is allowed (empty guards = all allowed)
     */
    public static function isGuardAllowed(array $channelDef, ?string $guardName): bool
    {
        $allowedGuards = $channelDef['guards'] ?? [];

        // Empty guards array = all guards allowed
        if (empty($allowedGuards)) {
            return true;
        }

        // Check if guard is in allowed list
        return $guardName !== null && in_array($guardName, $allowedGuards, true);
    }

    /**
     * Get all registered channel definitions.
     *
     * @return array<string, array{pattern: string, callback: callable, middleware: array}>
     */
    public static function getChannels(): array
    {
        return self::$channels;
    }

    /**
     * Find channel definition by name.
     *
     * Supports:
     * - Exact match: 'user.123' matches 'user.123'
     * - Wildcards: 'user.123' matches 'user.*'
     * - Parameters: 'user.123' matches 'user.{userId}'
     *
     * @param string $channelName Channel name to match
     * @return array{pattern: string, callback: callable, middleware: array, params: array}|null
     */
    public static function match(string $channelName): ?array
    {
        foreach (self::$channels as $definition) {
            $pattern = $definition['pattern'];

            // Exact match
            if ($pattern === $channelName) {
                return array_merge($definition, ['params' => []]);
            }

            // Wildcard match (e.g., 'private-*' matches 'private-chat')
            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
                if (preg_match($regex, $channelName)) {
                    return array_merge($definition, ['params' => []]);
                }
            }

            // Parameter match (e.g., 'user.{userId}' matches 'user.123')
            if (str_contains($pattern, '{')) {
                $params = self::extractParameters($pattern, $channelName);
                if ($params !== null) {
                    return array_merge($definition, ['params' => $params]);
                }
            }
        }

        return null;
    }

    /**
     * Extract parameters from channel name using pattern.
     *
     * Example:
     *   Pattern: 'user.{userId}.notifications'
     *   Channel: 'user.123.notifications'
     *   Result: ['userId' => '123']
     *
     * @param string $pattern Pattern with parameters like 'user.{userId}'
     * @param string $channelName Actual channel name like 'user.123'
     * @return array<string, string>|null Extracted parameters or null if no match
     */
    private static function extractParameters(string $pattern, string $channelName): ?array
    {
        // Convert pattern to regex
        // 'user.{userId}' -> '/^user\.([^.]+)$/'
        // 'team.{teamId}.channel.{channelId}' -> '/^team\.([^.]+)\.channel\.([^.]+)$/'

        $paramNames = [];

        // First, replace {param} with placeholder before escaping
        $placeholder = '___PARAM_PLACEHOLDER___';
        $paramPattern = preg_replace_callback('/\{(\w+)\}/', function ($matches) use (&$paramNames, $placeholder) {
            $paramNames[] = $matches[1];
            return $placeholder;
        }, $pattern);

        // Escape special regex characters (dots, etc.)
        $escaped = preg_quote($paramPattern, '/');

        // Replace placeholders with capture groups
        $regex = '/^' . str_replace($placeholder, '([^.]+)', $escaped) . '$/';

        if (preg_match($regex, $channelName, $matches)) {
            array_shift($matches); // Remove full match

            $params = [];
            foreach ($paramNames as $index => $paramName) {
                $params[$paramName] = $matches[$index] ?? '';
            }

            return $params;
        }

        return null;
    }

    /**
     * Clear all registered channels (useful for testing).
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$channels = [];
    }

    /**
     * Check if a channel is registered.
     *
     * @param string $pattern Channel pattern
     * @return bool
     */
    public static function has(string $pattern): bool
    {
        return isset(self::$channels[$pattern]);
    }

    /**
     * Remove a channel definition.
     *
     * @param string $pattern Channel pattern
     * @return void
     */
    public static function remove(string $pattern): void
    {
        unset(self::$channels[$pattern]);
    }
}
