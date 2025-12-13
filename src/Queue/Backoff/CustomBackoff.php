<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Backoff;

/**
 * Class CustomBackoff
 *
 * Uses custom array or callable for flexible retry delays.
 * Maximum flexibility for complex retry scenarios.
 *
 * Performance: O(1) for array, O(N) for callable where N = callback complexity
 *
 * Use Cases:
 * - Custom business logic delays
 * - Time-of-day based retries (avoid peak hours)
 * - Progressive backoff patterns
 * - Domain-specific retry logic
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Queue\Backoff
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class CustomBackoff implements BackoffStrategy
{
    /**
     * @var array<int>|callable Array of delays or callable
     */
    private $delays;

    /**
     * @param array<int>|callable $delays Array of delays or callable(int $attempts): int
     */
    public function __construct(array|callable $delays)
    {
        $this->delays = $delays;
    }

    /**
     * {@inheritdoc}
     *
     * Calculate delay using custom logic.
     *
     * @example
     * // Array-based delays
     * $backoff = new CustomBackoff([5, 10, 30, 60, 120]);
     * $backoff->calculate(1); // 5 seconds
     * $backoff->calculate(2); // 10 seconds
     * $backoff->calculate(10); // 120 seconds (uses last value)
     *
     * // Callable-based delays
     * $backoff = new CustomBackoff(fn($attempt) => $attempt * 10);
     * $backoff->calculate(1); // 10 seconds
     * $backoff->calculate(2); // 20 seconds
     * $backoff->calculate(3); // 30 seconds
     *
     * // Time-aware delays (avoid business hours)
     * $backoff = new CustomBackoff(function($attempt) {
     *     $hour = (int)date('H');
     *     // If during business hours (9-17), wait longer
     *     return ($hour >= 9 && $hour < 17) ? $attempt * 30 : $attempt * 5;
     * });
     *
     * @param int $attempts Current attempt number (1-indexed)
     * @return int Delay in seconds
     */
    public function calculate(int $attempts): int
    {
        if (is_callable($this->delays)) {
            // Call custom function
            return ($this->delays)($attempts);
        }

        // Use array - if attempt exceeds array length, use last value
        $index = min($attempts - 1, count($this->delays) - 1);
        return $this->delays[$index];
    }
}
