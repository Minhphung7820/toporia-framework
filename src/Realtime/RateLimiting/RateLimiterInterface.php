<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\RateLimiting;

use Toporia\Framework\Realtime\Exceptions\RateLimitException;

/**
 * Rate Limiter Interface
 *
 * Common interface for all rate limiter implementations.
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
interface RateLimiterInterface
{
    /**
     * Check if action is allowed and consume capacity.
     *
     * @param string $identifier Rate limit identifier
     * @param int $cost Cost of this action (default: 1)
     * @return bool True if allowed
     */
    public function attempt(string $identifier, int $cost = 1): bool;

    /**
     * Check if action is allowed, throw exception if not.
     *
     * @param string $identifier Rate limit identifier
     * @param int $cost Cost of this action (default: 1)
     * @throws RateLimitException If rate limit exceeded
     */
    public function check(string $identifier, int $cost = 1): void;

    /**
     * Get remaining capacity for an identifier.
     *
     * @param string $identifier Rate limit identifier
     * @return int Remaining capacity
     */
    public function remaining(string $identifier): int;

    /**
     * Get seconds until rate limit resets.
     *
     * @param string $identifier Rate limit identifier
     * @return int Seconds until reset
     */
    public function retryAfter(string $identifier): int;

    /**
     * Reset rate limit for an identifier.
     *
     * @param string $identifier Rate limit identifier
     */
    public function reset(string $identifier): void;

    /**
     * Get rate limiter statistics.
     *
     * @param string $identifier Rate limit identifier
     * @return array{current: int, remaining: int, limit: int, retry_after: int}
     */
    public function stats(string $identifier): array;

    /**
     * Get algorithm name.
     *
     * @return RateLimitAlgorithm
     */
    public function algorithm(): RateLimitAlgorithm;

    /**
     * Check if rate limiter is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool;
}
