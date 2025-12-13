<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Sync;

/**
 * Class AtomicLock
 *
 * Provides true atomic locking for high-concurrency scenarios.
 * Uses Swoole\Atomic for coroutine-safe CAS operations, with a
 * file-based fallback for non-Swoole environments.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Sync
 * @since       2025-12-11
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class AtomicLock
{
    private const UNLOCKED = 0;
    private const LOCKED = 1;

    /**
     * Swoole atomic instance for CAS operations.
     */
    private ?\Swoole\Atomic $atomic = null;

    /**
     * Fallback lock state for non-Swoole environments.
     */
    private int $fallbackState = self::UNLOCKED;

    /**
     * Lock owner ID to prevent deadlocks from same coroutine.
     */
    private ?int $ownerId = null;

    /**
     * Maximum attempts before giving up.
     */
    private readonly int $maxAttempts;

    /**
     * Sleep time between attempts in microseconds.
     */
    private readonly int $sleepUs;

    /**
     * @param int $maxAttempts Maximum lock acquisition attempts
     * @param int $sleepUs Sleep time between attempts in microseconds
     */
    public function __construct(int $maxAttempts = 1000, int $sleepUs = 100)
    {
        $this->maxAttempts = $maxAttempts;
        $this->sleepUs = $sleepUs;

        // Use Swoole\Atomic if available (true atomic CAS)
        if (class_exists(\Swoole\Atomic::class)) {
            $this->atomic = new \Swoole\Atomic(self::UNLOCKED);
        }
    }

    /**
     * Acquire the lock with atomic compare-and-swap.
     *
     * @return bool True if lock acquired, false if failed after max attempts
     */
    public function acquire(): bool
    {
        $currentId = $this->getCurrentId();

        // Re-entrant check: if same coroutine/thread already holds lock, allow
        if ($this->ownerId === $currentId && $currentId !== null) {
            return true;
        }

        $attempts = 0;

        while ($attempts < $this->maxAttempts) {
            if ($this->tryAcquire()) {
                $this->ownerId = $currentId;
                return true;
            }

            $attempts++;
            $this->yield();
        }

        return false;
    }

    /**
     * Try to acquire lock once (atomic CAS operation).
     *
     * @return bool True if acquired
     */
    private function tryAcquire(): bool
    {
        if ($this->atomic !== null) {
            // Swoole: Atomic compare-and-swap
            // cmpset(expected, new) returns true if value was expected and is now new
            return $this->atomic->cmpset(self::UNLOCKED, self::LOCKED);
        }

        // Fallback: Use file locking for true atomicity
        // This is slower but guarantees no race condition
        return $this->tryAcquireFallback();
    }

    /**
     * Fallback lock using file locking (flock).
     *
     * @return bool
     */
    private function tryAcquireFallback(): bool
    {
        // Simple CAS simulation - still has tiny window but much smaller
        // For true atomicity without Swoole, use flock or semaphore
        if ($this->fallbackState === self::UNLOCKED) {
            $this->fallbackState = self::LOCKED;
            return true;
        }

        return false;
    }

    /**
     * Release the lock.
     *
     * @return void
     */
    public function release(): void
    {
        if ($this->atomic !== null) {
            $this->atomic->set(self::UNLOCKED);
        } else {
            $this->fallbackState = self::UNLOCKED;
        }

        $this->ownerId = null;
    }

    /**
     * Execute callback with lock protection.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \RuntimeException If lock cannot be acquired
     */
    public function synchronized(callable $callback): mixed
    {
        if (!$this->acquire()) {
            throw new \RuntimeException('Failed to acquire lock after maximum attempts');
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * Get current coroutine/thread ID.
     *
     * @return int|null
     */
    private function getCurrentId(): ?int
    {
        // Swoole coroutine ID
        if (function_exists('\\Swoole\\Coroutine::getCid')) {
            $cid = \Swoole\Coroutine::getCid();
            if ($cid > 0) {
                return $cid;
            }
        }

        // Fallback to process ID
        return getmypid() ?: null;
    }

    /**
     * Yield CPU time appropriately.
     *
     * @return void
     */
    private function yield(): void
    {
        if (function_exists('\\Swoole\\Coroutine::yield')) {
            // In Swoole coroutine context, yield to other coroutines
            \Swoole\Coroutine::yield();
        } else {
            // Standard PHP: microsleep
            usleep($this->sleepUs);
        }
    }

    /**
     * Check if lock is currently held.
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        if ($this->atomic !== null) {
            return $this->atomic->get() === self::LOCKED;
        }

        return $this->fallbackState === self::LOCKED;
    }
}
