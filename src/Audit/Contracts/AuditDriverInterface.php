<?php

declare(strict_types=1);

namespace Toporia\Framework\Audit\Contracts;

/**
 * Interface AuditDriverInterface
 *
 * Contract for audit storage drivers.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
interface AuditDriverInterface
{
    /**
     * Store an audit log entry.
     *
     * @param AuditEntry $entry
     * @return bool
     */
    public function store(AuditEntry $entry): bool;

    /**
     * Store multiple audit entries.
     *
     * @param array<AuditEntry> $entries
     * @return int Number of entries stored
     */
    public function storeBatch(array $entries): int;

    /**
     * Get audit history for a model.
     *
     * @param string $modelType
     * @param int|string $modelId
     * @param int $limit
     * @param int $offset
     * @return array<AuditEntry>
     */
    public function getHistory(string $modelType, int|string $modelId, int $limit = 50, int $offset = 0): array;

    /**
     * Get audit history by user.
     *
     * @param int|string $userId
     * @param int $limit
     * @param int $offset
     * @return array<AuditEntry>
     */
    public function getByUser(int|string $userId, int $limit = 50, int $offset = 0): array;

    /**
     * Get driver name.
     *
     * @return string
     */
    public function getName(): string;
}
