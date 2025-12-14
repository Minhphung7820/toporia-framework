<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Events;

use Toporia\Framework\MultiTenancy\Contracts\TenantInterface;

/**
 * Class TenantIdentified
 *
 * Dispatched when a tenant is successfully identified from request.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class TenantIdentified
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly string $resolvedBy
    ) {}
}
