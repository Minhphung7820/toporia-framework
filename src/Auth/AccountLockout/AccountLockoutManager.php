<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\AccountLockout;

use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class AccountLockoutManager
 *
 * Manages account lockout after too many failed login attempts.
 * More severe than login throttling - locks the account itself, not just IP.
 *
 * Features:
 * - Progressive lockout duration (exponential backoff)
 * - Permanent lockout after max violations
 * - Manual unlock capability
 * - Lockout history tracking
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\AccountLockout
 * @since       2025-01-15
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class AccountLockoutManager
{
    /**
     * Max failed attempts before lockout.
     */
    private const MAX_ATTEMPTS = 10;

    /**
     * Initial lockout duration in seconds (5 minutes).
     */
    private const INITIAL_LOCKOUT = 300;

    /**
     * Max violations before permanent lockout.
     */
    private const MAX_VIOLATIONS = 5;

    /**
     * Lockout history retention in seconds (30 days).
     */
    private const HISTORY_RETENTION = 2592000;

    public function __construct(
        private CacheInterface $cache
    ) {}

    /**
     * Record a failed login attempt.
     *
     * @param string $identifier User identifier (email, username, ID)
     * @return void
     */
    public function recordFailedAttempt(string $identifier): void
    {
        $key = $this->getAttemptsKey($identifier);
        $attempts = $this->cache->get($key, 0);

        $this->cache->put($key, $attempts + 1, 3600); // 1 hour expiry

        // Check if should lock account
        if ($attempts + 1 >= self::MAX_ATTEMPTS) {
            $this->lockAccount($identifier);
        }
    }

    /**
     * Lock an account.
     *
     * @param string $identifier User identifier
     * @return void
     */
    public function lockAccount(string $identifier): void
    {
        $violations = $this->getViolationCount($identifier);

        // Check for permanent lockout
        if ($violations >= self::MAX_VIOLATIONS) {
            $this->permanentlyLockAccount($identifier);
            return;
        }

        // Calculate lockout duration (exponential backoff)
        $duration = $this->calculateLockoutDuration($violations);

        // Set lockout
        $lockKey = $this->getLockKey($identifier);
        $this->cache->put($lockKey, [
            'locked_at' => time(),
            'duration' => $duration,
            'violation' => $violations + 1,
            'reason' => 'Too many failed login attempts',
        ], $duration);

        // Increment violation count
        $this->incrementViolationCount($identifier);

        // Clear failed attempts
        $this->clearFailedAttempts($identifier);
    }

    /**
     * Permanently lock an account.
     *
     * @param string $identifier User identifier
     * @return void
     */
    public function permanentlyLockAccount(string $identifier): void
    {
        $lockKey = $this->getLockKey($identifier);
        $this->cache->forever($lockKey, [
            'locked_at' => time(),
            'duration' => null,
            'violation' => self::MAX_VIOLATIONS,
            'reason' => 'Permanent lockout due to repeated violations',
            'permanent' => true,
        ]);
    }

    /**
     * Check if account is locked.
     *
     * @param string $identifier User identifier
     * @return bool
     */
    public function isLocked(string $identifier): bool
    {
        return $this->cache->has($this->getLockKey($identifier));
    }

    /**
     * Get lockout details.
     *
     * @param string $identifier User identifier
     * @return array{locked_at: int, duration: int|null, violation: int, reason: string, permanent: bool}|null
     */
    public function getLockoutDetails(string $identifier): ?array
    {
        $lockKey = $this->getLockKey($identifier);
        $details = $this->cache->get($lockKey);

        if (!is_array($details)) {
            return null;
        }

        return $details;
    }

    /**
     * Get seconds until unlock.
     *
     * @param string $identifier User identifier
     * @return int|null Seconds until unlock, null if not locked or permanent
     */
    public function getSecondsUntilUnlock(string $identifier): ?int
    {
        $details = $this->getLockoutDetails($identifier);

        if ($details === null) {
            return null;
        }

        if ($details['permanent'] ?? false) {
            return null; // Permanent lockout
        }

        $elapsed = time() - $details['locked_at'];
        $remaining = $details['duration'] - $elapsed;

        return max(0, $remaining);
    }

    /**
     * Manually unlock an account.
     *
     * @param string $identifier User identifier
     * @return void
     */
    public function unlock(string $identifier): void
    {
        $this->cache->forget($this->getLockKey($identifier));
        $this->clearFailedAttempts($identifier);
    }

    /**
     * Clear failed attempts after successful login.
     *
     * @param string $identifier User identifier
     * @return void
     */
    public function clearFailedAttempts(string $identifier): void
    {
        $this->cache->forget($this->getAttemptsKey($identifier));
    }

    /**
     * Get failed attempt count.
     *
     * @param string $identifier User identifier
     * @return int
     */
    public function getFailedAttempts(string $identifier): int
    {
        return $this->cache->get($this->getAttemptsKey($identifier), 0);
    }

    /**
     * Get violation count (number of times account has been locked).
     *
     * @param string $identifier User identifier
     * @return int
     */
    public function getViolationCount(string $identifier): int
    {
        $key = $this->getViolationKey($identifier);
        return $this->cache->get($key, 0);
    }

    /**
     * Increment violation count.
     *
     * @param string $identifier User identifier
     * @return void
     */
    private function incrementViolationCount(string $identifier): void
    {
        $key = $this->getViolationKey($identifier);
        $count = $this->cache->get($key, 0);
        $this->cache->put($key, $count + 1, self::HISTORY_RETENTION);
    }

    /**
     * Calculate lockout duration based on violation count (exponential backoff).
     *
     * Violations:
     * 1st: 5 minutes
     * 2nd: 15 minutes
     * 3rd: 45 minutes
     * 4th: 2 hours
     * 5th+: Permanent
     *
     * @param int $violations Number of previous violations
     * @return int Duration in seconds
     */
    private function calculateLockoutDuration(int $violations): int
    {
        // Exponential backoff: base * (3 ^ violations)
        return self::INITIAL_LOCKOUT * (3 ** $violations);
    }

    /**
     * Get cache key for failed attempts.
     *
     * @param string $identifier
     * @return string
     */
    private function getAttemptsKey(string $identifier): string
    {
        return 'account_lockout:attempts:' . hash('sha256', $identifier);
    }

    /**
     * Get cache key for account lock.
     *
     * @param string $identifier
     * @return string
     */
    private function getLockKey(string $identifier): string
    {
        return 'account_lockout:lock:' . hash('sha256', $identifier);
    }

    /**
     * Get cache key for violation count.
     *
     * @param string $identifier
     * @return string
     */
    private function getViolationKey(string $identifier): string
    {
        return 'account_lockout:violations:' . hash('sha256', $identifier);
    }
}
