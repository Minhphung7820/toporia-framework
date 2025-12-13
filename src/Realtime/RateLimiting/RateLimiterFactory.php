<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\RateLimiting;

use Redis;
use Toporia\Framework\Realtime\Brokers\CircuitBreaker\CircuitBreaker;

/**
 * Rate Limiter Factory
 *
 * Creates rate limiter instances with proper configuration.
 *
 * Simplifies rate limiter instantiation:
 * - Auto-detect Redis availability
 * - Choose best algorithm for use case
 * - Apply sensible defaults
 * - Support for multi-layer limiting
 *
 * Usage:
 *
 * ```php
 * // Simple token bucket limiter
 * $limiter = RateLimiterFactory::create('connection_limit', [
 *     'algorithm' => 'token_bucket',
 *     'capacity' => 60,
 *     'refill_rate' => 1.0,
 * ]);
 *
 * // Multi-layer limiter
 * $multiLayer = RateLimiterFactory::createMultiLayer([
 *     'connection' => ['limit' => 60, 'window' => 60],
 *     'ip' => ['limit' => 100, 'window' => 60],
 *     'user' => ['limit' => 1000, 'window' => 3600],
 * ]);
 *
 * // Adaptive limiter
 * $adaptive = RateLimiterFactory::createAdaptive('api_limit', [
 *     'base_limit' => 100,
 *     'adjustment_rate' => 0.5,
 * ]);
 * ```
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
final class RateLimiterFactory
{
    /**
     * Create rate limiter instance.
     *
     * @param string $name Limiter name (for Redis key prefix)
     * @param array{
     *     algorithm?: string,
     *     limit?: int,
     *     window?: int,
     *     capacity?: int,
     *     refill_rate?: float,
     *     redis?: Redis|null,
     *     enabled?: bool
     * } $config Configuration
     * @return RateLimiterInterface
     */
    public static function create(string $name, array $config = []): RateLimiterInterface
    {
        $algorithm = RateLimitAlgorithm::tryFrom($config['algorithm'] ?? 'token_bucket')
            ?? RateLimitAlgorithm::TOKEN_BUCKET;

        $redis = $config['redis'] ?? null;
        $enabled = $config['enabled'] ?? true;

        return match ($algorithm) {
            RateLimitAlgorithm::TOKEN_BUCKET => self::createTokenBucket($name, $config, $redis, $enabled),
            RateLimitAlgorithm::SLIDING_WINDOW => self::createSlidingWindow($name, $config, $redis, $enabled),
            RateLimitAlgorithm::LEAKY_BUCKET => throw new \RuntimeException('Leaky bucket not yet implemented'),
            RateLimitAlgorithm::FIXED_WINDOW => throw new \RuntimeException('Fixed window not yet implemented'),
        };
    }

    /**
     * Create token bucket limiter.
     *
     * @param string $name
     * @param array<string, mixed> $config
     * @param Redis|null $redis
     * @param bool $enabled
     * @return TokenBucketRateLimiter
     */
    private static function createTokenBucket(
        string $name,
        array $config,
        ?Redis $redis,
        bool $enabled
    ): TokenBucketRateLimiter {
        return new TokenBucketRateLimiter(
            redis: $redis,
            capacity: $config['capacity'] ?? $config['limit'] ?? 60,
            refillRate: $config['refill_rate'] ?? 1.0,
            enabled: $enabled,
            prefix: "realtime:ratelimit:token:{$name}"
        );
    }

    /**
     * Create sliding window limiter.
     *
     * @param string $name
     * @param array<string, mixed> $config
     * @param Redis|null $redis
     * @param bool $enabled
     * @return SlidingWindowRateLimiter
     */
    private static function createSlidingWindow(
        string $name,
        array $config,
        ?Redis $redis,
        bool $enabled
    ): SlidingWindowRateLimiter {
        return new SlidingWindowRateLimiter(
            redis: $redis,
            limit: $config['limit'] ?? 60,
            windowSeconds: $config['window'] ?? 60,
            enabled: $enabled,
            prefix: "realtime:ratelimit:sliding:{$name}"
        );
    }

    /**
     * Create multi-layer rate limiter.
     *
     * @param array<string, array{enabled?: bool, limit?: int, window?: int, algorithm?: RateLimitAlgorithm}> $layerConfig
     * @param Redis|null $redis Redis connection for all layers
     * @return MultiLayerRateLimiter
     */
    public static function createMultiLayer(array $layerConfig = [], ?Redis $redis = null): MultiLayerRateLimiter
    {
        // Create default limiter for all layers
        $defaultLimiter = self::create('default', [
            'algorithm' => 'token_bucket',
            'capacity' => 60,
            'redis' => $redis,
        ]);

        return new MultiLayerRateLimiter($defaultLimiter, $layerConfig);
    }

    /**
     * Create adaptive rate limiter.
     *
     * @param string $name Limiter name
     * @param array{
     *     base_limit?: int,
     *     adjustment_rate?: float,
     *     load_update_interval?: int,
     *     algorithm?: string,
     *     redis?: Redis|null,
     *     circuit_breaker?: CircuitBreaker|null,
     *     enabled?: bool
     * } $config Configuration
     * @return AdaptiveRateLimiter
     */
    public static function createAdaptive(string $name, array $config = []): AdaptiveRateLimiter
    {
        $baseLimiter = self::create($name, [
            'algorithm' => $config['algorithm'] ?? 'token_bucket',
            'capacity' => $config['base_limit'] ?? 60,
            'redis' => $config['redis'] ?? null,
        ]);

        return new AdaptiveRateLimiter(
            baseLimiter: $baseLimiter,
            baseLimit: $config['base_limit'] ?? 60,
            adjustmentRate: $config['adjustment_rate'] ?? 0.5,
            loadUpdateInterval: $config['load_update_interval'] ?? 5,
            circuitBreaker: $config['circuit_breaker'] ?? null,
            enabled: $config['enabled'] ?? true
        );
    }

    /**
     * Create rate limiter from config array.
     *
     * Supports both simple and complex configurations:
     *
     * Simple:
     * ```php
     * ['type' => 'token_bucket', 'limit' => 100]
     * ```
     *
     * Multi-layer:
     * ```php
     * [
     *     'type' => 'multi_layer',
     *     'layers' => [
     *         'connection' => ['limit' => 60],
     *         'ip' => ['limit' => 100],
     *     ]
     * ]
     * ```
     *
     * Adaptive:
     * ```php
     * [
     *     'type' => 'adaptive',
     *     'base_limit' => 100,
     *     'adjustment_rate' => 0.5,
     * ]
     * ```
     *
     * @param string $name Limiter name
     * @param array<string, mixed> $config Configuration
     * @return RateLimiterInterface|MultiLayerRateLimiter|AdaptiveRateLimiter
     */
    public static function fromConfig(string $name, array $config): RateLimiterInterface|MultiLayerRateLimiter|AdaptiveRateLimiter
    {
        $type = $config['type'] ?? 'token_bucket';

        return match ($type) {
            'multi_layer' => self::createMultiLayer(
                $config['layers'] ?? [],
                $config['redis'] ?? null
            ),
            'adaptive' => self::createAdaptive($name, $config),
            default => self::create($name, $config),
        };
    }

    /**
     * Auto-detect and create Redis connection if available.
     *
     * @param array{host?: string, port?: int, password?: string|null, database?: int} $config Redis config
     * @return Redis|null
     */
    public static function createRedisConnection(array $config = []): ?Redis
    {
        if (!extension_loaded('redis')) {
            return null;
        }

        try {
            $redis = new Redis();
            $redis->connect(
                $config['host'] ?? '127.0.0.1',
                (int) ($config['port'] ?? 6379),
                (float) ($config['timeout'] ?? 2.0)
            );

            if (isset($config['password']) && $config['password'] !== null && $config['password'] !== '') {
                $redis->auth($config['password']);
            }

            if (isset($config['database'])) {
                $redis->select((int) $config['database']);
            }

            return $redis;
        } catch (\RedisException $e) {
            error_log("Failed to connect to Redis: {$e->getMessage()}");
            return null;
        }
    }
}
