<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Middleware;

use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\{Request, Response};

/**
 * Class HandleCors
 *
 * CORS (Cross-Origin Resource Sharing) Middleware. Handles CORS requests by adding appropriate headers to responses.
 * Supports preflight requests (OPTIONS) and validates origins.
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
final class HandleCors implements MiddlewareInterface
{
    /**
     * Default allowed methods
     */
    private const DEFAULT_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * Default allowed headers
     */
    private const DEFAULT_HEADERS = [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-Replay-Nonce',
    ];

    /**
     * @param array|null $config CORS configuration (null = resolve from config)
     */
    public function __construct(
        ?array $config = null
    ) {
        // Auto-resolve config from container if not provided
        if ($config === null) {
            try {
                $configService = app('config');
                $this->config = $configService->get('security.cors', []) ?? [];
            } catch (\Throwable $e) {
                $this->config = [];
            }
        } else {
            $this->config = $config;
        }
    }

    private array $config;

    /**
     * Handle the request.
     *
     * Processes CORS requests:
     * 1. Check if CORS is enabled
     * 2. Handle preflight requests (OPTIONS) - return immediately
     * 3. Add CORS headers to actual requests
     *
     * Performance: O(1) - Early return if disabled
     *
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return mixed
     */
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        // Check if CORS is enabled
        if (!($this->config['enabled'] ?? true)) {
            return $next($request, $response);
        }

        // Handle preflight requests (OPTIONS)
        if ($request->method() === 'OPTIONS') {
            return $this->handlePreflight($request, $response);
        }

        // Add CORS headers to actual requests
        $this->addCorsHeaders($request, $response);

        return $next($request, $response);
    }

    /**
     * Handle preflight OPTIONS request.
     *
     * Preflight requests are sent by browsers before actual CORS requests.
     * We validate the request and return appropriate headers.
     *
     * Performance: O(1) - Direct header setting, no route processing
     *
     * @param Request $request
     * @param Response $response
     * @return null
     */
    private function handlePreflight(Request $request, Response $response): null
    {
        $origin = $request->header('Origin');

        // Validate origin
        if (!$this->isOriginAllowed($origin)) {
            $response->setStatus(403);
            $response->json([
                'error' => 'CORS policy: Origin not allowed'
            ], 403);
            return null;
        }

        // Validate requested method
        $requestMethod = $request->header('Access-Control-Request-Method');
        if ($requestMethod && !$this->isMethodAllowed($requestMethod)) {
            $response->setStatus(405);
            $response->json([
                'error' => 'CORS policy: Method not allowed'
            ], 405);
            return null;
        }

        // Validate requested headers
        $requestHeaders = $this->parseRequestedHeaders($request);
        if (!$this->areHeadersAllowed($requestHeaders)) {
            $response->setStatus(403);
            $response->json([
                'error' => 'CORS policy: Headers not allowed'
            ], 403);
            return null;
        }

        // Set CORS headers for preflight
        $this->setPreflightHeaders($request, $response, $requestMethod, $requestHeaders);

        // Return empty 204 response
        $response->setStatus(204);
        $response->send('');

        return null;
    }

    /**
     * Add CORS headers to actual requests.
     *
     * Performance: O(1) - Direct header setting
     *
     * @param Request $request
     * @param Response $response
     * @return void
     */
    private function addCorsHeaders(Request $request, Response $response): void
    {
        $origin = $request->header('Origin');

        // Only add headers if origin is present and allowed
        if ($origin === null || !$this->isOriginAllowed($origin)) {
            return;
        }

        // Get allowed origin value
        $allowedOrigin = $this->getAllowedOrigin($origin);
        if ($allowedOrigin === null) {
            return; // Origin not allowed, don't set headers
        }

        // Set allowed origin
        $response->header('Access-Control-Allow-Origin', $allowedOrigin);

        // Set credentials if enabled
        if ($this->config['credentials'] ?? false) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        // Set exposed headers if configured
        $exposedHeaders = $this->config['exposed_headers'] ?? [];
        if (!empty($exposedHeaders)) {
            $response->header('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));
        }
    }

    /**
     * Set headers for preflight response.
     *
     * Performance: O(1) - Direct header setting
     *
     * @param Request $request
     * @param Response $response
     * @param string|null $requestMethod
     * @param array $requestHeaders
     * @return void
     */
    private function setPreflightHeaders(
        Request $request,
        Response $response,
        ?string $requestMethod,
        array $requestHeaders
    ): void {
        $origin = $request->header('Origin');

        // Get allowed origin value
        $allowedOrigin = $this->getAllowedOrigin($origin);
        if ($allowedOrigin === null) {
            return; // Should not happen as we validate before calling this
        }

        // Allowed origin
        $response->header('Access-Control-Allow-Origin', $allowedOrigin);

        // Allowed methods
        $allowedMethods = $this->getAllowedMethods();
        $response->header('Access-Control-Allow-Methods', implode(', ', $allowedMethods));

        // Allowed headers
        $allowedHeaders = $this->getAllowedHeaders();
        $response->header('Access-Control-Allow-Headers', implode(', ', $allowedHeaders));

        // Credentials
        if ($this->config['credentials'] ?? false) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        // Max-Age (preflight cache duration)
        $maxAge = $this->config['max_age'] ?? 3600;
        if ($maxAge > 0) {
            $response->header('Access-Control-Max-Age', (string) $maxAge);
        }
    }

    /**
     * Check if origin is allowed.
     *
     * Performance: O(1) for wildcard, O(N) for array lookup where N = allowed origins
     *
     * @param string|null $origin
     * @return bool
     */
    private function isOriginAllowed(?string $origin): bool
    {
        if ($origin === null) {
            return false;
        }

        $allowedOrigins = $this->config['allowed_origins'] ?? [];

        // If no origins configured, deny all (secure default)
        if (empty($allowedOrigins)) {
            return false;
        }

        // Allow all origins
        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }

        // Check exact match
        if (in_array($origin, $allowedOrigins, true)) {
            return true;
        }

        // Check pattern matching (e.g., *.example.com)
        foreach ($allowedOrigins as $pattern) {
            if ($this->matchesPattern($origin, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get allowed origin value for header.
     *
     * Returns '*' for wildcard or specific origin.
     * Returns null if origin not allowed (should not set header).
     *
     * Performance: O(1) for wildcard, O(N) for pattern matching
     *
     * @param string|null $origin
     * @return string|null
     */
    private function getAllowedOrigin(?string $origin): ?string
    {
        if ($origin === null) {
            return null;
        }

        $allowedOrigins = $this->config['allowed_origins'] ?? [];

        // If no origins configured, return null (deny)
        if (empty($allowedOrigins)) {
            return null;
        }

        // Wildcard
        if (in_array('*', $allowedOrigins, true)) {
            // If credentials enabled, can't use wildcard - must return specific origin
            if ($this->config['credentials'] ?? false) {
                return $origin; // Return specific origin
            }
            return '*';
        }

        // Return specific origin if allowed
        if (in_array($origin, $allowedOrigins, true)) {
            return $origin;
        }

        // Pattern match
        foreach ($allowedOrigins as $pattern) {
            if ($this->matchesPattern($origin, $pattern)) {
                return $origin;
            }
        }

        return null; // Origin not allowed
    }

    /**
     * Check if origin matches pattern (supports wildcards).
     *
     * Performance: O(1) - String operations
     *
     * @param string $origin
     * @param string $pattern
     * @return bool
     */
    private function matchesPattern(string $origin, string $pattern): bool
    {
        // Convert pattern to regex
        $regex = str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/'));
        return (bool) preg_match('/^' . $regex . '$/i', $origin);
    }

    /**
     * Check if method is allowed.
     *
     * Performance: O(1) - Array lookup
     *
     * @param string $method
     * @return bool
     */
    private function isMethodAllowed(string $method): bool
    {
        $allowedMethods = $this->getAllowedMethods();
        return in_array(strtoupper($method), $allowedMethods, true);
    }

    /**
     * Get allowed methods.
     *
     * Performance: O(1) - Array access
     *
     * @return array<string>
     */
    private function getAllowedMethods(): array
    {
        return $this->config['allowed_methods'] ?? self::DEFAULT_METHODS;
    }

    /**
     * Parse requested headers from Access-Control-Request-Headers header.
     *
     * Performance: O(N) where N = number of headers
     *
     * @param Request $request
     * @return array<string>
     */
    private function parseRequestedHeaders(Request $request): array
    {
        $headerValue = $request->header('Access-Control-Request-Headers');
        if ($headerValue === null || $headerValue === '') {
            return [];
        }

        // Split by comma and trim
        $headers = array_map('trim', explode(',', $headerValue));
        return array_filter($headers);
    }

    /**
     * Check if requested headers are allowed.
     *
     * Performance: O(N*M) where N = requested headers, M = allowed headers
     * Typically small numbers, so effectively O(1)
     *
     * @param array<string> $requestedHeaders
     * @return bool
     */
    private function areHeadersAllowed(array $requestedHeaders): bool
    {
        if (empty($requestedHeaders)) {
            return true; // No headers requested
        }

        $allowedHeaders = $this->getAllowedHeaders();
        $allowedHeadersLower = array_map('strtolower', $allowedHeaders);

        foreach ($requestedHeaders as $header) {
            $headerLower = strtolower($header);
            if (!in_array($headerLower, $allowedHeadersLower, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get allowed headers.
     *
     * Performance: O(1) - Array access
     *
     * @return array<string>
     */
    private function getAllowedHeaders(): array
    {
        return $this->config['allowed_headers'] ?? self::DEFAULT_HEADERS;
    }
}
