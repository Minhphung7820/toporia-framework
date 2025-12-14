<?php

declare(strict_types=1);

namespace Toporia\Framework\Audit\Drivers;

use DateTimeImmutable;
use Toporia\Framework\Audit\Contracts\AuditDriverInterface;
use Toporia\Framework\Audit\Contracts\AuditEntry;
use Toporia\Framework\Database\Connection;

/**
 * Class DatabaseDriver
 *
 * Database storage driver for audit logs.
 * Uses batch inserts for high performance.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class DatabaseDriver implements AuditDriverInterface
{
    private ?Connection $connection = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = []
    ) {}

    /**
     * Store a single audit entry.
     *
     * @param AuditEntry $entry
     * @return void
     */
    public function store(AuditEntry $entry): void
    {
        $this->getConnection()->table($this->getTable())->insert(
            $this->entryToRow($entry)
        );
    }

    /**
     * Store multiple audit entries (batch insert).
     *
     * @param array<AuditEntry> $entries
     * @return void
     */
    public function storeBatch(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $rows = array_map(
            fn(AuditEntry $entry) => $this->entryToRow($entry),
            $entries
        );

        // Use chunk insert for large batches
        $chunkSize = $this->config['batch_size'] ?? 1000;
        $chunks = array_chunk($rows, $chunkSize);

        foreach ($chunks as $chunk) {
            $this->getConnection()->table($this->getTable())->insert($chunk);
        }
    }

    /**
     * Get audit history for a model.
     *
     * @param string $modelType
     * @param int|string $modelId
     * @param int $limit
     * @return array<AuditEntry>
     */
    public function getHistory(string $modelType, int|string $modelId, int $limit = 50): array
    {
        $rows = $this->getConnection()
            ->table($this->getTable())
            ->where('auditable_type', '=', $modelType)
            ->where('auditable_id', '=', (string) $modelId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();

        return array_map(
            fn(array $row) => $this->rowToEntry($row),
            $rows
        );
    }

    /**
     * Get audit entries by user.
     *
     * @param int|string $userId
     * @param int $limit
     * @return array<AuditEntry>
     */
    public function getByUser(int|string $userId, int $limit = 50): array
    {
        $rows = $this->getConnection()
            ->table($this->getTable())
            ->where('user_id', '=', (string) $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();

        return array_map(
            fn(array $row) => $this->rowToEntry($row),
            $rows
        );
    }

    /**
     * Convert AuditEntry to database row.
     *
     * @param AuditEntry $entry
     * @return array<string, mixed>
     */
    protected function entryToRow(AuditEntry $entry): array
    {
        return [
            'uuid' => $entry->id,
            'event' => $entry->event,
            'auditable_type' => $entry->modelType,
            'auditable_id' => (string) $entry->modelId,
            'old_values' => json_encode($entry->oldValues, JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode($entry->newValues, JSON_UNESCAPED_UNICODE),
            'user_id' => $entry->userId !== null ? (string) $entry->userId : null,
            'user_name' => $entry->userName,
            'ip_address' => $entry->ipAddress,
            'user_agent' => $entry->userAgent,
            'url' => $entry->url,
            'tenant_id' => $entry->tenantId !== null ? (string) $entry->tenantId : null,
            'metadata' => json_encode($entry->metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => $entry->timestamp->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Convert database row to AuditEntry.
     *
     * @param array<string, mixed> $row
     * @return AuditEntry
     */
    protected function rowToEntry(array $row): AuditEntry
    {
        return new AuditEntry(
            event: $row['event'],
            modelType: $row['auditable_type'],
            modelId: $row['auditable_id'],
            oldValues: $this->decodeJson($row['old_values'] ?? null),
            newValues: $this->decodeJson($row['new_values'] ?? null),
            userId: $row['user_id'],
            userName: $row['user_name'] ?? null,
            ipAddress: $row['ip_address'] ?? null,
            userAgent: $row['user_agent'] ?? null,
            url: $row['url'] ?? null,
            tenantId: $row['tenant_id'] ?? null,
            metadata: $this->decodeJson($row['metadata'] ?? null),
            timestamp: new DateTimeImmutable($row['created_at']),
            id: $row['uuid']
        );
    }

    /**
     * Decode JSON string.
     *
     * @param string|null $json
     * @return array<string, mixed>
     */
    protected function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get table name.
     *
     * @return string
     */
    protected function getTable(): string
    {
        return $this->config['table'] ?? 'audit_logs';
    }

    /**
     * Get database connection.
     *
     * @return Connection
     */
    protected function getConnection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        // Try container
        if (function_exists('app')) {
            $container = app();

            // Specific connection
            $connectionName = $this->config['connection'] ?? null;

            if ($connectionName !== null && $container->has("db.connection.{$connectionName}")) {
                $this->connection = $container->make("db.connection.{$connectionName}");
                return $this->connection;
            }

            // Default connection
            if ($container->has(Connection::class)) {
                $this->connection = $container->make(Connection::class);
                return $this->connection;
            }

            if ($container->has('db')) {
                $this->connection = $container->make('db');
                return $this->connection;
            }
        }

        throw new \RuntimeException('Database connection not available for audit logging.');
    }
}
