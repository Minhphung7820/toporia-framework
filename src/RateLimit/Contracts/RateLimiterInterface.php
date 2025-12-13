<?php

declare(strict_types=1);

namespace Toporia\Framework\RateLimit\Contracts;


/**
 * Interface RateLimiterInterface
 *
 * Contract defining the interface for RateLimiterInterface implementations
 * in the RateLimit layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  RateLimit\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface RateLimiterInterface
{
    /**
     * Attempt to consume tokens from the limiter
     *
     * @param string $key Unique identifier (e.g., user ID, IP address)
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $decaySeconds Time window in seconds
     * @return bool True if allowed, false if rate limited
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool;

    /**
     * Check if rate limit has been exceeded without consuming
     *
     * @param string $key
     * @param int $maxAttempts
     * @param int|null $decaySeconds Optional decay seconds to use when resetting time
     * @return bool True if too many attempts
     */
    public function tooManyAttempts(string $key, int $maxAttempts, ?int $decaySeconds = null): bool;

    /**
     * Get the number of attempts for a key
     *
     * @param string $key
     * @return int
     */
    public function attempts(string $key): int;

    /**
     * Get the number of remaining attempts
     *
     * @param string $key
     * @param int $maxAttempts
     * @return int
     */
    public function remaining(string $key, int $maxAttempts): int;

    /**
     * Get the time until the rate limit resets
     *
     * @param string $key
     * @param int|null $decaySeconds Optional decay seconds to use as fallback if reset time not set
     * @return int Seconds until reset
     */
    public function availableIn(string $key, ?int $decaySeconds = null): int;

    /**
     * Clear all attempts for a key
     *
     * @param string $key
     * @return void
     */
    public function clear(string $key): void;

    /**
     * Reset attempts for a key
     *
     * @param string $key
     * @return void
     */
    public function resetAttempts(string $key): void;
}
