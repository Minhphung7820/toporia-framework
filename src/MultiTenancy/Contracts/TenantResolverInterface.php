<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Contracts;

use Toporia\Framework\Http\Request;

/**
 * Interface TenantResolverInterface
 *
 * Contract for tenant resolution strategies.
 * Multiple resolvers can be chained (subdomain, header, path, etc.)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
interface TenantResolverInterface
{
    /**
     * Resolve tenant from request.
     *
     * @param Request $request
     * @return TenantInterface|null Returns null if not resolved
     */
    public function resolve(Request $request): ?TenantInterface;

    /**
     * Get resolver priority (higher = runs first).
     *
     * @return int
     */
    public function priority(): int;

    /**
     * Get resolver name for debugging/logging.
     *
     * @return string
     */
    public function name(): string;
}
