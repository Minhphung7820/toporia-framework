<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Exceptions;

use RuntimeException;
use Toporia\Framework\MultiTenancy\Contracts\TenantInterface;

/**
 * Class TenantException
 *
 * Exception for multi-tenancy errors.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class TenantException extends RuntimeException
{
    /**
     * No tenant context active.
     *
     * @return self
     */
    public static function noTenantContext(): self
    {
        return new self(
            'No tenant context is active. ' .
            'Ensure the request passes through tenant middleware or set tenant manually.',
            1001
        );
    }

    /**
     * Tenant not found.
     *
     * @param string $identifier
     * @return self
     */
    public static function notFound(string $identifier): self
    {
        return new self(
            "Tenant with identifier [{$identifier}] not found.",
            1002
        );
    }

    /**
     * Tenant is not active.
     *
     * @param TenantInterface $tenant
     * @return self
     */
    public static function inactive(TenantInterface $tenant): self
    {
        return new self(
            "Tenant [{$tenant->getTenantKey()}] is not active.",
            1003
        );
    }

    /**
     * Invalid tenant identifier.
     *
     * @param string $identifier
     * @return self
     */
    public static function invalidIdentifier(string $identifier): self
    {
        return new self(
            "Invalid tenant identifier: [{$identifier}].",
            1004
        );
    }

    /**
     * Tenant resolution failed.
     *
     * @param string $reason
     * @return self
     */
    public static function resolutionFailed(string $reason): self
    {
        return new self(
            "Tenant resolution failed: {$reason}",
            1005
        );
    }

    /**
     * Database connection failed for tenant.
     *
     * @param TenantInterface $tenant
     * @param string $reason
     * @return self
     */
    public static function databaseConnectionFailed(TenantInterface $tenant, string $reason): self
    {
        return new self(
            "Database connection failed for tenant [{$tenant->getTenantKey()}]: {$reason}",
            1006
        );
    }
}
