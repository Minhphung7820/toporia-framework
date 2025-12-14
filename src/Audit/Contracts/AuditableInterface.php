<?php

declare(strict_types=1);

namespace Toporia\Framework\Audit\Contracts;

/**
 * Interface AuditableInterface
 *
 * Contract for models that should be audited.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
interface AuditableInterface
{
    /**
     * Get attributes to include in audit.
     * Return empty array to include all.
     *
     * @return array<string>
     */
    public function getAuditInclude(): array;

    /**
     * Get attributes to exclude from audit.
     *
     * @return array<string>
     */
    public function getAuditExclude(): array;

    /**
     * Get custom audit data.
     *
     * @return array<string, mixed>
     */
    public function getAuditCustomData(): array;

    /**
     * Check if auditing is enabled for this model.
     *
     * @return bool
     */
    public function isAuditingEnabled(): bool;
}
