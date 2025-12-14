<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Middleware;

use Toporia\Framework\Http\Middleware\AbstractMiddleware;
use Toporia\Framework\Http\Request;
use Toporia\Framework\Http\Response;
use Toporia\Framework\MultiTenancy\TenantManager;

/**
 * Class EnsureTenant
 *
 * Middleware to ensure a tenant context exists.
 * Does NOT resolve tenant - only verifies context is set.
 *
 * Use after IdentifyTenant or when tenant is set programmatically.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class EnsureTenant extends AbstractMiddleware
{
    /**
     * {@inheritdoc}
     */
    protected function process(Request $request, Response $response): ?Response
    {
        if (!TenantManager::check()) {
            return $response->json([
                'success' => false,
                'error' => 'Tenant context required',
                'code' => 'TENANT_REQUIRED',
            ], 403);
        }

        return null;
    }
}
