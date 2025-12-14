<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy\Events;

use Toporia\Framework\Http\Request;

/**
 * Class TenantNotFound
 *
 * Dispatched when tenant resolution fails.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class TenantNotFound
{
    public function __construct(
        public readonly Request $request
    ) {}
}
