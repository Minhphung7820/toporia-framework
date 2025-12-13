<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\RateLimiting;

use Toporia\Framework\Realtime\Exceptions\RateLimitException;
use Redis;

/**
 * Sliding Window Rate Limiter
 *
 * Implements Sliding Window Log algorithm for rate limiting.
 *
 * Algorithm:
 * - Store timestamp of each request in sorted set
 * - Remove requests outside current window
 * - Count requests in window
 * - Allow if under limit
 *
 * Advantages:
 * - Most accurate rate limiting
 * - No edge case issues (unlike fixed window)
 * - Smooth distribution
 *
 * Disadvantages:
 * - Higher memory usage (stores all timestamps)
 * - Slightly slower than fixed window
 *
 * Best for: Premium features, critical operations, compliance
 *
 * Performance: ~2-3ms per check (Redis) or <0.2ms (memory)
 * Memory: O(limit) per identifier
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\RateLimiting
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SlidingWindowRateLimiter implements RateLimiterInterface
{
    /**
     * In-memory state for local limiter.
     *
     * @var array<string, array<float>>
     */
    private array $windows = [];

    /**
     * Lua script for atomic sliding window operations.
     */
    private const LUA_SCRIPT = <<<'LUA'
local key = KEYS[1]
local limit = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local cost = tonumber(ARGV[3])
local now = tonumber(ARGV[4])
local window_start = now - window

-- Remove expired entries
redis.call('ZREMRANGEBYSCORE', key, 0, window_start)

-- Get current count
local count = redis.call('ZCARD', key)

-- Check if adding this request would exceed limit
if count + cost <= limit then
    -- Add new entries
    for i = 1, cost do
        local unique_id = now .. ':' .. i .. ':' .. math.random()
        redis.call('ZADD', key, now, unique_id)
    end
    redis.call('EXPIRE', key, math.ceil(window * 2))
    return {1, count + cost}
else
    -- Limit exceeded
    return {0, count}
