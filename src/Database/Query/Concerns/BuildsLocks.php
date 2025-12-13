<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

/**
 * Trait BuildsLocks
 *
 * Pessimistic locking builders for Query Builder.
 * Provides Modern ORM row locking for concurrent access control.
 *
 * Features:
 * - FOR UPDATE (exclusive lock)
 * - LOCK IN SHARE MODE (shared lock)
 * - Transaction-based locking
 * - Lock timeout configuration
 * - NOWAIT and SKIP LOCKED support
 * - Deadlock detection and retry
 *
 * Performance:
 * - Prevents race conditions in concurrent transactions
 * - Use within transactions only
 * - May cause deadlocks if not careful
 * - Automatic retry on deadlock
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
trait BuildsLocks
{
    /**
     * Lock type for the query.
     *
     * @var string|null 'update' or 'shared'
     */
    private ?string $lock = null;

    /**
     * Lock timeout in seconds (0 = no timeout, null = database default).
     *
     * @var int|null
     */
    private ?int $lockTimeout = null;

    /**
     * Whether to use NOWAIT (fail immediately if lock cannot be acquired).
     *
     * @var bool
     */
    private bool $lockNowait = false;

    /**
     * Whether to use SKIP LOCKED (skip locked rows, continue with next available).
     *
     * @var bool
     */
    private bool $lockSkipLocked = false;

    /**
     * Maximum number of deadlock retries.
     *
     * @var int
     */
    private int $maxDeadlockRetries = 3;

    /**
     * Deadlock retry delay in microseconds (default: 100ms).
     *
     * @var int
     */
    private int $deadlockRetryDelay = 100000;

    /**
     * Add FOR UPDATE lock to the query.
     *
     * Locks selected rows for writing until the transaction commits.
     * Other transactions cannot read or write locked rows.
     *
     * Example:
     * ```php
     * DB::transaction(function() {
     *     $user = DB::table('users')
     *         ->where('id', 1)
     *         ->lockForUpdate()
     *         ->first();
     *
     *     // Update balance safely
     *     DB::table('users')
     *         ->where('id', 1)
     *         ->update(['balance' => $user['balance'] + 100]);
     * });
     * ```
     *
     * Use case:
     * - Preventing race conditions
     * - Implementing pessimistic locking
     * - Ensuring read-modify-write atomicity
     *
     * Performance:
     * - Blocks other transactions (can cause contention)
     * - Use only when necessary
     * - Keep transactions short
     *
     * IMPORTANT:
     * - Must be used within a transaction
     * - May cause deadlocks if multiple locks are acquired in different orders
     * - Not supported by all databases (fallbacks to no lock)
     *
     * @param int|null $timeout Lock timeout in seconds (null = database default, 0 = no timeout)
     * @return $this
     */
    public function lockForUpdate(?int $timeout = null): self
    {
        $this->lock = 'update';
        if ($timeout !== null) {
            $this->lockTimeout = $timeout;
        }
        return $this;
    }

    /**
     * Add FOR UPDATE lock with NOWAIT option.
     *
     * Fails immediately if lock cannot be acquired (no waiting).
     * Useful for non-blocking operations.
     *
     * Example:
     * ```php
     * try {
     *     $user = DB::table('users')
     *         ->where('id', 1)
     *         ->lockForUpdateNowait()
     *         ->first();
     * } catch (LockException $e) {
     *     // Lock could not be acquired
     * }
     * ```
     *
     * @return $this
     */
    public function lockForUpdateNowait(): self
    {
        $this->lock = 'update';
        $this->lockNowait = true;
        return $this;
    }

    /**
     * Add FOR UPDATE lock with SKIP LOCKED option.
     *
     * Skips locked rows and continues with next available row.
     * Useful for queue processing and batch operations.
     *
     * Example:
     * ```php
     * // Process next available job, skip if locked
     * $job = DB::table('jobs')
     *     ->where('status', 'pending')
     *     ->orderBy('created_at')
     *     ->lockForUpdateSkipLocked()
     *     ->first();
     * ```
     *
     * Database Support:
     * - PostgreSQL 9.5+: FOR UPDATE SKIP LOCKED
     * - MySQL 8.0+: FOR UPDATE SKIP LOCKED
     * - SQLite: Not supported (fallback to regular lock)
     *
     * @return $this
     */
    public function lockForUpdateSkipLocked(): self
    {
        $this->lock = 'update';
        $this->lockSkipLocked = true;
        return $this;
    }

    /**
     * Add LOCK IN SHARE MODE lock to the query.
     *
     * Locks selected rows for reading. Other transactions can read but cannot write.
     * Prevents the "read committed" isolation level anomaly.
     *
     * Example:
     * ```php
     * DB::transaction(function() {
     *     // Lock product for reading
     *     $product = DB::table('products')
     *         ->where('id', 1)
     *         ->sharedLock()
     *         ->first();
     *
     *     // Can read safely, others can also read but cannot update
     *     $canOrder = $product['stock'] > 0;
     * });
     * ```
     *
     * Use case:
     * - Ensuring consistent reads
     * - Preventing phantom reads
     * - Read-only pessimistic locking
     *
     * Performance:
     * - Less restrictive than FOR UPDATE
     * - Multiple transactions can hold shared locks
     * - Blocks writers only
     *
     * Database Support:
     * - MySQL/MariaDB: LOCK IN SHARE MODE
     * - PostgreSQL: FOR SHARE
     * - SQLite: Not supported (no-op)
     *
     * @param int|null $timeout Lock timeout in seconds
     * @return $this
     */
    public function sharedLock(?int $timeout = null): self
    {
        $this->lock = 'shared';
        if ($timeout !== null) {
            $this->lockTimeout = $timeout;
        }
        return $this;
    }

    /**
     * Set lock timeout for the current query.
     *
     * @param int $seconds Timeout in seconds (0 = no timeout)
     * @return $this
     */
    public function lockTimeout(int $seconds): self
    {
        $this->lockTimeout = $seconds;
        return $this;
    }

    /**
     * Set maximum number of deadlock retries.
     *
     * @param int $retries Maximum retries (default: 3)
     * @return $this
     */
    public function maxDeadlockRetries(int $retries): self
    {
        $this->maxDeadlockRetries = $retries;
        return $this;
    }

    /**
     * Set deadlock retry delay.
     *
     * @param int $microseconds Delay in microseconds (default: 100000 = 100ms)
     * @return $this
     */
    public function deadlockRetryDelay(int $microseconds): self
    {
        $this->deadlockRetryDelay = $microseconds;
        return $this;
    }

    /**
     * Get the lock type.
     *
     * @return string|null
     */
    protected function getLock(): ?string
    {
        return $this->lock;
    }

    /**
     * Get lock timeout.
     *
     * @return int|null
     */
    protected function getLockTimeout(): ?int
    {
        return $this->lockTimeout;
    }

    /**
     * Check if NOWAIT is enabled.
     *
     * @return bool
     */
    protected function isLockNowait(): bool
    {
        return $this->lockNowait;
    }

    /**
     * Check if SKIP LOCKED is enabled.
     *
     * @return bool
     */
    protected function isLockSkipLocked(): bool
    {
        return $this->lockSkipLocked;
    }

    /**
     * Get maximum deadlock retries.
     *
     * @return int
     */
    protected function getMaxDeadlockRetries(): int
    {
        return $this->maxDeadlockRetries;
    }

    /**
     * Get deadlock retry delay.
     *
     * @return int
     */
    protected function getDeadlockRetryDelay(): int
    {
        return $this->deadlockRetryDelay;
    }

    /**
     * Check if error is a deadlock.
     *
     * @param \Throwable $e Exception to check
     * @return bool
     */
    protected function isDeadlock(\Throwable $e): bool
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // MySQL deadlock error codes
        if ($code === 1213 || $code === 1205) {
            return true;
        }

        // PostgreSQL deadlock error codes
        if ($code === '40001' || $code === '40P01') {
            return true;
        }

        // Check message patterns
        $deadlockPatterns = [
            'deadlock',
            'deadlock detected',
            'lock wait timeout',
            'try restarting transaction',
        ];

        foreach ($deadlockPatterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if error is a lock timeout.
     *
     * @param \Throwable $e Exception to check
     * @return bool
     */
    protected function isLockTimeout(\Throwable $e): bool
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // MySQL lock timeout error code
        if ($code === 1205) {
            return true;
        }

        // PostgreSQL lock timeout
        if ($code === '55P03') {
            return true;
        }

        // Check message patterns
        $timeoutPatterns = [
            'lock wait timeout',
            'timeout',
            'lock acquisition timeout',
        ];

        foreach ($timeoutPatterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
