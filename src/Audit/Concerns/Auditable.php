<?php

declare(strict_types=1);

namespace Toporia\Framework\Audit\Concerns;

use Toporia\Framework\Audit\AuditManager;
use Toporia\Framework\Audit\Contracts\AuditEntry;

/**
 * Trait Auditable
 *
 * Add audit logging capabilities to Eloquent-style models.
 * Automatically tracks created, updated, deleted, and restored events.
 *
 * Usage:
 *   class UserModel extends Model implements AuditableInterface
 *   {
 *       use Auditable;
 *
 *       // Optional: Specify which attributes to audit (whitelist)
 *       protected array $auditInclude = ['name', 'email', 'role'];
 *
 *       // Optional: Attributes to exclude from audit (blacklist)
 *       protected array $auditExclude = ['password', 'remember_token'];
 *
 *       // Optional: Disable auditing temporarily
 *       protected bool $auditingEnabled = true;
 *   }
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
trait Auditable
{
    /**
     * Temporarily disable auditing flag.
     */
    protected bool $auditingDisabled = false;

    /**
     * Boot the Auditable trait.
     * Registers model observers for audit events.
     *
     * @return void
     */
    public static function bootAuditable(): void
    {
        // Created event
        static::created(function ($model) {
            $model->auditCreated();
        });

        // Updated event
        static::updated(function ($model) {
            $model->auditUpdated();
        });

        // Deleted event
        static::deleted(function ($model) {
            $model->auditDeleted();
        });

        // Restored event (for soft deletes)
        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->auditRestored();
            });
        }
    }

    /**
     * Get attributes to include in audit (whitelist).
     *
     * @return array<string>
     */
    public function getAuditInclude(): array
    {
        return $this->auditInclude ?? [];
    }

    /**
     * Get attributes to exclude from audit (blacklist).
     *
     * @return array<string>
     */
    public function getAuditExclude(): array
    {
        return $this->auditExclude ?? ['password', 'remember_token', 'api_token'];
    }

    /**
     * Get custom audit data to merge with entry.
     *
     * @return array<string, mixed>
     */
    public function getAuditCustomData(): array
    {
        if (method_exists($this, 'customAuditData')) {
            return $this->customAuditData();
        }

        return [];
    }

    /**
     * Check if auditing is enabled for this model.
     *
     * @return bool
     */
    public function isAuditingEnabled(): bool
    {
        if ($this->auditingDisabled) {
            return false;
        }

        return $this->auditingEnabled ?? true;
    }

    /**
     * Disable auditing for this model instance.
     *
     * @return static
     */
    public function disableAuditing(): static
    {
        $this->auditingDisabled = true;
        return $this;
    }

    /**
     * Enable auditing for this model instance.
     *
     * @return static
     */
    public function enableAuditing(): static
    {
        $this->auditingDisabled = false;
        return $this;
    }

    /**
     * Execute callback without auditing.
     *
     * @param callable $callback
     * @return mixed
     */
    public function withoutAuditing(callable $callback): mixed
    {
        $this->disableAuditing();

        try {
            return $callback($this);
        } finally {
            $this->enableAuditing();
        }
    }

    /**
     * Audit model creation.
     *
     * @return void
     */
    protected function auditCreated(): void
    {
        if (!$this->shouldAudit()) {
            return;
        }

        $this->recordAudit('created', [], $this->getAuditableAttributes());
    }

    /**
     * Audit model update.
     *
     * @return void
     */
    protected function auditUpdated(): void
    {
        if (!$this->shouldAudit()) {
            return;
        }

        $changes = $this->getAuditChanges();

        if (empty($changes['old']) && empty($changes['new'])) {
            return; // No auditable changes
        }

        $this->recordAudit('updated', $changes['old'], $changes['new']);
    }

    /**
     * Audit model deletion.
     *
     * @return void
     */
    protected function auditDeleted(): void
    {
        if (!$this->shouldAudit()) {
            return;
        }

        $this->recordAudit('deleted', $this->getAuditableAttributes(), []);
    }

    /**
     * Audit model restoration.
     *
     * @return void
     */
    protected function auditRestored(): void
    {
        if (!$this->shouldAudit()) {
            return;
        }

        $this->recordAudit('restored', [], $this->getAuditableAttributes());
    }

    /**
     * Record an audit entry.
     *
     * @param string $event
     * @param array $oldValues
     * @param array $newValues
     * @return void
     */
    protected function recordAudit(string $event, array $oldValues, array $newValues): void
    {
        $manager = $this->getAuditManager();

        if ($manager === null) {
            return;
        }

        $manager->record($this, $event, $oldValues, $newValues);
    }

    /**
     * Check if audit should be performed.
     *
     * @return bool
     */
    protected function shouldAudit(): bool
    {
        if (!$this->isAuditingEnabled()) {
            return false;
        }

        $manager = $this->getAuditManager();

        return $manager !== null && $manager->isEnabled();
    }

    /**
     * Get auditable attributes (filtered by include/exclude).
     *
     * @return array<string, mixed>
     */
    protected function getAuditableAttributes(): array
    {
        $attributes = $this->getAttributes();

        return $this->filterAuditAttributes($attributes);
    }

    /**
     * Get audit changes (old and new values).
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    protected function getAuditChanges(): array
    {
        $dirty = $this->getDirty();
        $original = $this->getOriginal();

        $oldValues = [];
        $newValues = [];

        foreach ($dirty as $key => $newValue) {
            if (!$this->isAuditableAttribute($key)) {
                continue;
            }

            $oldValues[$key] = $original[$key] ?? null;
            $newValues[$key] = $newValue;
        }

        return [
            'old' => $oldValues,
            'new' => $newValues,
        ];
    }

    /**
     * Filter attributes based on include/exclude rules.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function filterAuditAttributes(array $attributes): array
    {
        $include = $this->getAuditInclude();
        $exclude = $this->getAuditExclude();

        // If whitelist is defined, use only those attributes
        if (!empty($include)) {
            $attributes = array_intersect_key($attributes, array_flip($include));
        }

        // Remove excluded attributes
        foreach ($exclude as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }

    /**
     * Check if attribute should be audited.
     *
     * @param string $attribute
     * @return bool
     */
    protected function isAuditableAttribute(string $attribute): bool
    {
        $include = $this->getAuditInclude();
        $exclude = $this->getAuditExclude();

        // Check whitelist first
        if (!empty($include) && !in_array($attribute, $include, true)) {
            return false;
        }

        // Check blacklist
        if (in_array($attribute, $exclude, true)) {
            return false;
        }

        return true;
    }

    /**
     * Get audit history for this model.
     *
     * @param int $limit
     * @return array<AuditEntry>
     */
    public function getAuditHistory(int $limit = 50): array
    {
        $manager = $this->getAuditManager();

        if ($manager === null) {
            return [];
        }

        return $manager->getHistory(
            static::class,
            $this->getKey(),
            $limit
        );
    }

    /**
     * Get AuditManager instance.
     *
     * @return AuditManager|null
     */
    protected function getAuditManager(): ?AuditManager
    {
        if (!function_exists('app')) {
            return null;
        }

        $container = app();

        if ($container->has(AuditManager::class)) {
            return $container->make(AuditManager::class);
        }

        return null;
    }

    /**
     * Get model attributes.
     * Override if model doesn't have this method.
     *
     * @return array<string, mixed>
     */
    abstract public function getAttributes(): array;

    /**
     * Get dirty (changed) attributes.
     *
     * @return array<string, mixed>
     */
    abstract public function getDirty(): array;

    /**
     * Get original attributes before changes.
     *
     * @return array<string, mixed>
     */
    abstract public function getOriginal(): array;

    /**
     * Get model primary key.
     *
     * @return mixed
     */
    abstract public function getKey(): mixed;
}
