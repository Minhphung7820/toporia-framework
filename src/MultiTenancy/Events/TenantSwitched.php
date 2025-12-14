<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Events;

use Toporia\Framework\MultiTenancy\Contracts\TenantInterface;

/**
 * Class TenantSwitched
 *
 * Dispatched when tenant context is switched.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class TenantSwitched
{
    public function __construct(
        public readonly ?TenantInterface $newTenant,
        public readonly ?TenantInterface $previousTenant
    ) {}
}
