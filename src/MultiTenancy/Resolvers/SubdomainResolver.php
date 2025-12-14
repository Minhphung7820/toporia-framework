<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Resolvers;

use Toporia\Framework\Http\Request;
use Toporia\Framework\MultiTenancy\Contracts\TenantInterface;
use Toporia\Framework\MultiTenancy\Contracts\TenantResolverInterface;

/**
 * Class SubdomainResolver
 *
 * Resolves tenant from subdomain.
 * Example: tenant1.example.com -> tenant1
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class SubdomainResolver implements TenantResolverInterface
{
    /**
     * @param callable(string): ?TenantInterface $finder Function to find tenant by subdomain
     * @param string $baseDomain Base domain (e.g., 'example.com')
     * @param array<string> $excludedSubdomains Subdomains to exclude (e.g., 'www', 'api')
     */
    public function __construct(
        private readonly mixed $finder,
        private readonly string $baseDomain,
        private readonly array $excludedSubdomains = ['www', 'api', 'admin', 'mail', 'ftp']
    ) {}

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request): ?TenantInterface
    {
        $host = $request->getHost();

        // Extract subdomain
        $subdomain = $this->extractSubdomain($host);

        if ($subdomain === null || $subdomain === '') {
            return null;
        }

        // Check excluded subdomains
        if (in_array(strtolower($subdomain), $this->excludedSubdomains, true)) {
            return null;
        }

        // Find tenant by subdomain
        return ($this->finder)($subdomain);
    }

    /**
     * {@inheritdoc}
     */
    public function priority(): int
    {
        return 100; // High priority - subdomain is primary method
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'subdomain';
    }

    /**
     * Extract subdomain from host.
     *
     * @param string $host
     * @return string|null
     */
    private function extractSubdomain(string $host): ?string
    {
        // Remove port if present
        $host = strtolower(explode(':', $host)[0]);
        $baseDomain = strtolower($this->baseDomain);

        // Check if host ends with base domain
        if (!str_ends_with($host, $baseDomain)) {
            return null;
        }

        // Extract subdomain part
        $subdomain = substr($host, 0, -strlen($baseDomain));

        // Remove trailing dot
        return rtrim($subdomain, '.');
    }
}
