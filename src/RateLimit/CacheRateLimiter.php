<?php

declare(strict_types=1);

namespace Toporia\Framework\RateLimit;

use Toporia\Framework\RateLimit\Contracts\RateLimiterInterface;
use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class CacheRateLimiter
 *
 * Uses cache backend for rate limiting with fixed window algorithm.
 * Works with any cache driver (File, Redis, Memory).
 *
 * Rate Limiting Behavior:
 * - Fixed Window: Reset time is fixed when limit is first reached
 * - No penalty accumulation: Spamming after rate limit doesn't increase wait time
 * - Reset time decreases naturally: "Try again in 60s" → "Try again in 50s" → etc.
 *
 * To enable "sliding window" (reset timer on each violation):
 * - Uncomment the code in tooManyAttempts() method
 * - This will reset/extend reset time each time rate limit is exceeded
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  RateLimit
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class CacheRateLimiter implements RateLimiterInterface
{
    /**
     * Default decay time in seconds (used as fallback when decay unknown).
     */
    private const DEFAULT_DECAY_SECONDS = 60;

    /**
     * @var bool Whether to reset timer on each violation (sliding window behavior)
     * If true: Each spam request extends reset time
     * If false: Reset time is fixed (current behavior)
     */
    private bool $resetOnViolation = false;

    /**
     * Request-level cache to avoid duplicate cache calls within the same request.
     * This significantly improves performance by caching resetTime and attempts.
     *
     * @var array<string, array{resetTime: int|null, attempts: int|null, availableIn: int|null}>
     */
    private array $requestCache = [];

    public function __construct(
        private CacheInterface $cache,
        bool $resetOnViolation = false
    ) {
        $this->resetOnViolation = $resetOnViolation;
    }

    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        // Check if rate limit exceeded (this will cache results in requestCache)
        if ($this->tooManyAttempts($key, $maxAttempts, $decaySeconds)) {
            return false;
        }

        // Increment attempts and return true
        $this->hit($key, $decaySeconds);

        // Invalidate request cache for this key since we just incremented
        $this->clearRequestCache($key);

        return true;
    }

    public function tooManyAttempts(string $key, int $maxAttempts, ?int $decaySeconds = null): bool
    {
        // Validate input
        if ($maxAttempts <= 0) {
            return false; // No limit if maxAttempts <= 0
        }

        $currentTime = now()->getTimestamp();

        // Get resetTime from cache or request cache
        $resetTime = $this->getResetTime($key);

        // If resetTime has expired or doesn't exist, attempts should be 0
        if ($resetTime === null) {
            // ResetTime doesn't exist - check if attempts still exist
            $attempts = $this->getAttempts($key);
            if ($attempts > 0) {
                // Attempts exist without resetTime - inconsistent state, reset to be safe
                $this->resetAttempts($key);
                $this->clearRequestCache($key);
            }
            return false; // No rate limit active
        }

        // Check if resetTime timestamp has expired (regardless of cache TTL)
        if ($resetTime < $currentTime) {
            // ResetTime timestamp expired - reset everything
            $this->resetAttempts($key);
            $this->clearRequestCache($key);
            return false; // No rate limit active
        }

        // ResetTime is valid - check attempts (only once, reuse from cache)
        $attempts = $this->getAttempts($key);
        $exceeded = $attempts >= $maxAttempts;

        // If rate limit exceeded, ensure resetTime is set properly
        // When limit is FIRST exceeded, reset resetTime to NOW + decaySeconds
        if ($exceeded) {
            $decay = $decaySeconds ?? self::DEFAULT_DECAY_SECONDS;
            $this->ensureResetTime($key, $resetTime, $currentTime, $decay);
            // Invalidate cache since we may have updated resetTime
            $this->clearRequestCache($key);
        }

        return $exceeded;
    }

    /**
     * Ensure reset time is set when rate limit is exceeded.
     *
     * When rate limit is FIRST exceeded, reset the resetTime to current time + decaySeconds.
     * This ensures users wait the full decay period from the moment they exceed the limit,
     * not from when the window started.
     *
     * @param string $key
     * @param int|null $existingResetTime
     * @param int $currentTime
     * @param int|null $decaySeconds Optional decay seconds to use
     * @return void
     */
    private function ensureResetTime(string $key, ?int $existingResetTime, int $currentTime, ?int $decaySeconds = null): void
    {
        $decay = $decaySeconds ?? self::DEFAULT_DECAY_SECONDS;

        // CRITICAL: If resetTime doesn't exist or expired, set new one
        if ($existingResetTime === null || $existingResetTime < $currentTime) {
            $this->cache->set(
                $this->resetTimeKey($key),
                $currentTime + $decay,
                $decay
            );
            // Set flag to track that resetTime was set when exceeded
            $this->cache->set($this->resetFlagKey($key), true, $decay);
            return;
        }

        // CRITICAL: When rate limit is FIRST exceeded, resetTime may have been set from window start
        // (e.g., 20 seconds ago). We need to reset it to NOW + decaySeconds to ensure
        // user waits the FULL decay period from when they exceed, not from window start.

        // Check if we already reset when exceeded (using a flag)
        $resetFlagKey = $this->resetFlagKey($key);
        $alreadyResetWhenExceeded = $this->cache->get($resetFlagKey);

        // If already reset when exceeded, don't reset again
        // This prevents resetting every time tooManyAttempts() is called
        if ($alreadyResetWhenExceeded) {
            return; // Already reset, keep current resetTime
        }

        // This is the FIRST time rate limit is exceeded
        // Reset resetTime to NOW + decaySeconds to ensure full 60s wait from now
        $this->cache->set(
            $this->resetTimeKey($key),
            $currentTime + $decay,
            $decay
        );
        // Set flag to indicate we already reset when exceeding
        $this->cache->set($resetFlagKey, true, $decay);
    }

    public function attempts(string $key): int
    {
        return $this->getAttempts($key);
    }

    /**
     * Get attempts count with request-level caching.
     *
     * @param string $key
     * @return int
     */
    private function getAttempts(string $key): int
    {
        // Check request cache first
        if (isset($this->requestCache[$key]['attempts'])) {
            return $this->requestCache[$key]['attempts'];
        }

        $currentTime = now()->getTimestamp();
        $resetTime = $this->getResetTime($key);

        // If resetTime doesn't exist, rate limit window has expired
        if ($resetTime === null) {
            // Check if attempts still exist (stale data)
            $attempts = (int) $this->cache->get($this->attemptsKey($key), 0);
            if ($attempts > 0) {
                // Stale attempts exist without resetTime - clean up
                $this->cache->delete($this->attemptsKey($key));
            }
            $this->requestCache[$key]['attempts'] = 0;
            return 0;
        }

        // If resetTime has expired (timestamp in the past), attempts should be 0
        if ($resetTime < $currentTime) {
            // Reset time expired - clear attempts and return 0
            $this->cache->delete($this->attemptsKey($key));
            $this->cache->delete($this->resetTimeKey($key));
            $this->requestCache[$key]['attempts'] = 0;
            return 0;
        }

        // ResetTime is valid - get attempts count from cache
        $attempts = (int) $this->cache->get($this->attemptsKey($key), 0);

        // Cache in request cache
        $this->requestCache[$key]['attempts'] = $attempts;

        return $attempts;
    }

    /**
     * Get resetTime with request-level caching.
     *
     * @param string $key
     * @return int|null
     */
    private function getResetTime(string $key): ?int
    {
        // Check request cache first
        if (isset($this->requestCache[$key]['resetTime'])) {
            return $this->requestCache[$key]['resetTime'];
        }

        // Get from cache
        $resetTime = $this->cache->get($this->resetTimeKey($key));

        // Cache in request cache (even if null)
        $this->requestCache[$key]['resetTime'] = $resetTime;

        return $resetTime;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        $attempts = $this->getAttempts($key);
        return max(0, $maxAttempts - $attempts);
    }

    public function availableIn(string $key, ?int $decaySeconds = null): int
    {
        // Check request cache first
        if (isset($this->requestCache[$key]['availableIn'])) {
            return $this->requestCache[$key]['availableIn'];
        }

        $currentTime = now()->getTimestamp();
        $resetTime = $this->getResetTime($key);

        // If resetTime exists, check if it's still valid
        if ($resetTime !== null) {
            // Check if resetTime timestamp has expired
            if ($resetTime < $currentTime) {
                // ResetTime expired - rate limit is no longer active
                $this->resetAttempts($key);
                $this->clearRequestCache($key);
                return 0;
            }

            // ResetTime is valid - return remaining time
            $remaining = max(0, $resetTime - $currentTime);
            $result = $remaining > 0 ? $remaining : 0;

            // Cache result
            $this->requestCache[$key]['availableIn'] = $result;

            return $result;
        }

        // ResetTime doesn't exist - check if attempts still exist
        $attempts = $this->getAttempts($key);

        if ($attempts > 0) {
            // Attempts exist but no resetTime - inconsistent state, reset
            $this->resetAttempts($key);
            $this->clearRequestCache($key);
            return 0;
        }

        // No attempts and no reset time - rate limit is not active
        $this->requestCache[$key]['availableIn'] = 0;
        return 0;
    }

    public function clear(string $key): void
    {
        $this->resetAttempts($key);
    }

    public function resetAttempts(string $key): void
    {
        $this->cache->delete($this->attemptsKey($key));
        $this->cache->delete($this->resetTimeKey($key));
        $this->cache->delete($this->resetFlagKey($key));
        $this->clearRequestCache($key);
    }

    /**
     * Increment the hit counter
     *
     * @param string $key
     * @param int $decaySeconds
     * @return int New attempt count
     */
    public function hit(string $key, int $decaySeconds = self::DEFAULT_DECAY_SECONDS): int
    {
        $attemptsKey = $this->attemptsKey($key);
        $resetTimeKey = $this->resetTimeKey($key);
        $currentTime = now()->getTimestamp();

        // Use getResetTime() to leverage request cache
        $resetTime = $this->getResetTime($key);
        $needsReset = ($resetTime !== null && $resetTime < $currentTime);

        if ($needsReset) {
            // Reset time expired - reset everything and start fresh
            // CRITICAL: Delete attempts BEFORE setting new resetTime
            $this->cache->delete($attemptsKey);
            $this->cache->delete($resetTimeKey);
        }

        // Set new reset time if not exists or expired
        if ($resetTime === null || $needsReset) {
            $newResetTime = $currentTime + $decaySeconds;
            // CRITICAL: Set resetTime with TTL matching decaySeconds
            // This ensures both resetTime and attempts expire at the same time
            $this->cache->set($resetTimeKey, $newResetTime, $decaySeconds);
            // Update request cache
            $this->requestCache[$key]['resetTime'] = $newResetTime;
        }

        // If reset was needed, start fresh at 1 (don't increment from old value)
        if ($needsReset) {
            // CRITICAL: Set attempts to 1 directly with SAME TTL as resetTime
            // This ensures attempts and resetTime expire together
            $this->cache->set($attemptsKey, 1, $decaySeconds);
            $this->requestCache[$key]['attempts'] = 1;
            return 1;
        }

        // Increment attempts (only if resetTime is still valid)
        $attempts = $this->cache->increment($attemptsKey, 1);

        if ($attempts === false) {
            // Cache key doesn't exist - start fresh at 1
            $this->cache->set($attemptsKey, 1, $decaySeconds);
            $this->requestCache[$key]['attempts'] = 1;
            return 1;
        }

        // Update request cache with new attempts count
        $this->requestCache[$key]['attempts'] = $attempts;

        return $attempts;
    }

    /**
     * Clear request-level cache for a key.
     *
     * @param string $key
     * @return void
     */
    private function clearRequestCache(string $key): void
    {
        unset($this->requestCache[$key]);
    }

    /**
     * Get the cache key for attempts counter
     *
     * @param string $key
     * @return string
     */
    private function attemptsKey(string $key): string
    {
        return "rate_limit:{$key}:attempts";
    }

    /**
     * Get the cache key for reset time
     *
     * @param string $key
     * @return string
     */
    private function resetTimeKey(string $key): string
    {
        return "rate_limit:{$key}:reset";
    }

    /**
     * Get the cache key for reset flag (tracks if resetTime was reset when exceeding)
     *
     * @param string $key
     * @return string
     */
    private function resetFlagKey(string $key): string
    {
        return "rate_limit:{$key}:reset_flag";
    }
}
