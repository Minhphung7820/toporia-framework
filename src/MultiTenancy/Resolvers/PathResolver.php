<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Resolvers;

use Toporia\Framework\Http\Request;
use Toporia\Framework\MultiTenancy\Contracts\TenantInterface;
use Toporia\Framework\MultiTenancy\Contracts\TenantResolverInterface;

/**
 * Class PathResolver
 *
 * Resolves tenant from URL path prefix.
 * Example: /tenant1/products -> tenant1
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class PathResolver implements TenantResolverInterface
{
    /**
     * @param callable(string): ?TenantInterface $finder Function to find tenant by slug
     * @param int $segmentIndex Path segment index (0-based, default: 0 = first segment)
     * @param string $prefix Optional prefix before tenant segment (e.g., 'tenant')
     */
    public function __construct(
        private readonly mixed $finder,
        private readonly int $segmentIndex = 0,
        private readonly string $prefix = ''
    ) {}

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request): ?TenantInterface
    {
        $path = trim($request->getPath(), '/');

        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);

        // Check if segment exists
        if (!isset($segments[$this->segmentIndex])) {
            return null;
        }

        $segment = $segments[$this->segmentIndex];

        // Handle prefix if set
        if ($this->prefix !== '') {
            if (!str_starts_with($segment, $this->prefix)) {
                return null;
            }
            $segment = substr($segment, strlen($this->prefix));
        }

        if ($segment === '') {
            return null;
        }

        return ($this->finder)($segment);
    }

    /**
     * {@inheritdoc}
     */
    public function priority(): int
    {
        return 80; // Lower priority than subdomain and header
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'path';
    }
}
