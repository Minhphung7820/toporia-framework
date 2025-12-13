<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Throttle;

use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class LoginThrottle
 *
 * Throttles authentication attempts to prevent brute force attacks.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Throttle
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class LoginThrottle
{
    /**
     * Default max attempts before lockout.
     */
    private const DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * Default decay time in seconds (1 minute).
     */
    private const DEFAULT_DECAY_SECONDS = 60;

    /**
     * @param CacheInterface $cache Cache for storing attempt counts
     * @param int $maxAttempts Maximum attempts before lockout
     * @param int $decaySeconds Time in seconds before attempts reset
     */
    public function __construct(
        private CacheInterface $cache,
        private int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private int $decaySeconds = self::DEFAULT_DECAY_SECONDS
    ) {}

    /**
     * Increment login attempts for an identifier.
     *
     * @param string $identifier Identifier (email, username, IP)
     * @return int New attempt count
     */
    public function incrementAttempts(string $identifier): int
    {
        $key = $this->getKey($identifier);
        $attempts = $this->cache->get($key, 0);
        $attempts++;

        $this->cache->set($key, $attempts, $this->decaySeconds);

        return $attempts;
    }

    /**
     * Get current attempt count for an identifier.
     *
     * @param string $identifier Identifier (email, username, IP)
     * @return int Current attempt count
     */
    public function getAttempts(string $identifier): int
    {
        $key = $this->getKey($identifier);
        return $this->cache->get($key, 0);
    }

    /**
     * Check if identifier is locked out.
     *
     * @param string $identifier Identifier (email, username, IP)
     * @return bool True if locked out
     */
    public function isLockedOut(string $identifier): bool
    {
        return $this->getAttempts($identifier) >= $this->maxAttempts;
    }

    /**
     * Get remaining attempts before lockout.
     *
     * @param string $identifier Identifier (email, username, IP)
     * @return int Remaining attempts (0 if locked out)
     */
    public function getRemainingAttempts(string $identifier): int
    {
        $attempts = $this->getAttempts($identifier);
        return max(0, $this->maxAttempts - $attempts);
    }

    /**
     * Get seconds until lockout expires.
     *
     * @param string $identifier Identifier (email, username, IP)
     * @return int Seconds until expiration (0 if not locked out)
     */
    public function getSecondsUntilUnlock(string $identifier): int
    {
        if (!$this->isLockedOut($identifier)) {
            return 0;
        }

        // Get TTL from cache (approximate)
        $key = $this->getKey($identifier);
        // Note: Most cache implementations don't expose TTL directly
        // This is an approximation - in production, store expiration timestamp
        return $this->decaySeconds;
    }

    /**
     * Clear login attempts for an identifier.
     *
     * @param string $identifier Identifier (email, username, IP)
     * @return void
     */
    public function clearAttempts(string $identifier): void
    {
        $key = $this->getKey($identifier);
        $this->cache->delete($key);
    }

    /**
     * Clear all login attempts (for testing/admin purposes).
     *
     * @return void
     */
    public function clearAllAttempts(): void
    {
        // Note: This requires cache to support pattern deletion
        // For now, this is a placeholder - implement based on cache driver
    }

    /**
     * Get cache key for identifier.
     *
     * @param string $identifier Identifier
     * @return string Cache key
     */
    private function getKey(string $identifier): string
    {
        return 'login_throttle:' . hash('sha256', $identifier);
    }
}
