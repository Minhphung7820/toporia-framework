<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Middleware;

use Toporia\Framework\Auth\Throttle\LoginThrottle;
use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\Exceptions\TooManyRequestsHttpException;
use Toporia\Framework\Http\{Request, Response};

/**
 * Class ThrottleLogins
 *
 * Middleware to throttle authentication attempts and prevent brute force attacks.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ThrottleLogins implements MiddlewareInterface
{
    /**
     * @param LoginThrottle $throttle Login throttle instance
     * @param int $maxAttempts Maximum attempts (overrides throttle default)
     * @param int $decayMinutes Decay time in minutes (overrides throttle default)
     */
    public function __construct(
        private LoginThrottle $throttle,
        private ?int $maxAttempts = null,
        private ?int $decayMinutes = null
    ) {}

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        $identifier = $this->getIdentifier($request);

        // Check if locked out
        if ($this->throttle->isLockedOut($identifier)) {
            $seconds = $this->throttle->getSecondsUntilUnlock($identifier);

            // Throw TooManyRequestsHttpException - will be caught by error handler
            throw new TooManyRequestsHttpException(
                $seconds,
                sprintf('Too many login attempts. Please try again in %s.', $this->formatDuration($seconds))
            );
        }

        // Process request
        $result = $next($request, $response);

        // Ensure we have a Response object
        $response = $result instanceof Response ? $result : $response;

        // If login failed, increment attempts
        if ($response->status() === 401 || $response->status() === 422) {
            $attempts = $this->throttle->incrementAttempts($identifier);
            $remaining = $this->throttle->getRemainingAttempts($identifier);

            // Add headers
            $response->header('X-RateLimit-Limit', (string) ($this->maxAttempts ?? 5));
            $response->header('X-RateLimit-Remaining', (string) $remaining);

            if ($remaining === 0) {
                $seconds = $this->throttle->getSecondsUntilUnlock($identifier);
                $response->header('Retry-After', (string) $seconds);
            }
        } else {
            // Login successful - clear attempts
            $this->throttle->clearAttempts($identifier);
        }

        return $response;
    }

    /**
     * Get identifier for throttling (email, username, or IP).
     *
     * @param Request $request HTTP request
     * @return string Identifier
     */
    private function getIdentifier(Request $request): string
    {
        // Try email first
        $email = $request->input('email');
        if ($email !== null) {
            return $email;
        }

        // Try username
        $username = $request->input('username');
        if ($username !== null) {
            return $username;
        }

        // Fallback to IP address
        return $request->ip() ?? 'unknown';
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
}
