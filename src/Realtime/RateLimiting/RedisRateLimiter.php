<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\RateLimiting;

use Toporia\Framework\Realtime\Exceptions\RateLimitException;
use Redis;

/**
 * Class RedisRateLimiter
 *
 * Distributed rate limiter using Redis for multi-server environments.
 * Uses sliding window algorithm for accurate rate limiting across servers.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\RateLimiting
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RedisRateLimiter
{
    /**
     * @param Redis $redis Redis connection
     * @param int $maxMessages Maximum messages per window
     * @param int $windowSeconds Window size in seconds
     * @param bool $enabled Whether rate limiting is enabled
     * @param string $prefix Redis key prefix
     */
    public function __construct(
        private readonly Redis $redis,
        private readonly int $maxMessages = 60,
        private readonly int $windowSeconds = 60,
        private readonly bool $enabled = true,
        private readonly string $prefix = 'realtime:ratelimit'
    ) {}

    /**
     * Check if action is allowed and record it.
     *
     * Uses Redis sorted set with sliding window algorithm:
     * - Each action is a sorted set member with timestamp as score
     * - Remove expired actions outside window
     * - Count remaining actions
     * - Allow if under limit
     *
     * Performance: O(log N) where N = actions in window
     *
     * @param string $identifier Rate limit identifier (connection, channel, user)
     * @return bool True if allowed
     */
    public function attempt(string $identifier): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $key = "{$this->prefix}:{$identifier}";
        $now = microtime(true);
        $windowStart = $now - $this->windowSeconds;

        try {
            // Start pipeline for atomic operations
            $this->redis->multi();

            // Remove expired entries (outside window)
            $this->redis->zRemRangeByScore($key, '0', (string) $windowStart);

            // Count current entries in window
            $this->redis->zCard($key);

            // Execute pipeline
            $results = $this->redis->exec();
            $count = $results[1] ?? 0;

            // Check if limit exceeded
            if ($count >= $this->maxMessages) {
                return false;
            }

            // Add new entry (use unique ID to allow concurrent requests)
            $uniqueId = uniqid('', true);
            $this->redis->zAdd($key, $now, $uniqueId);

            // Set expiration (cleanup)
            $this->redis->expire($key, $this->windowSeconds * 2);

            return true;
        } catch (\RedisException $e) {
            // On Redis error, allow the request (fail open)
            // Log error for monitoring
            error_log("Redis rate limiter error: {$e->getMessage()}");
            return true;
        }
    }

    /**
     * Check if action is allowed, throw exception if not.
     *
     * @param string $identifier Rate limit identifier
     * @throws RateLimitException If rate limit exceeded
     */
    public function check(string $identifier): void
    {
        if (!$this->attempt($identifier)) {
            $retryAfter = $this->retryAfter($identifier);
            $current = $this->current($identifier);

            throw new RateLimitException(
                $identifier,
                $this->maxMessages,
                $current,
                $retryAfter
            );
        }
    }

    /**
     * Get remaining attempts for an identifier.
     *
     * @param string $identifier Rate limit identifier
     * @return int Remaining attempts
     */
    public function remaining(string $identifier): int
    {
        if (!$this->enabled) {
            return PHP_INT_MAX;
        }

        try {
            $count = $this->current($identifier);
            return max(0, $this->maxMessages - $count);
        } catch (\RedisException $e) {
            error_log("Redis rate limiter error: {$e->getMessage()}");
            return $this->maxMessages; // Fail open
        }
    }

    /**
     * Get current count for an identifier.
     *
     * @param string $identifier Rate limit identifier
     * @return int Current count
     */
    public function current(string $identifier): int
    {
        if (!$this->enabled) {
            return 0;
        }

        try {
            $key = "{$this->prefix}:{$identifier}";
            $now = microtime(true);
            $windowStart = $now - $this->windowSeconds;

            // Clean up expired entries first
            $this->redis->zRemRangeByScore($key, '0', (string) $windowStart);

            // Get count
            return (int) $this->redis->zCard($key);
        } catch (\RedisException $e) {
            error_log("Redis rate limiter error: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Get seconds until rate limit resets.
     *
     * @param string $identifier Rate limit identifier
     * @return int Seconds until reset
     */
    public function retryAfter(string $identifier): int
    {
        if (!$this->enabled) {
            return 0;
        }

        try {
            $key = "{$this->prefix}:{$identifier}";

            // Get oldest entry in window
            $oldest = $this->redis->zRange($key, 0, 0, ['withscores' => true]);

            if (empty($oldest)) {
                return 0;
            }

            $oldestTime = (float) reset($oldest);
            $windowEnd = $oldestTime + $this->windowSeconds;
            $now = microtime(true);

            return max(0, (int) ceil($windowEnd - $now));
        } catch (\RedisException $e) {
            error_log("Redis rate limiter error: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Reset rate limit for an identifier.
     *
     * @param string $identifier Rate limit identifier
     */
    public function reset(string $identifier): void
    {
        try {
            $key = "{$this->prefix}:{$identifier}";
            $this->redis->del($key);
        } catch (\RedisException $e) {
            error_log("Redis rate limiter error: {$e->getMessage()}");
        }
    }

    /**
     * Clear all rate limit data (use with caution).
     */
    public function clear(): void
    {
        try {
            $pattern = "{$this->prefix}:*";
            $keys = $this->redis->keys($pattern);

            if (!empty($keys)) {
                $this->redis->del($keys);
            }
        } catch (\RedisException $e) {
            error_log("Redis rate limiter error: {$e->getMessage()}");
        }
    }

    /**
     * Get rate limiter statistics.
     *
     * @param string $identifier Rate limit identifier
     * @return array{current: int, remaining: int, limit: int, retry_after: int}
     */
    public function stats(string $identifier): array
    {
        $current = $this->current($identifier);

        return [
            'current' => $current,
            'remaining' => max(0, $this->maxMessages - $current),
            'limit' => $this->maxMessages,
            'retry_after' => $this->retryAfter($identifier),
        ];
    }
}