end
LUA;

    /**
     * @param Redis|null $redis Redis connection (null = in-memory)
     * @param int $limit Maximum requests per window
     * @param int $windowSeconds Window size in seconds
     * @param bool $enabled Whether rate limiting is enabled
     * @param string $prefix Redis key prefix
     */
    public function __construct(
        private readonly ?Redis $redis = null,
        private readonly int $limit = 60,
        private readonly int $windowSeconds = 60,
        private readonly bool $enabled = true,
        private readonly string $prefix = 'realtime:ratelimit:sliding'
    ) {}

    /**
     * {@inheritdoc}
     */
    public function attempt(string $identifier, int $cost = 1): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if ($this->redis !== null) {
            return $this->attemptRedis($identifier, $cost);
        }

        return $this->attemptMemory($identifier, $cost);
    }

    /**
     * Attempt using Redis (distributed).
     *
     * @param string $identifier
     * @param int $cost
     * @return bool
     */
    private function attemptRedis(string $identifier, int $cost): bool
    {
        try {
            $key = "{$this->prefix}:{$identifier}";
            $now = microtime(true);

            $result = $this->redis->eval(
                self::LUA_SCRIPT,
                [$key, $this->limit, $this->windowSeconds, $cost, $now],
                1
            );

            return $result[0] === 1;
        } catch (\RedisException $e) {
            error_log("Redis sliding window error: {$e->getMessage()}");
            return true; // Fail open
        }
    }

    /**
     * Attempt using in-memory storage.
     *
     * @param string $identifier
     * @param int $cost
     * @return bool
     */
    private function attemptMemory(string $identifier, int $cost): bool
    {
        $now = microtime(true);
        $windowStart = $now - $this->windowSeconds;

        // Initialize window if not exists
        if (!isset($this->windows[$identifier])) {
            $this->windows[$identifier] = [];
        }

        $window = &$this->windows[$identifier];

        // Remove expired entries
        $window = array_filter($window, fn($timestamp) => $timestamp > $windowStart);

        // Check if adding this request would exceed limit
        if (count($window) + $cost <= $this->limit) {
            // Add new entries
            for ($i = 0; $i < $cost; $i++) {
                $window[] = $now;
            }
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function check(string $identifier, int $cost = 1): void
    {
        if (!$this->attempt($identifier, $cost)) {
            throw new RateLimitException(
                $identifier,
                $this->limit,
                $this->current($identifier),
                $this->retryAfter($identifier)
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function remaining(string $identifier): int
    {
        if (!$this->enabled) {
            return PHP_INT_MAX;
        }

        $current = $this->current($identifier);
        return max(0, $this->limit - $current);
    }

    /**
     * Get current count for identifier.
     *
     * @param string $identifier
     * @return int
     */
    private function current(string $identifier): int
    {
        if ($this->redis !== null) {
            return $this->currentRedis($identifier);
        }

        return $this->currentMemory($identifier);
    }

    /**
     * Get current count from Redis.
     *
     * @param string $identifier
     * @return int
     */
    private function currentRedis(string $identifier): int
    {
        try {
            $key = "{$this->prefix}:{$identifier}";
            $now = microtime(true);
            $windowStart = $now - $this->windowSeconds;

            // Remove expired entries
            $this->redis->zRemRangeByScore($key, 0, (string) $windowStart);

            // Get count
            return (int) $this->redis->zCard($key);
        } catch (\RedisException $e) {
            error_log("Redis sliding window error: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Get current count from memory.
     *
     * @param string $identifier
     * @return int
     */
    private function currentMemory(string $identifier): int
    {
        if (!isset($this->windows[$identifier])) {
            return 0;
        }

        $now = microtime(true);
        $windowStart = $now - $this->windowSeconds;

        // Remove expired entries
        $this->windows[$identifier] = array_filter(
            $this->windows[$identifier],
            fn($timestamp) => $timestamp > $windowStart
        );

        return count($this->windows[$identifier]);
    }

    /**
     * {@inheritdoc}
     */
    public function retryAfter(string $identifier): int
    {
        if (!$this->enabled) {
            return 0;
        }

        if ($this->redis !== null) {
            return $this->retryAfterRedis($identifier);
        }

        return $this->retryAfterMemory($identifier);
    }

    /**
     * Get retry after from Redis.
     *
     * @param string $identifier
     * @return int
     */
    private function retryAfterRedis(string $identifier): int
    {
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
            error_log("Redis sliding window error: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Get retry after from memory.
     *
     * @param string $identifier
     * @return int
     */
    private function retryAfterMemory(string $identifier): int
    {
        if (!isset($this->windows[$identifier]) || empty($this->windows[$identifier])) {
            return 0;
        }

        $oldestTime = min($this->windows[$identifier]);
        $windowEnd = $oldestTime + $this->windowSeconds;
        $now = microtime(true);

        return max(0, (int) ceil($windowEnd - $now));
    }

    /**
     * {@inheritdoc}
     */
    public function reset(string $identifier): void
    {
        if ($this->redis !== null) {
            try {
                $key = "{$this->prefix}:{$identifier}";
                $this->redis->del($key);
            } catch (\RedisException $e) {
                error_log("Redis sliding window error: {$e->getMessage()}");
            }
        } else {
            unset($this->windows[$identifier]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stats(string $identifier): array
    {
        $current = $this->current($identifier);

        return [
            'current' => $current,
            'remaining' => max(0, $this->limit - $current),
            'limit' => $this->limit,
            'retry_after' => $this->retryAfter($identifier),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function algorithm(): RateLimitAlgorithm
    {
        return RateLimitAlgorithm::SLIDING_WINDOW;
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clean up expired windows (in-memory only).
     */
    public function cleanup(): void
    {
        if ($this->redis !== null) {
            return; // Redis handles expiration automatically
        }

        $now = microtime(true);
        $windowStart = $now - ($this->windowSeconds * 2);

        foreach ($this->windows as $identifier => $timestamps) {
            // Remove expired timestamps
            $this->windows[$identifier] = array_filter(
                $timestamps,
                fn($timestamp) => $timestamp > $windowStart
            );

            // Remove empty windows
            if (empty($this->windows[$identifier])) {
                unset($this->windows[$identifier]);
            }
        }
    }
}

