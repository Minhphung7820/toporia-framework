<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Resolvers;

use Toporia\Framework\Http\Request;
use Toporia\Framework\MultiTenancy\Contracts\TenantInterface;
use Toporia\Framework\MultiTenancy\Contracts\TenantResolverInterface;

/**
 * Class HeaderResolver
 *
 * Resolves tenant from HTTP header.
 * Useful for API requests with X-Tenant-ID header.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class HeaderResolver implements TenantResolverInterface
{
    /**
     * @param callable(string): ?TenantInterface $finder Function to find tenant by identifier
     * @param string $headerName Header name (default: X-Tenant-ID)
     */
    public function __construct(
        private readonly mixed $finder,
        private readonly string $headerName = 'X-Tenant-ID'
    ) {}

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request): ?TenantInterface
    {
        $identifier = $request->header($this->headerName);

        if ($identifier === null || $identifier === '') {
            return null;
        }

        // Sanitize identifier
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        return ($this->finder)($identifier);
    }

    /**
     * {@inheritdoc}
     */
    public function priority(): int
    {
        return 90; // Slightly lower than subdomain
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'header';
    }
}
