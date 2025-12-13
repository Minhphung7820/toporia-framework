<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing;

use Toporia\Framework\Http\Request;

/**
 * Class SubdomainRouter
 *
 * Handles subdomain-based routing similar to Toporia's subdomain routing.
 *
 * Performance:
 * - O(1) subdomain extraction
 * - Pre-compiled patterns for subdomain matching
 * - Wildcard subdomain support
 *
 * Example:
 * ```php
 * // Static subdomain
 * $router->domain('api.example.com')->group(function ($router) {
 *     $router->get('/users', [ApiUserController::class, 'index']);
 * });
 *
 * // Dynamic subdomain with parameter
 * $router->domain('{tenant}.example.com')->group(function ($router) {
 *     $router->get('/dashboard', [TenantController::class, 'dashboard']);
 * });
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
 *
 * // Wildcard subdomain
 * $router->domain('*.example.com')->group(function ($router) {
 *     $router->get('/', [WildcardController::class, 'handle']);
 * });
 * ```
 */
class SubdomainRouter
{
    /**
     * Registered domain patterns.
     *
     * @var array<string, array{pattern: string, compiled: string, parameters: array<string>}>
     */
    protected array $domains = [];

    /**
     * Current domain for grouping.
     *
     * @var string|null
     */
    protected ?string $currentDomain = null;

    /**
     * Extracted subdomain parameters for current request.
     *
     * @var array<string, string>
     */
    protected array $subdomainParameters = [];

    /**
     * Register a domain pattern.
     *
     * @param string $domain Domain pattern (e.g., '{tenant}.example.com')
     * @return static
     */
    public function domain(string $domain): static
    {
        $parameters = [];
        $compiled = $this->compileDomainPattern($domain, $parameters);

        $this->domains[$domain] = [
            'pattern' => $domain,
            'compiled' => $compiled,
            'parameters' => $parameters,
        ];

        $this->currentDomain = $domain;

        return $this;
    }

    /**
     * Compile a domain pattern to regex.
     *
     * @param string $pattern Domain pattern
     * @param array<string> $parameters Reference to store parameter names
     * @return string Compiled regex pattern
     */
    protected function compileDomainPattern(string $pattern, array &$parameters): string
    {
        // Extract parameters from pattern
        if (preg_match_all('#\{([^}]+)\}#', $pattern, $matches)) {
            $parameters = $matches[1];
        }

        // Convert pattern to regex
        $regex = preg_quote($pattern, '#');

        // Replace {param} with capture groups
        foreach ($parameters as $param) {
            $regex = str_replace(
                '\\{' . $param . '\\}',
                '(?P<' . $param . '>[^.]+)',
                $regex
            );
        }

        // Handle wildcard (*) subdomain
        $regex = str_replace('\\*', '[^.]+', $regex);

        return '#^' . $regex . '$#i';
    }

    /**
     * Match a request against registered domains.
     *
     * @param Request $request HTTP request
     * @return array{domain: string, parameters: array<string, string>}|null
     */
    public function match(Request $request): ?array
    {
        $host = $this->extractHost($request);

        foreach ($this->domains as $domainKey => $domain) {
            if (preg_match($domain['compiled'], $host, $matches)) {
                $parameters = [];

                foreach ($domain['parameters'] as $param) {
                    if (isset($matches[$param])) {
                        $parameters[$param] = $matches[$param];
                    }
                }

                $this->subdomainParameters = $parameters;

                return [
                    'domain' => $domainKey,
                    'parameters' => $parameters,
                ];
            }
        }

        return null;
    }

    /**
     * Extract host from request.
     *
     * @param Request $request
     * @return string
     */
    protected function extractHost(Request $request): string
    {
        $host = $request->getHeader('Host') ?? '';

        // Remove port if present
        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        return strtolower($host);
    }

    /**
     * Extract subdomain from a host.
     *
     * @param string $host Full host (e.g., 'tenant.api.example.com')
     * @param string $baseDomain Base domain (e.g., 'example.com')
     * @return string|null Subdomain (e.g., 'tenant.api') or null if no subdomain
     */
    public function extractSubdomain(string $host, string $baseDomain): ?string
    {
        $host = strtolower($host);
        $baseDomain = strtolower($baseDomain);

        if ($host === $baseDomain) {
            return null;
        }

        if (!str_ends_with($host, '.' . $baseDomain)) {
            return null;
        }

        return substr($host, 0, -strlen('.' . $baseDomain));
    }

    /**
     * Get subdomain parts as array.
     *
     * @param string $subdomain Full subdomain (e.g., 'tenant.api')
     * @return array<string> Parts (e.g., ['tenant', 'api'])
     */
    public function getSubdomainParts(string $subdomain): array
    {
        return explode('.', $subdomain);
    }

    /**
     * Get the current domain pattern.
     *
     * @return string|null
     */
    public function getCurrentDomain(): ?string
    {
        return $this->currentDomain;
    }

    /**
     * Set the current domain for grouping.
     *
     * @param string|null $domain
     * @return static
     */
    public function setCurrentDomain(?string $domain): static
    {
        $this->currentDomain = $domain;

        return $this;
    }

    /**
     * Get subdomain parameters from current request.
     *
     * @return array<string, string>
     */
    public function getSubdomainParameters(): array
    {
        return $this->subdomainParameters;
    }

    /**
     * Get a specific subdomain parameter.
     *
     * @param string $key Parameter name
     * @param string|null $default Default value
     * @return string|null
     */
    public function getSubdomainParameter(string $key, ?string $default = null): ?string
    {
        return $this->subdomainParameters[$key] ?? $default;
    }

    /**
     * Clear the current domain context.
     *
     * @return static
     */
    public function clearCurrentDomain(): static
    {
        $this->currentDomain = null;

        return $this;
    }

    /**
     * Check if a domain pattern is registered.
     *
     * @param string $domain
     * @return bool
     */
    public function hasDomain(string $domain): bool
    {
        return isset($this->domains[$domain]);
    }

    /**
     * Get all registered domains.
     *
     * @return array<string, array{pattern: string, compiled: string, parameters: array<string>}>
     */
    public function getDomains(): array
    {
        return $this->domains;
    }

    /**
     * Remove a registered domain.
     *
     * @param string $domain
     * @return static
     */
    public function removeDomain(string $domain): static
    {
        unset($this->domains[$domain]);

        return $this;
    }

    /**
     * Create a new domain group with callback.
     *
     * @param string $domain Domain pattern
     * @param callable $callback Group callback
     * @param Router $router Router instance
     * @return void
     */
    public function group(string $domain, callable $callback, Router $router): void
    {
        $previousDomain = $this->currentDomain;

        $this->domain($domain);

        $callback($router);

        $this->currentDomain = $previousDomain;
    }

    /**
     * Check if current request matches a domain pattern.
     *
     * @param string $pattern Domain pattern
     * @param Request $request HTTP request
     * @return bool
     */
    public function matches(string $pattern, Request $request): bool
    {
        $parameters = [];
        $compiled = $this->compileDomainPattern($pattern, $parameters);
        $host = $this->extractHost($request);

        return (bool) preg_match($compiled, $host);
    }
}
