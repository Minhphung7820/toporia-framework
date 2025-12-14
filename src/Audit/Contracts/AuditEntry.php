<?php

declare(strict_types=1);

namespace Toporia\Framework\Audit\Contracts;

use DateTimeImmutable;

/**
 * Class AuditEntry
 *
 * Immutable value object representing an audit log entry.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class AuditEntry
{
    /**
     * @param string $event Event type (created, updated, deleted, restored, etc.)
     * @param string $modelType Model class name
     * @param int|string $modelId Model primary key
     * @param array<string, mixed> $oldValues Previous values (for update/delete)
     * @param array<string, mixed> $newValues New values (for create/update)
     * @param int|string|null $userId User who made the change
     * @param string|null $userName User name for display
     * @param string|null $ipAddress Request IP address
     * @param string|null $userAgent Request user agent
     * @param string|null $url Request URL
     * @param int|string|null $tenantId Tenant ID if multi-tenant
     * @param array<string, mixed> $metadata Additional metadata
     * @param DateTimeImmutable $timestamp When the event occurred
     * @param string|null $id Unique identifier
     */
    public function __construct(
        public readonly string $event,
        public readonly string $modelType,
        public readonly int|string $modelId,
        public readonly array $oldValues = [],
        public readonly array $newValues = [],
        public readonly int|string|null $userId = null,
        public readonly ?string $userName = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $url = null,
        public readonly int|string|null $tenantId = null,
        public readonly array $metadata = [],
        public readonly DateTimeImmutable $timestamp = new DateTimeImmutable(),
        public readonly ?string $id = null
    ) {}

    /**
     * Get changed attributes.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function getChanges(): array
    {
        $changes = [];

        // Combine all keys
        $allKeys = array_unique(array_merge(
            array_keys($this->oldValues),
            array_keys($this->newValues)
        ));

        foreach ($allKeys as $key) {
            $old = $this->oldValues[$key] ?? null;
            $new = $this->newValues[$key] ?? null;

            if ($old !== $new) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * Check if specific attribute was changed.
     *
     * @param string $attribute
     * @return bool
     */
    public function wasChanged(string $attribute): bool
    {
        $old = $this->oldValues[$attribute] ?? null;
        $new = $this->newValues[$attribute] ?? null;

        return $old !== $new;
    }

    /**
     * Get model short name.
     *
     * @return string
     */
    public function getModelShortName(): string
    {
        $parts = explode('\\', $this->modelType);
        return end($parts);
    }

    /**
     * Convert to array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'model_type' => $this->modelType,
            'model_id' => $this->modelId,
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'url' => $this->url,
            'tenant_id' => $this->tenantId,
            'metadata' => $this->metadata,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s.u'),
        ];
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            event: $data['event'],
            modelType: $data['model_type'],
            modelId: $data['model_id'],
            oldValues: $data['old_values'] ?? [],
            newValues: $data['new_values'] ?? [],
            userId: $data['user_id'] ?? null,
            userName: $data['user_name'] ?? null,
            ipAddress: $data['ip_address'] ?? null,
            userAgent: $data['user_agent'] ?? null,
            url: $data['url'] ?? null,
            tenantId: $data['tenant_id'] ?? null,
            metadata: $data['metadata'] ?? [],
            timestamp: isset($data['timestamp'])
                ? new DateTimeImmutable($data['timestamp'])
                : new DateTimeImmutable(),
            id: $data['id'] ?? null
        );
    }
}
