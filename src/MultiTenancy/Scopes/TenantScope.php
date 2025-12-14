<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Scopes;

use Toporia\Framework\Database\Query\QueryBuilder;
use Toporia\Framework\MultiTenancy\TenantManager;

/**
 * Class TenantScope
 *
 * Global scope that automatically filters queries by tenant_id.
 * Apply to models that belong to a tenant.
 *
 * Usage in Model:
 *   protected static function booted(): void
 *   {
 *       static::addGlobalScope('tenant', new TenantScope());
 *   }
 *
 * Or use the BelongsToTenant trait for automatic setup.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class TenantScope
{
    /**
     * @param string $column Tenant ID column name
     */
    public function __construct(
        private readonly string $column = 'tenant_id'
    ) {}

    /**
     * Apply the scope to a given query builder.
     *
     * @param QueryBuilder $query
     * @param object $model
     * @return void
     */
    public function apply(QueryBuilder $query, object $model): void
    {
        // Only apply if tenant context is active
        if (!TenantManager::check()) {
            return;
        }

        $tenantId = TenantManager::id();

        if ($tenantId !== null) {
            $query->where($this->column, '=', $tenantId);
        }
    }

    /**
     * Get the column name.
     *
     * @return string
     */
    public function getColumn(): string
    {
        return $this->column;
    }
}
