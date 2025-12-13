<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

use Toporia\Framework\Database\Contracts\ConnectionInterface;

/**
 * Trait HasRaceConditionProtection
 *
 * Provides race condition protection methods for models.
 * Integrates QueryBuilder race condition features with Model API.
 *
 * Features:
 * - Atomic increment/decrement on model instances
 * - Pessimistic locking helpers
 * - Transaction-safe operations
 * - Deadlock retry support
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
trait HasRaceConditionProtection
{
    /**
     * Atomically increment a column value for this model instance.
     *
     * Example:
     * ```php
     * $product = ProductModel::find(1);
     * $product->incrementAtomic('views'); // Thread-safe increment
     * ```
     *
     * @param string $column Column to increment
     * @param int|float $amount Amount to increment (default: 1)
     * @return bool True if successful
     */
    public function incrementAtomic(string $column, int|float $amount = 1): bool
    {
        if (!$this->exists) {
            throw new \RuntimeException('Cannot increment on non-existent model');
        }

        $affected = static::query()
            ->where(static::getPrimaryKey(), $this->getKey())
            ->atomicIncrement($column, $amount);

        if ($affected > 0) {
            // Refresh model to get updated value
            $this->refresh();
            return true;
        }

        return false;
    }

    /**
     * Atomically decrement a column value for this model instance.
     *
     * @param string $column Column to decrement
     * @param int|float $amount Amount to decrement (default: 1)
     * @return bool True if successful
     */
    public function decrementAtomic(string $column, int|float $amount = 1): bool
    {
        return $this->incrementAtomic($column, -$amount);
    }

    /**
     * Lock this model instance for update.
     *
     * Reloads the model with FOR UPDATE lock within a transaction.
     *
     * Example:
     * ```php
     * DB::transaction(function() use ($product) {
     *     $product->lockForUpdate();
     *     // Now safe to modify
     *     $product->stock -= 1;
     *     $product->save();
     * });
     * ```
     *
     * @return $this
     * @throws \RuntimeException If not in transaction
     */
    public function lockForUpdate(): self
    {
        $connection = static::getConnection();
        if (!$connection->inTransaction()) {
            throw new \RuntimeException('lockForUpdate() must be called within a transaction');
        }

        $locked = static::query()
            ->where(static::getPrimaryKey(), $this->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked) {
            $this->attributes = $locked->attributes;
            $this->syncOriginal();
        }

        return $this;
    }

    /**
     * Lock this model instance for shared read.
     *
     * @return $this
     * @throws \RuntimeException If not in transaction
     */
    public function sharedLock(): self
    {
        $connection = static::getConnection();
        if (!$connection->inTransaction()) {
            throw new \RuntimeException('sharedLock() must be called within a transaction');
        }

        $locked = static::query()
            ->where(static::getPrimaryKey(), $this->getKey())
            ->sharedLock()
            ->first();

        if ($locked) {
            $this->attributes = $locked->attributes;
            $this->syncOriginal();
        }

        return $this;
    }

    /**
     * Update model with compare-and-swap operation.
     *
     * @param string $column Column to update
     * @param mixed $expected Expected current value
     * @param mixed $new New value
     * @return bool True if update succeeded
     */
    public function compareAndSwap(string $column, mixed $expected, mixed $new): bool
    {
        if (!$this->exists) {
            throw new \RuntimeException('Cannot perform CAS on non-existent model');
        }

        $success = static::query()
            ->where(static::getPrimaryKey(), $this->getKey())
            ->compareAndSwap($column, $expected, $new);

        if ($success) {
            $this->setAttribute($column, $new);
            $this->syncOriginal();
        }

        return $success;
    }
}

