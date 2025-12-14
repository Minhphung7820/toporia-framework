<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Concerns;

/**
 * Trait HasTenants
 *
 * Use on User model to define user-tenant relationships.
 * Supports users belonging to multiple tenants.
 *
 * Usage:
 *   class User extends Model
 *   {
 *       use HasTenants;
 *
 *       // Optional: customize model and pivot table
 *       protected static string $tenantModel = Tenant::class;
 *       protected static string $tenantPivotTable = 'tenant_users';
 *   }
 *
 * @property-read string|null $tenantModel Optional custom tenant model class
 * @property-read string|null $tenantPivotTable Optional custom pivot table name
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
trait HasTenants
{
    /**
     * Get tenants the user belongs to.
     *
     * Override this method if using a different table/model.
     *
     * @return \Toporia\Framework\Database\ORM\Relations\BelongsToMany
     */
    public function tenants(): mixed
    {
        return $this->belongsToMany(
            static::getTenantModelClass(),
            static::getTenantPivotTable(),
            'user_id',
            'tenant_id'
        );
    }

    /**
     * Check if user belongs to a specific tenant.
     *
     * @param int|string $tenantId
     * @return bool
     */
    public function belongsToTenant(int|string $tenantId): bool
    {
        return $this->tenants()
            ->where(static::getTenantModelClass()::getPrimaryKeyName(), $tenantId)
            ->exists();
    }

    /**
     * Get tenant IDs the user belongs to.
     *
     * @return array<int|string>
     */
    public function getTenantIds(): array
    {
        return $this->tenants()
            ->pluck(static::getTenantModelClass()::getPrimaryKeyName())
            ->toArray();
    }

    /**
     * Get the tenant model class.
     *
     * @return string
     */
    protected static function getTenantModelClass(): string
    {
        if (defined(static::class . '::TENANT_MODEL')) {
            return constant(static::class . '::TENANT_MODEL');
        }

        return property_exists(static::class, 'tenantModel')
            ? static::$tenantModel
            : 'App\\Domain\\Entities\\Tenant';
    }

    /**
     * Get the pivot table name.
     *
     * @return string
     */
    protected static function getTenantPivotTable(): string
    {
        if (defined(static::class . '::TENANT_PIVOT_TABLE')) {
            return constant(static::class . '::TENANT_PIVOT_TABLE');
        }

        return property_exists(static::class, 'tenantPivotTable')
            ? static::$tenantPivotTable
            : 'tenant_users';
    }
}
