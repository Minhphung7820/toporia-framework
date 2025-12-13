<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

use Toporia\Framework\Database\Contracts\ConnectionInterface;

/**
 * Trait RaceConditionProtection
 *
 * Comprehensive race condition protection for QueryBuilder.
 * Provides atomic operations, deadlock handling, and retry mechanisms.
 *
 * Features:
 * - Atomic increment/decrement operations
 * - Compare-and-swap (CAS) operations
 * - Deadlock detection and automatic retry
 * - Lock timeout handling
 * - Transaction-safe operations
 * - Optimistic locking support
 *
 * Performance:
 * - Minimal overhead for non-contended operations
 * - Automatic retry with exponential backoff
 * - Deadlock detection and recovery
 * - Efficient atomic operations
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Query\Concerns
 * @since       2025-01-22
 *
 * @link        https://github.com/Minhphung485/toporia
 */
trait RaceConditionProtection
{
    /**
     * Execute query with automatic deadlock retry.
     *
     * Automatically retries on deadlock with exponential backoff.
     *
     * @param callable $callback Query execution callback
     * @param int|null $maxRetries Maximum retries (null = use default)
     * @return mixed Query result
     * @throws \Throwable If all retries exhausted or non-deadlock error
     */
    protected function executeWithDeadlockRetry(callable $callback, ?int $maxRetries = null)
    {
        $maxRetries = $maxRetries ?? $this->getMaxDeadlockRetries();
        $retryDelay = $this->getDeadlockRetryDelay();

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                if (!$this->isDeadlock($e) || $attempt >= $maxRetries) {
                    throw $e;
                }

                // Exponential backoff: delay * 2^attempt
                $delay = $retryDelay * (2 ** $attempt);
                usleep($delay);
            }
        }

        throw new \RuntimeException('Maximum deadlock retries exceeded');
    }

    /**
     * Atomically increment a column value.
     *
     * Thread-safe increment operation that prevents race conditions.
     * Uses database-level atomic operations (no read-modify-write).
     *
     * Example:
     * ```php
     * // Increment view count atomically
     * DB::table('articles')
     *     ->where('id', 1)
     *     ->atomicIncrement('views');
     *
     * // Increment by custom amount
     * DB::table('products')
     *     ->where('id', 1)
     *     ->atomicIncrement('stock', 5);
     * ```
     *
     * Performance:
     * - Single database operation (no SELECT needed)
     * - Atomic at database level
     * - No race conditions possible
     *
     * @param string $column Column to increment
     * @param int|float $amount Amount to increment (default: 1)
     * @return int Number of affected rows
     */
    public function atomicIncrement(string $column, int|float $amount = 1): int
    {
        $driver = $this->getConnection()->getDriverName();
        $table = $this->getTable();

        if ($table === null) {
            throw new \RuntimeException('Table not set for atomic increment');
        }

        $wheres = $this->compileWheres();
        $bindings = $this->getBindings();

        // Build atomic increment query
        $sql = match ($driver) {
            'mysql', 'pgsql' => "UPDATE {$table} SET {$column} = {$column} + ? {$wheres}",
            'sqlite' => "UPDATE {$table} SET {$column} = {$column} + ? {$wheres}",
            default => throw new \RuntimeException("Unsupported driver: {$driver}")
        };

        // Prepend increment amount to bindings
        array_unshift($bindings, $amount);

        return $this->executeWithDeadlockRetry(function () use ($sql, $bindings) {
            $statement = $this->getConnection()->execute($sql, $bindings);
            return $statement->rowCount();
        });
    }

    /**
     * Atomically decrement a column value.
     *
     * Thread-safe decrement operation that prevents race conditions.
     *
     * Example:
     * ```php
     * // Decrement stock atomically
     * DB::table('products')
     *     ->where('id', 1)
     *     ->atomicDecrement('stock');
     *
     * // Decrement by custom amount
     * DB::table('accounts')
     *     ->where('id', 1)
     *     ->atomicDecrement('balance', 100.50);
     * ```
     *
     * @param string $column Column to decrement
     * @param int|float $amount Amount to decrement (default: 1)
     * @return int Number of affected rows
     */
    public function atomicDecrement(string $column, int|float $amount = 1): int
    {
        return $this->atomicIncrement($column, -$amount);
    }

    /**
     * Compare-and-swap (CAS) operation.
     *
     * Atomically updates a column only if current value matches expected value.
     * Returns true if update succeeded, false if value changed.
     *
     * Example:
     * ```php
     * // Update balance only if current balance is 100
     * $success = DB::table('accounts')
     *     ->where('id', 1)
     *     ->compareAndSwap('balance', 100, 150);
     *
     * if (!$success) {
     *     // Balance was changed by another transaction
     * }
     * ```
     *
     * Performance:
     * - Single atomic database operation
     * - No race conditions
     * - Useful for optimistic locking
     *
     * @param string $column Column to update
     * @param mixed $expected Expected current value
     * @param mixed $new New value to set
     * @return bool True if update succeeded, false otherwise
     */
    public function compareAndSwap(string $column, mixed $expected, mixed $new): bool
    {
        $table = $this->getTable();

        if ($table === null) {
            throw new \RuntimeException('Table not set for compare-and-swap');
        }

        $wheres = $this->compileWheres();
        $bindings = $this->getBindings();

        // Add expected value to WHERE clause
        $casWhere = " AND {$column} = ?";
        $sql = "UPDATE {$table} SET {$column} = ? {$wheres}{$casWhere}";

        // Add new value and expected value to bindings
        $casBindings = array_merge([$new], $bindings, [$expected]);

        $affected = $this->executeWithDeadlockRetry(function () use ($sql, $casBindings) {
            $statement = $this->getConnection()->execute($sql, $casBindings);
            return $statement->rowCount();
        });

        return $affected > 0;
    }

    /**
     * Atomic update with condition check.
     *
     * Updates a column only if condition is met (e.g., stock > 0).
     * Useful for preventing negative values or enforcing business rules.
     *
     * Example:
     * ```php
     * // Decrement stock only if stock > 0
     * $updated = DB::table('products')
     *     ->where('id', 1)
     *     ->atomicUpdateIf('stock', 'stock - 1', 'stock > 0');
     *
     * if ($updated) {
     *     // Stock decremented successfully
     * }
     * ```
     *
     * @param string $column Column to update
     * @param string $expression Update expression (e.g., 'stock - 1')
     * @param string $condition WHERE condition (e.g., 'stock > 0')
     * @return int Number of affected rows
     */
    public function atomicUpdateIf(string $column, string $expression, string $condition): int
    {
        $table = $this->getTable();

        if ($table === null) {
            throw new \RuntimeException('Table not set for atomic update');
        }

        $wheres = $this->compileWheres();
        $bindings = $this->getBindings();

        $sql = "UPDATE {$table} SET {$column} = {$expression} {$wheres} AND ({$condition})";

        return $this->executeWithDeadlockRetry(function () use ($sql, $bindings) {
            $statement = $this->getConnection()->execute($sql, $bindings);
            return $statement->rowCount();
        });
    }

    /**
     * Get connection instance.
     *
     * @return ConnectionInterface
     */
    abstract protected function getConnection(): ConnectionInterface;

    /**
     * Get table name.
     *
     * @return string|null
     */
    abstract public function getTable(): ?string;

    /**
     * Compile WHERE clauses.
     *
     * @return string
     */
    abstract protected function compileWheres(): string;

    /**
     * Get query bindings.
     *
     * @return array
     */
    abstract public function getBindings(): array;
}
