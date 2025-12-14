<?php

declare(strict_types=1);

namespace Toporia\Framework\Audit;

use DateTimeImmutable;
use Toporia\Framework\Audit\Contracts\AuditDriverInterface;
use Toporia\Framework\Audit\Contracts\AuditEntry;
use Toporia\Framework\Audit\Contracts\AuditableInterface;
use Toporia\Framework\MultiTenancy\TenantManager;

/**
 * Class AuditManager
 *
 * Central manager for audit logging operations.
 * Handles audit context (user, request), batch operations, and driver management.
 *
 * Usage:
 *   // Via container
 *   $audit = app(AuditManager::class);
 *   $audit->record($model, 'updated', $old, $new);
 *
 *   // Via helper
 *   audit()->record($model, 'created', [], $attributes);
 *
 *   // Get history
 *   $history = audit()->getHistory(UserModel::class, $userId);
 *
 *   // Batch operations
 *   audit()->batch(function ($audit) {
 *       // Multiple operations...
 *   });
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class AuditManager
{
    /**
     * Resolved driver instances.
     *
     * @var array<string, AuditDriverInterface>
     */
    private array $drivers = [];

    /**
     * Current audit context (user, IP, etc.).
     *
     * @var array<string, mixed>
     */
    private array $context = [];

    /**
     * Batch mode entries.
     *
     * @var array<AuditEntry>|null
     */
    private ?array $batchEntries = null;

    /**
     * Global enabled state.
     */
    private bool $enabled = true;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = []
    ) {}

    /**
     * Check if auditing is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->config['enabled'] ?? true;
    }

    /**
     * Enable auditing globally.
     *
     * @return void
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable auditing globally.
     *
     * @return void
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Execute callback without auditing.
     *
     * @param callable $callback
     * @return mixed
     */
    public function withoutAuditing(callable $callback): mixed
    {
        $wasEnabled = $this->enabled;
        $this->enabled = false;

        try {
            return $callback();
        } finally {
            $this->enabled = $wasEnabled;
        }
    }

    /**
     * Set audit context (user, IP, etc.).
     *
     * @param array<string, mixed> $context
     * @return void
     */
    public function setContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }

    /**
     * Get current audit context.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set user context.
     *
     * @param int|string|null $userId
     * @param string|null $userName
     * @return void
     */
    public function setUser(int|string|null $userId, ?string $userName = null): void
    {
        $this->context['user_id'] = $userId;
        $this->context['user_name'] = $userName;
    }

    /**
     * Set request context.
     *
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @param string|null $url
     * @return void
     */
    public function setRequest(?string $ipAddress, ?string $userAgent = null, ?string $url = null): void
    {
        $this->context['ip_address'] = $ipAddress;
        $this->context['user_agent'] = $userAgent;
        $this->context['url'] = $url;
    }

    /**
     * Record an audit entry.
     *
     * @param AuditableInterface|object $model
     * @param string $event
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     * @param array<string, mixed> $metadata
     * @return AuditEntry|null
     */
    public function record(
        object $model,
        string $event,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = []
    ): ?AuditEntry {
        if (!$this->isEnabled()) {
            return null;
        }

        // Get model information
        $modelType = get_class($model);
        $modelId = $this->getModelId($model);

        // Get custom data if model implements AuditableInterface
        if ($model instanceof AuditableInterface) {
            $metadata = array_merge($model->getAuditCustomData(), $metadata);
        }

        // Build entry
        $entry = new AuditEntry(
            event: $event,
            modelType: $modelType,
            modelId: $modelId,
            oldValues: $oldValues,
            newValues: $newValues,
            userId: $this->context['user_id'] ?? null,
            userName: $this->context['user_name'] ?? null,
            ipAddress: $this->context['ip_address'] ?? null,
            userAgent: $this->context['user_agent'] ?? null,
            url: $this->context['url'] ?? null,
            tenantId: $this->getTenantId(),
            metadata: $metadata,
            timestamp: new DateTimeImmutable(),
            id: $this->generateId()
        );

        // Batch mode: queue entry
        if ($this->batchEntries !== null) {
            $this->batchEntries[] = $entry;
            return $entry;
        }

        // Store immediately
        $this->store($entry);

        return $entry;
    }

    /**
     * Execute callback in batch mode.
     * All entries are collected and stored at once.
     *
     * @param callable $callback
     * @return array<AuditEntry>
     */
    public function batch(callable $callback): array
    {
        $this->batchEntries = [];

        try {
            $callback($this);

            $entries = $this->batchEntries;
            $this->batchEntries = null;

            // Store all entries
            if (!empty($entries)) {
                $this->storeBatch($entries);
            }

            return $entries;
        } catch (\Throwable $e) {
            $this->batchEntries = null;
            throw $e;
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
        return $this->driver()->getHistory($modelType, $modelId, $limit);
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
        return $this->driver()->getByUser($userId, $limit);
    }

    /**
     * Get the default driver instance.
     *
     * @param string|null $name
     * @return AuditDriverInterface
     */
    public function driver(?string $name = null): AuditDriverInterface
    {
        $name = $name ?? $this->getDefaultDriver();

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        $this->drivers[$name] = $this->createDriver($name);

        return $this->drivers[$name];
    }

    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->config['default'] ?? 'database';
    }

    /**
     * Create a driver instance.
     *
     * @param string $name
     * @return AuditDriverInterface
     */
    protected function createDriver(string $name): AuditDriverInterface
    {
        $driverConfig = $this->config['drivers'][$name] ?? [];
        $driverClass = $driverConfig['driver'] ?? null;

        if ($driverClass === null) {
            // Default drivers
            $driverClass = match ($name) {
                'database' => Drivers\DatabaseDriver::class,
                'file' => Drivers\FileDriver::class,
                default => throw new \InvalidArgumentException("Audit driver [{$name}] not configured.")
            };
        }

        // Try container first
        if (function_exists('app')) {
            $container = app();
            if ($container->has($driverClass)) {
                return $container->make($driverClass, ['config' => $driverConfig]);
            }
        }

        return new $driverClass($driverConfig);
    }

    /**
     * Store a single audit entry.
     *
     * @param AuditEntry $entry
     * @return void
     */
    protected function store(AuditEntry $entry): void
    {
        $this->driver()->store($entry);
    }

    /**
     * Store multiple audit entries.
     *
     * @param array<AuditEntry> $entries
     * @return void
     */
    protected function storeBatch(array $entries): void
    {
        $this->driver()->storeBatch($entries);
    }

    /**
     * Get model primary key.
     *
     * @param object $model
     * @return int|string
     */
    protected function getModelId(object $model): int|string
    {
        if (method_exists($model, 'getKey')) {
            return $model->getKey();
        }

        if (method_exists($model, 'getId')) {
            return $model->getId();
        }

        if (property_exists($model, 'id')) {
            return $model->id;
        }

        return spl_object_id($model);
    }

    /**
     * Get current tenant ID if multi-tenancy is enabled.
     *
     * @return int|string|null
     */
    protected function getTenantId(): int|string|null
    {
        if (!class_exists(TenantManager::class)) {
            return null;
        }

        return TenantManager::id();
    }

    /**
     * Generate unique audit ID.
     *
     * @return string
     */
    protected function generateId(): string
    {
        return sprintf(
            '%s-%s-%s',
            date('Ymd'),
            bin2hex(random_bytes(4)),
            substr((string) hrtime(true), -6)
        );
    }
}
