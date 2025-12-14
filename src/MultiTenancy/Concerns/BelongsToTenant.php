<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Concerns;

use Toporia\Framework\MultiTenancy\TenantManager;
use Toporia\Framework\MultiTenancy\Scopes\TenantScope;

/**
 * Trait BelongsToTenant
 *
 * Use this trait on models that belong to a tenant.
 * Automatically:
 * - Applies TenantScope to filter queries
 * - Sets tenant_id on creating
 * - Provides helper methods
 *
 * Usage:
 *   class Product extends Model
 *   {
 *       use BelongsToTenant;
 *
 *       // Optional: customize column name
 *       protected static string $tenantColumn = 'tenant_id';
 *   }
 *
 * @property-read string|null $tenantColumn Optional custom tenant column name
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
trait BelongsToTenant
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootBelongsToTenant(): void
    {
        // Add global scope for automatic tenant filtering
        static::addGlobalScope('tenant', new TenantScope(static::getTenantColumn()));

        // Auto-set tenant_id on creating
        static::creating(function ($model) {
            $column = static::getTenantColumn();

            // Only set if not already set and tenant context exists
            if ($model->{$column} === null && TenantManager::check()) {
                $model->{$column} = TenantManager::id();
            }
        });
    }

    /**
     * Get the tenant ID column name.
     *
     * @return string
     */
    public static function getTenantColumn(): string
    {
        if (defined(static::class . '::TENANT_COLUMN')) {
            return constant(static::class . '::TENANT_COLUMN');
        }

        return property_exists(static::class, 'tenantColumn')
            ? static::$tenantColumn
            : 'tenant_id';
    }

    /**
     * Get the tenant ID for this model.
     *
     * @return int|string|null
     */
    public function getTenantId(): int|string|null
    {
        return $this->{static::getTenantColumn()};
    }

    /**
     * Check if model belongs to current tenant.
     *
     * @return bool
     */
    public function belongsToCurrentTenant(): bool
    {
        if (!TenantManager::check()) {
            return false;
        }

        return $this->getTenantId() === TenantManager::id();
    }

    /**
     * Query without tenant scope.
     *
     * @return \Toporia\Framework\Database\ORM\ModelQueryBuilder
     */
    public static function withoutTenantScope(): mixed
    {
        return static::withoutGlobalScope('tenant');
    }

    /**
     * Query for specific tenant.
     *
     * @param int|string $tenantId
     * @return \Toporia\Framework\Database\ORM\ModelQueryBuilder
     */
    public static function forTenant(int|string $tenantId): mixed
    {
        return static::withoutGlobalScope('tenant')
            ->where(static::getTenantColumn(), $tenantId);
    }

    /**
     * Query for all tenants.
     *
     * @return \Toporia\Framework\Database\ORM\ModelQueryBuilder
     */
    public static function allTenants(): mixed
    {
        return static::withoutGlobalScope('tenant');
    }
}
