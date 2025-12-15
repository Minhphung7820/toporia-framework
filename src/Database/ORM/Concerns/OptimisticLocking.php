<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Database\ORM\Exceptions\StaleObjectException;

/**
 * Trait OptimisticLocking
 *
 * Provides optimistic locking for models using version column.
 * Prevents lost updates in concurrent scenarios without database-level locks.
 *
 * Features:
 * - Version-based optimistic locking
 * - Automatic version increment on update
 * - StaleObjectException on version mismatch
 * - Configurable version column name
 * - Automatic retry on conflict
 *
 * Performance:
 * - No database locks (better concurrency)
 * - Minimal overhead (single column check)
 * - Works well for low-contention scenarios
 * - Automatic retry with exponential backoff
 *
 * Use Cases:
 * - High-concurrency read-heavy workloads
 * - When pessimistic locking causes too much contention
 * - Distributed systems where locks are expensive
 * - When you want to detect and handle conflicts gracefully
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\ORM\Concerns
 * @since       2025-01-22
 *
 * @link        https://github.com/Minhphung485/toporia
 */
trait OptimisticLocking
{
    /**
     * Version column name (default: 'version').
     *
     * @var string
     */
    protected static string $versionColumn = 'version';

    /**
     * Maximum number of retries on version conflict.
     *
     * @var int
     */
    protected static int $maxOptimisticRetries = 3;

    /**
     * Retry delay in microseconds (default: 50ms).
     *
     * @var int
     */
    protected static int $optimisticRetryDelay = 50000;

    /**
     * Whether optimistic locking is enabled for this model.
     *
     * @var bool
     */
    protected static bool $optimisticLockingEnabled = true;

    /**
     * Get the version column name.
     *
     * @return string
     */
    public static function getVersionColumn(): string
    {
        return static::$versionColumn;
    }

    /**
     * Enable or disable optimistic locking.
     *
     * @param bool $enabled
     * @return void
     */
    public static function enableOptimisticLocking(bool $enabled = true): void
    {
        static::$optimisticLockingEnabled = $enabled;
    }

    /**
     * Check if optimistic locking is enabled.
     *
     * @return bool
     */
    public static function usesOptimisticLocking(): bool
    {
        return static::$optimisticLockingEnabled;
    }

    /**
     * Get the current version value.
     *
     * @return int|null
     */
    public function getVersion(): ?int
    {
        return $this->getAttribute(static::$versionColumn);
    }

    /**
     * Increment version for next update.
     *
     * @return void
     */
    protected function incrementVersion(): void
    {
        if (!static::usesOptimisticLocking()) {
            return;
        }

        $currentVersion = $this->getVersion() ?? 0;
        $this->setAttribute(static::$versionColumn, $currentVersion + 1);
    }

    /**
     * Save the model with optimistic locking.
     *
     * Automatically checks version before update and increments on success.
     * Throws StaleObjectException if version mismatch detected.
     *
     * @param array<string, mixed> $options
     * @return bool
     * @throws \Toporia\Framework\Database\ORM\Exceptions\StaleObjectException
     */
    public function saveWithOptimisticLock(array $options = []): bool
    {
        if (!$this->exists || !static::usesOptimisticLocking()) {
            // For new records, initialize version to 1
            if (!$this->exists && !$this->getVersion()) {
                $this->setAttribute(static::$versionColumn, 1);
            }
            // For new records, use normal save flow
            // This will be handled by Model::save() -> performInsert()
            // We just ensure version is set
            return true;
        }

        // Fire saving event (before create or update)
        if (method_exists($this, 'fireEvent')) {
            if ($this->fireEvent('saving') === false) {
                return false;
            }

            // Fire updating event (can cancel)
            if ($this->fireEvent('updating') === false) {
                return false;
            }
        }

        // Update timestamp if enabled
        if (property_exists(static::class, 'timestamps') && isset(static::$timestamps) && static::$timestamps) {
            $this->setAttribute('updated_at', now()->toDateTimeString());
        }

        $originalVersion = $this->getOriginal(static::$versionColumn) ?? 0;
        $this->incrementVersion();
        $dirty = $this->getDirty();

        // Update with version check using compare-and-swap
        $updated = static::query()
            ->where(static::getPrimaryKey(), $this->getKey())
            ->where(static::$versionColumn, $originalVersion)
            ->update($dirty);

        if ($updated === 0) {
            // Version mismatch - object was modified by another transaction
            // Refresh to get latest version
            $this->refresh();

            throw new StaleObjectException(
                sprintf(
                    'The model [%s] with ID [%s] was modified by another transaction. ' .
                    'Current version: %d, Expected version: %d',
                    static::class,
                    $this->getKey(),
                    $this->getVersion(),
                    $originalVersion
                ),
                $this
            );
        }

        $this->syncOriginal();

        // Fire events if available
        if (method_exists($this, 'fireEvent')) {
            // Fire updated event
            $this->fireEvent('updated');

            // Fire saved event (after create or update)
            $this->fireEvent('saved');
        }

        return true;
    }

    /**
     * Save with automatic retry on version conflict.
     *
     * Automatically retries on StaleObjectException with exponential backoff.
     *
     * @param int $maxRetries Maximum number of retries
     * @param callable|null $onConflict Callback called on each retry
     * @return bool
     * @throws \Toporia\Framework\Database\ORM\Exceptions\StaleObjectException If all retries exhausted
     */
    public function saveWithRetry(int $maxRetries = null, ?callable $onConflict = null): bool
    {
        $maxRetries = $maxRetries ?? static::$maxOptimisticRetries;
        $retryDelay = static::$optimisticRetryDelay;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->saveWithOptimisticLock();
            } catch (StaleObjectException $e) {
                if ($attempt >= $maxRetries) {
                    throw $e;
                }

                // Call conflict handler if provided
                if ($onConflict !== null) {
                    $onConflict($this, $attempt, $e);
                }

                // Refresh model to get latest version
                $this->refresh();

                // Exponential backoff: delay * 2^attempt
                $delay = $retryDelay * (2 ** $attempt);
                usleep($delay);
            }
        }

        return false;
    }

    /**
     * Update model with optimistic locking check.
     *
     * @param array<string, mixed> $attributes
     * @return bool
     */
    public function updateWithOptimisticLock(array $attributes): bool
    {
        $this->fill($attributes);
        return $this->saveWithOptimisticLock();
    }
}

