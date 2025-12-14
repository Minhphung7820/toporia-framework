<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Contracts;

/**
 * Interface TenantInterface
 *
 * Contract for Tenant entity in multi-tenancy system.
 * Implement this interface in your application's Tenant model.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
interface TenantInterface
{
    /**
     * Get tenant unique identifier.
     *
     * @return int|string
     */
    public function getTenantKey(): int|string;

    /**
     * Get tenant identifier column name.
     *
     * @return string
     */
    public function getTenantKeyName(): string;

    /**
     * Get tenant unique slug/subdomain.
     *
     * @return string|null
     */
    public function getSlug(): ?string;

    /**
     * Check if tenant is active.
     *
     * @return bool
     */
    public function isActive(): bool;

    /**
     * Get tenant configuration.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed;
}
