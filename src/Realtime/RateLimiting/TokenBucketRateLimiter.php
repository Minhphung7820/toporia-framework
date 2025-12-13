<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\RateLimiting;

use Toporia\Framework\Realtime\Exceptions\RateLimitException;
use Redis;

/**
 * Token Bucket Rate Limiter
 *
 * Implements Token Bucket algorithm for rate limiting.
 *
 * Algorithm:
 * - Bucket has maximum capacity (burst size)
 * - Tokens are added at fixed rate (refill rate)
 * - Each request consumes tokens
 * - Request allowed if enough tokens available
 *
 * Advantages:
 * - Allows burst traffic up to bucket capacity
 * - Smooth long-term average rate
 * - Flexible and intuitive
 *
 * Best for: Realtime messaging with occasional bursts
 *
 * Performance: ~1-2ms per check (Redis) or <0.1ms (memory)
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
final class TokenBucketRateLimiter implements RateLimiterInterface
{
    /**
     * In-memory state for local limiter.
     *
     * @var array<string, array{tokens: float, last_refill: float}>
     */
    private array $buckets = [];

    /**
     * Lua script for atomic token bucket operations.
     */
    private const LUA_SCRIPT = <<<'LUA'
local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill_rate = tonumber(ARGV[2])
local cost = tonumber(ARGV[3])
local now = tonumber(ARGV[4])

-- Get current state
local state = redis.call('HMGET', key, 'tokens', 'last_refill')
local tokens = tonumber(state[1]) or capacity
local last_refill = tonumber(state[2]) or now

-- Calculate tokens to add based on elapsed time
local elapsed = now - last_refill
local tokens_to_add = elapsed * refill_rate
tokens = math.min(capacity, tokens + tokens_to_add)

-- Check if we have enough tokens
if tokens >= cost then
    -- Consume tokens
    tokens = tokens - cost
    redis.call('HMSET', key, 'tokens', tokens, 'last_refill', now)
    redis.call('EXPIRE', key, math.ceil(capacity / refill_rate * 2))
    return {1, tokens}
else
    -- Not enough tokens
    return {0, tokens}
end
LUA;

    /**
     * @param Redis|null $redis Redis connection (null = in-memory)
     * @param int $capacity Maximum bucket capacity (burst size)
     * @param float $refillRate Tokens added per second
     * @param bool $enabled Whether rate limiting is enabled
     * @param string $prefix Redis key prefix
     */
    public function __construct(
        private readonly ?Redis $redis = null,
        private readonly int $capacity = 60,
        private readonly float $refillRate = 1.0,
        private readonly bool $enabled = true,
        private readonly string $prefix = 'realtime:ratelimit:token'
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
                [$key, $this->capacity, $this->refillRate, $cost, $now],
                1
            );

            return $result[0] === 1;
        } catch (\RedisException $e) {
            error_log("Redis token bucket error: {$e->getMessage()}");
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

        // Initialize bucket if not exists
        if (!isset($this->buckets[$identifier])) {
            $this->buckets[$identifier] = [
                'tokens' => (float) $this->capacity,
                'last_refill' => $now,
            ];
        }

        $bucket = &$this->buckets[$identifier];

        // Refill tokens based on elapsed time
        $elapsed = $now - $bucket['last_refill'];
        $tokensToAdd = $elapsed * $this->refillRate;
        $bucket['tokens'] = min($this->capacity, $bucket['tokens'] + $tokensToAdd);
        $bucket['last_refill'] = $now;

        // Check if we have enough tokens
        if ($bucket['tokens'] >= $cost) {
            $bucket['tokens'] -= $cost;
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
                $this->capacity,
                $this->capacity - $this->remaining($identifier),
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

        if ($this->redis !== null) {
            return $this->remainingRedis($identifier);
        }

        return $this->remainingMemory($identifier);
    }

    /**
     * Get remaining tokens from Redis.
     *
     * @param string $identifier
     * @return int
     */
    private function remainingRedis(string $identifier): int
    {
        try {
            $key = "{$this->prefix}:{$identifier}";
            $state = $this->redis->hGetAll($key);

            if (empty($state)) {
                return $this->capacity;
            }

            $tokens = (float) ($state['tokens'] ?? $this->capacity);
            $lastRefill = (float) ($state['last_refill'] ?? microtime(true));

            // Calculate current tokens after refill
            $elapsed = microtime(true) - $lastRefill;
            $tokensToAdd = $elapsed * $this->refillRate;
            $currentTokens = min($this->capacity, $tokens + $tokensToAdd);

            return (int) floor($currentTokens);
        } catch (\RedisException $e) {
            error_log("Redis token bucket error: {$e->getMessage()}");
            return $this->capacity;
        }
    }

    /**
     * Get remaining tokens from memory.
     *
     * @param string $identifier
     * @return int
     */
    private function remainingMemory(string $identifier): int
    {
        if (!isset($this->buckets[$identifier])) {
            return $this->capacity;
        }

        $bucket = $this->buckets[$identifier];
        $elapsed = microtime(true) - $bucket['last_refill'];
        $tokensToAdd = $elapsed * $this->refillRate;
        $currentTokens = min($this->capacity, $bucket['tokens'] + $tokensToAdd);

        return (int) floor($currentTokens);
    }

    /**
     * {@inheritdoc}
     */
    public function retryAfter(string $identifier): int
    {
        $remaining = $this->remaining($identifier);

        if ($remaining > 0) {
            return 0;
        }

        // Time to refill 1 token
        return (int) ceil(1.0 / $this->refillRate);
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
                error_log("Redis token bucket error: {$e->getMessage()}");
            }
        } else {
            unset($this->buckets[$identifier]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stats(string $identifier): array
    {
        $remaining = $this->remaining($identifier);
        $current = $this->capacity - $remaining;

        return [
            'current' => max(0, $current),
            'remaining' => $remaining,
            'limit' => $this->capacity,
            'retry_after' => $this->retryAfter($identifier),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function algorithm(): RateLimitAlgorithm
    {
        return RateLimitAlgorithm::TOKEN_BUCKET;
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clean up expired buckets (in-memory only).
     */
    public function cleanup(): void
    {
        if ($this->redis !== null) {
            return; // Redis handles expiration automatically
        }

        $now = microtime(true);
        $maxAge = ($this->capacity / $this->refillRate) * 2;

        foreach ($this->buckets as $identifier => $bucket) {
            if (($now - $bucket['last_refill']) > $maxAge) {
                unset($this->buckets[$identifier]);
            }
        }
    }
}

