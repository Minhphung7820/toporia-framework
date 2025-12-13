<?php

declare(strict_types=1);

namespace Toporia\Framework\RateLimit;

use Toporia\Framework\Http\Request;
use Toporia\Framework\RateLimit\Contracts\RateLimiterInterface;

/**
 * Class RateLimiter
 *
 * Manages named rate limiters similar to Toporia's RateLimiter.
 * Allows defining reusable rate limit configurations in ServiceProviders.
 *
 * Usage in ServiceProvider:
 * ```php
 * RateLimiter::for('api-per-user', function (Request $request) {
 *     return Limit::perMinute(100)->by($request->user()?->id ?? $request->ip());
 * });
 * ```
 *
 * Usage in routes:
 * ```php
 * Route::middleware('throttle:api-per-user')->group(function () {
 *     Route::get('/orders', fn() => 'orders');
 * });
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  RateLimit
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RateLimiter
{
    /**
     * @var array<string, callable> Registered named limiters
     */
    private static array $limiters = [];

    /**
     * @var RateLimiterInterface Base rate limiter instance
     */
    private static ?RateLimiterInterface $limiter = null;

    /**
     * Register a named rate limiter.
     *
     * @param string $name Limiter name
     * @param callable $callback Callback that returns a Limit instance
     * @return void
     */
    public static function for(string $name, callable $callback): void
    {
        self::$limiters[$name] = $callback;
    }

    /**
     * Get a named rate limiter configuration.
     *
     * @param string $name Limiter name
     * @param Request $request Request instance
     * @return Limit|null Limit instance or null if not found
     */
    public static function limiter(string $name, Request $request): ?Limit
    {
        if (!isset(self::$limiters[$name])) {
            return null;
        }

        $callback = self::$limiters[$name];
        $limit = $callback($request);

        if (!$limit instanceof Limit) {
            throw new \InvalidArgumentException(
                "Rate limiter '{$name}' must return a Limit instance"
            );
        }

        return $limit;
    }

    /**
     * Check if a named limiter exists.
     *
     * @param string $name Limiter name
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$limiters[$name]);
    }

    /**
     * Set the base rate limiter instance.
     *
     * @param RateLimiterInterface $limiter
     * @return void
     */
    public static function setLimiter(RateLimiterInterface $limiter): void
    {
        self::$limiter = $limiter;
    }

    /**
     * Get the base rate limiter instance.
     *
     * @return RateLimiterInterface
     */
    public static function getLimiter(): RateLimiterInterface
    {
        if (self::$limiter === null) {
            throw new \RuntimeException('Rate limiter instance not set. Call RateLimiter::setLimiter() first.');
        }

        return self::$limiter;
    }

    /**
     * Clear all registered limiters (useful for testing).
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$limiters = [];
    }
}
