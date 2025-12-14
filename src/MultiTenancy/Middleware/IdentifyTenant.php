<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Middleware;

use Toporia\Framework\Http\Middleware\AbstractMiddleware;
use Toporia\Framework\Http\Request;
use Toporia\Framework\Http\Response;
use Toporia\Framework\MultiTenancy\TenantManager;
use Toporia\Framework\MultiTenancy\Exceptions\TenantException;

/**
 * Class IdentifyTenant
 *
 * Middleware to identify and set tenant context from request.
 * Should be applied to all tenant-aware routes.
 *
 * Usage in routes:
 *   $router->group(['middleware' => ['tenant']], function ($router) {
 *       $router->get('/dashboard', [DashboardController::class, 'index']);
 *   });
 *
 * Behavior:
 * - If tenant found: Sets context and continues
 * - If tenant not found: Returns 404 or continues based on configuration
 * - If tenant inactive: Returns 403
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class IdentifyTenant extends AbstractMiddleware
{
    /**
     * @param bool $required Whether tenant is required (fail if not found)
     * @param bool $checkActive Whether to verify tenant is active
     */
    public function __construct(
        private readonly bool $required = true,
        private readonly bool $checkActive = true
    ) {}

    /**
     * {@inheritdoc}
     */
    protected function process(Request $request, Response $response): ?Response
    {
        // Resolve tenant from request
        $tenant = TenantManager::initialize($request);

        // Handle tenant not found
        if ($tenant === null) {
            if ($this->required) {
                return $this->tenantNotFound($response);
            }
            // Optional tenant - continue without context
            return null;
        }

        // Check if tenant is active
        if ($this->checkActive && !$tenant->isActive()) {
            return $this->tenantInactive($response, $tenant->getTenantKey());
        }

        // Store tenant info in request attributes for later use
        $request->setAttribute('tenant', $tenant);
        $request->setAttribute('tenant_id', $tenant->getTenantKey());

        return null; // Continue to next middleware
    }

    /**
     * {@inheritdoc}
     */
    protected function after(Request $request, Response $response, mixed $result): void
    {
        // Clean up tenant context after request
        // Note: Only forget if this middleware set the context
        if (TenantManager::wasIdentified()) {
            TenantManager::forget();
        }
    }

    /**
     * Return tenant not found response.
     *
     * @param Response $response
     * @return Response
     */
    protected function tenantNotFound(Response $response): Response
    {
        $response->json([
            'success' => false,
            'error' => 'Tenant not found',
            'code' => 'TENANT_NOT_FOUND',
        ], 404);

        return $response;
    }

    /**
     * Return tenant inactive response.
     *
     * @param Response $response
     * @param int|string $tenantId
     * @return Response
     */
    protected function tenantInactive(Response $response, int|string $tenantId): Response
    {
        $response->json([
            'success' => false,
            'error' => 'Tenant is not active',
            'code' => 'TENANT_INACTIVE',
        ], 403);

        return $response;
    }
}
