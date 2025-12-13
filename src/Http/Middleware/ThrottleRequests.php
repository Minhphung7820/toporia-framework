<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Middleware;

use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\Exceptions\TooManyRequestsHttpException;
use Toporia\Framework\Http\{Request, Response};
use Toporia\Framework\RateLimit\{Contracts\RateLimiterInterface, RateLimiter, Limit};

/**
 * Class ThrottleRequests
 *
 * Rate limits HTTP requests based on configurable criteria. Supports named limiters (Toporia style) and direct configuration.
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
final class ThrottleRequests implements MiddlewareInterface
{
    /**
     * @param RateLimiterInterface $limiter Base rate limiter instance
     * @param int|null $maxAttempts Maximum attempts (null = use named limiter)
     * @param int|null $decayMinutes Decay time in minutes (null = use named limiter)
     * @param string|null $namedLimiter Named limiter name (e.g., 'api-per-user')
     * @param string|null $prefix Optional prefix for rate limit key
     */
    public function __construct(
        private RateLimiterInterface $limiter,
        private ?int $maxAttempts = null,
        private ?int $decayMinutes = null,
        private ?string $namedLimiter = null,
        private ?string $prefix = null
    ) {}

    public function handle(Request $request, Response $response, callable $next): mixed
    {
        // Resolve limit configuration (named limiter or direct config)
        $limit = $this->resolveLimit($request);

        if ($limit === null) {
            // No limit configured - allow request
            return $next($request, $response);
        }

        $key = $this->resolveRequestSignature($request, $limit);
        $maxAttempts = $limit->getMaxAttempts();
        $decaySeconds = $limit->getDecaySeconds();

        // Check if rate limit exceeded (this will cache results internally)
        if ($this->limiter->tooManyAttempts($key, $maxAttempts, $decaySeconds)) {
            $this->throwRateLimitException($response, $key, $maxAttempts, $decaySeconds);
        }

        // Attempt to consume rate limit (will increment attempts)
        // Note: attempt() internally calls tooManyAttempts() again, but results are cached
        $this->limiter->attempt($key, $maxAttempts, $decaySeconds);

        $result = $next($request, $response);

        // Add rate limit headers (will reuse cached results)
        $this->addHeaders($response, $key, $maxAttempts);

        return $result;
    }

    /**
     * Resolve limit configuration from named limiter or direct config.
     *
     * @param Request $request
     * @return Limit|null
     */
    private function resolveLimit(Request $request): ?Limit
    {
        // Priority 1: Named limiter
        if ($this->namedLimiter !== null) {
            return RateLimiter::limiter($this->namedLimiter, $request);
        }

        // Priority 2: Direct configuration
        if ($this->maxAttempts !== null && $this->decayMinutes !== null) {
            return new Limit(
                $this->maxAttempts,
                $this->decayMinutes * 60,
                null,
                $this->prefix
            );
        }

        // No limit configured
        return null;
    }

    /**
     * Throw rate limit exceeded exception
     *
     * @param Response $response
     * @param string $key
     * @param int $maxAttempts
     * @param int $decaySeconds
     * @return never
     * @throws TooManyRequestsHttpException
     */
    private function throwRateLimitException(Response $response, string $key, int $maxAttempts, int $decaySeconds): never
    {
        // Get the actual retry after time
        $retryAfter = $this->limiter->availableIn($key, $decaySeconds);

        // If retryAfter is 0, rate limit has expired - allow the request
        // Don't set fallback to decaySeconds as that would incorrectly extend the rate limit
        if ($retryAfter <= 0) {
            $retryAfter = 0;
        }

        // Add rate limit headers to response before throwing
        $response->header('X-RateLimit-Limit', (string)$maxAttempts);
        $response->header('X-RateLimit-Remaining', '0');
        $response->header('X-RateLimit-Reset', (string)(now()->getTimestamp() + $retryAfter));

        // Throw TooManyRequestsHttpException - will be caught by error handler
        throw new TooManyRequestsHttpException(
            $retryAfter > 0 ? $retryAfter : null,
            $retryAfter > 0
                ? 'Rate limit exceeded. Please try again in ' . $this->formatDuration($retryAfter) . '.'
                : 'Rate limit exceeded.'
        );
    }

    /**
     * Format seconds into human-readable duration (e.g., 1h25m38s, 1m54s, 45s)
     *
     * @param int $seconds
     * @return string
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $hours = intval($seconds / 3600);
        $minutes = intval(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }

        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }

        if ($secs > 0) {
            $parts[] = $secs . 's';
        }

        return implode('', $parts);
    }

    /**
     * Add rate limit headers to response
     *
     * @param Response $response
     * @param string $key
     * @param int $maxAttempts
     * @return void
     */
    private function addHeaders(Response $response, string $key, int $maxAttempts): void
    {
        // Get remaining attempts (will reuse cached results from attempt())
        $remaining = $this->limiter->remaining($key, $maxAttempts);

        // Get availableIn (will reuse cached results)
        $availableIn = $this->limiter->availableIn($key);

        $response->header('X-RateLimit-Limit', (string)$maxAttempts);
        $response->header('X-RateLimit-Remaining', (string)$remaining);
        $response->header('X-RateLimit-Reset', (string)(now()->getTimestamp() + $availableIn));
    }

    /**
     * Resolve the request signature for rate limiting
     *
     * @param Request $request
     * @param Limit $limit
     * @return string
     */
    private function resolveRequestSignature(Request $request, Limit $limit): string
    {
        $prefix = $limit->getPrefix() ?? $this->prefix ?? 'throttle';

        // Use custom key resolver if provided
        $keyResolver = $limit->getKeyResolver();
        if ($keyResolver !== null) {
            $key = $keyResolver($request);
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Key resolver must return a string');
            }
            return $prefix . ':' . $key;
        }

        // Default: use user ID or IP
        $parts = [
            $prefix,
            $this->getUserIdentifier($request),
            $request->path(),
        ];

        return implode(':', $parts);
    }

    /**
     * Get user identifier for rate limiting
     *
     * Uses authenticated user ID if available, falls back to IP address.
     *
     * @param Request $request
     * @return string
     */
    private function getUserIdentifier(Request $request): string
    {
        // Try to get authenticated user ID
        try {
            $user = auth()->user();
            if ($user && method_exists($user, 'getId')) {
                return 'user:' . $user->getId();
            }
        } catch (\Throwable $e) {
            // Auth not available or user not authenticated
        }

        // Fall back to IP address
        return 'ip:' . $request->ip();
    }

    /**
     * Create a throttle middleware with specific limits
     *
     * @param RateLimiterInterface $limiter
     * @param int $maxAttempts
     * @param int $decayMinutes
     * @param string|null $prefix
     * @return self
     */
    public static function with(
        RateLimiterInterface $limiter,
        int $maxAttempts = 60,
        int $decayMinutes = 1,
        ?string $prefix = null
    ): self {
        return new self($limiter, $maxAttempts, $decayMinutes, null, $prefix);
    }
}
