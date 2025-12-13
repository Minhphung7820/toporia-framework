<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\RateLimiting;

/**
 * Rate Limit Algorithm Enum
 *
 * Defines available rate limiting algorithms.
 *
 * - TOKEN_BUCKET: Allows bursts while maintaining average rate
 * - SLIDING_WINDOW: Accurate rate limiting with smooth distribution
 * - LEAKY_BUCKET: Smooth traffic flow, no bursts
 * - FIXED_WINDOW: Simple, fast but less accurate
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
enum RateLimitAlgorithm: string
{
    /**
     * Token Bucket Algorithm
     *
     * Best for: APIs that need to allow burst traffic
     * Pros: Flexible, allows bursts up to bucket capacity
     * Cons: More complex implementation
     *
     * Use case: Realtime messaging where users may send bursts of messages
     */
    case TOKEN_BUCKET = 'token_bucket';

    /**
     * Sliding Window Log Algorithm
     *
     * Best for: High accuracy rate limiting
     * Pros: Most accurate, no edge case issues
     * Cons: Higher memory usage (stores timestamps)
     *
     * Use case: Premium channels, critical operations
     */
    case SLIDING_WINDOW = 'sliding_window';

    /**
     * Leaky Bucket Algorithm
     *
     * Best for: Smoothing traffic, preventing spikes
     * Pros: Smooth output rate, simple to understand
     * Cons: Doesn't allow bursts
     *
     * Use case: Backend processing queues, downstream API calls
     */
    case LEAKY_BUCKET = 'leaky_bucket';

    /**
     * Fixed Window Algorithm
     *
     * Best for: Simple, high-performance rate limiting
     * Pros: Fast, low memory usage
     * Cons: Edge case allows 2x rate at window boundaries
     *
     * Use case: General purpose, non-critical operations
     */
    case FIXED_WINDOW = 'fixed_window';

    /**
     * Get algorithm description.
     *
     * @return string
     */
    public function description(): string
    {
        return match ($this) {
            self::TOKEN_BUCKET => 'Token Bucket: Allows bursts while maintaining average rate',
            self::SLIDING_WINDOW => 'Sliding Window: High accuracy, no edge cases',
            self::LEAKY_BUCKET => 'Leaky Bucket: Smooth traffic flow, no bursts',
            self::FIXED_WINDOW => 'Fixed Window: Simple and fast',
        };
    }

    /**
     * Check if algorithm supports bursts.
     *
     * @return bool
     */
    public function supportsBursts(): bool
    {
        return match ($this) {
            self::TOKEN_BUCKET => true,
            self::SLIDING_WINDOW => true,
            self::LEAKY_BUCKET => false,
            self::FIXED_WINDOW => false,
        };
    }

    /**
     * Get relative performance score (1-10).
     *
     * Higher = faster but less accurate
     *
     * @return int
     */
    public function performanceScore(): int
    {
        return match ($this) {
            self::TOKEN_BUCKET => 7,
            self::SLIDING_WINDOW => 5,
            self::LEAKY_BUCKET => 8,
            self::FIXED_WINDOW => 10,
        };
    }

    /**
     * Get accuracy score (1-10).
     *
     * Higher = more accurate
     *
     * @return int
     */
    public function accuracyScore(): int
    {
        return match ($this) {
            self::TOKEN_BUCKET => 8,
            self::SLIDING_WINDOW => 10,
            self::LEAKY_BUCKET => 7,
            self::FIXED_WINDOW => 5,
        };
    }
}

