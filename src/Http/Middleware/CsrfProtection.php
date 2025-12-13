<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Middleware;

use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\Exceptions\TokenMismatchException;
use Toporia\Framework\Http\{Request, Response};
use Toporia\Framework\Security\Contracts\CsrfTokenManagerInterface;

/**
 * Class CsrfProtection
 *
 * CSRF Protection Middleware. Validates CSRF tokens for state-changing requests (POST, PUT, PATCH, DELETE).
 * Automatically skips validation for safe methods (GET, HEAD, OPTIONS).
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
final class CsrfProtection implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const TOKEN_FIELDS = ['_token', '_csrf', 'csrf_token'];
    private const CSRF_COOKIE_NAME = 'XSRF-TOKEN'; // Standard SPA CSRF cookie name

    /**
     * @var array URIs to exclude from CSRF verification
     */
    private array $except = [];

    /**
     * @param CsrfTokenManagerInterface $tokenManager
     * @param array|null $except URIs to exclude from CSRF verification (supports wildcards)
     *                          If null, will be resolved from config
     */
    public function __construct(
        private CsrfTokenManagerInterface $tokenManager,
        ?array $except = null
    ) {
        // Auto-resolve except URIs from config if not provided
        if ($except === null) {
            try {
                $config = app('config');
                $this->except = $config->get('security.csrf.except', []) ?? [];
            } catch (\Throwable $e) {
                $this->except = [];
            }
        } else {
            $this->except = $except;
        }
    }

    public function handle(Request $request, Response $response, callable $next): mixed
    {
        // Skip CSRF validation for safe methods
        if ($this->isSafeMethod($request->method())) {
            return $next($request, $response);
        }

        // Skip CSRF validation for excluded URIs
        if ($this->shouldExcludeUri($request->path())) {
            return $next($request, $response);
        }

        // Get token from request
        $token = $this->getTokenFromRequest($request);

        // Validate token
        if (!$this->validateToken($token)) {
            // Throw TokenMismatchException - will be caught by error handler
            throw new TokenMismatchException(
                'The CSRF token is invalid or has expired. Please reload the page and try again.'
            );
        }

        return $next($request, $response);
    }

    /**
     * Check if the HTTP method is safe (doesn't require CSRF protection)
     *
     * @param string $method
     * @return bool
     */
    private function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::SAFE_METHODS, true);
    }

    /**
     * Get CSRF token from request
     *
     * Checks multiple locations (in order of preference):
     * 1. Request body/input fields (_token, _csrf, csrf_token)
     * 2. X-CSRF-TOKEN header
     * 3. X-XSRF-TOKEN header (frontend reads XSRF-TOKEN cookie and sends as header)
     * 4. XSRF-TOKEN cookie (direct cookie access, fallback)
     *
     * Performance: O(N) where N = number of token fields (typically 3-4 checks)
     * Optimized: Early return on first match
     *
     * @param Request $request
     * @return string|null
     */
    private function getTokenFromRequest(Request $request): ?string
    {
        // Try input fields first (form submissions)
        foreach (self::TOKEN_FIELDS as $field) {
            $token = $request->input($field);
            if ($token !== null && $token !== '') {
                return $token;
            }
        }

        // Try headers (SPA typically sends X-XSRF-TOKEN header)
        // Headers may be URL-encoded by browser/client, so decode them
        $headerToken = $request->header('X-CSRF-TOKEN')
            ?? $request->header('X-XSRF-TOKEN');

        if ($headerToken !== null && $headerToken !== '') {
            // Decode URL-encoded header value if needed
            // urldecode is safe - it only affects %XX sequences
            // CSRF tokens are hex strings, but browsers may encode them anyway
            return urldecode($headerToken);
        }

        // Try cookie directly (fallback for SPA)
        // Cookie name is XSRF-TOKEN (standard SPA CSRF cookie name)
        // PHP automatically URL-decodes cookie values in $_COOKIE
        // But if cookie was set with setcookie(), it may still be encoded
        if (isset($_COOKIE[self::CSRF_COOKIE_NAME])) {
            $cookieToken = $_COOKIE[self::CSRF_COOKIE_NAME];
            if ($cookieToken !== null && $cookieToken !== '') {
                // Decode in case cookie value is still URL-encoded
                // urldecode is idempotent for non-encoded strings
                return urldecode($cookieToken);
            }
        }

        return null;
    }

    /**
     * Validate the CSRF token
     *
     * @param string|null $token
     * @return bool
     */
    private function validateToken(?string $token): bool
    {
        if ($token === null) {
            return false;
        }

        // Try to validate with default key
        if ($this->tokenManager->validate($token)) {
            return true;
        }

        // Try to validate with alternative key names
        foreach (self::TOKEN_FIELDS as $key) {
            if ($this->tokenManager->validate($token, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if URI should be excluded from CSRF verification.
     *
     * Supports wildcard patterns using asterisk.
     *
     * Performance: O(N*M) where N = except patterns, M = path length
     * Typically very fast as N is small (usually < 10 patterns)
     *
     * @param string $path Request path
     * @return bool True if URI should be excluded
     */
    private function shouldExcludeUri(string $path): bool
    {
        // Normalize path (ensure leading slash, remove trailing slash)
        $path = '/' . trim($path, '/');

        foreach ($this->except as $pattern) {
            // Normalize pattern
            $pattern = '/' . trim($pattern, '/');

            // Exact match
            if ($pattern === $path) {
                return true;
            }

            // Wildcard pattern matching
            if (str_contains($pattern, '*')) {
                // Convert wildcard pattern to regex
                // First, escape special regex chars except * and /
                $escaped = preg_quote($pattern, '#');
                // Unescape * and / (they were escaped by preg_quote)
                $escaped = str_replace(['\\*', '\\/'], ['*', '/'], $escaped);
                // Now replace * with .* and / with \/
                $regex = '#^' . str_replace(['*', '/'], ['.*', '\/'], $escaped) . '$#';
                if (preg_match($regex, $path)) {
                    return true;
                }
            }
        }

        return false;
    }
}
