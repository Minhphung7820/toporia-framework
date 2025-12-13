<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Backoff;

/**
 * Class ExponentialBackoff
 *
 * Increases delay exponentially with each retry attempt.
 * Industry-standard backoff algorithm for distributed systems.
 *
 * Formula: delay = base^attempt (capped at max)
 *
 * Performance: O(1) - pow() is constant time
 *
 * Use Cases:
 * - External API calls (give service time to recover)
 * - Distributed system retries
 * - Network failures
 * - Database connection retries
 *
 * Benefits:
 * - Reduces load on failing services
 * - Gives systems time to recover
 * - Industry best practice
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
final class ExponentialBackoff implements BackoffStrategy
{
    /**
     * @param int $base Base delay in seconds (default: 2)
     * @param int $max Maximum delay cap in seconds (default: 300 = 5 minutes)
     * @param bool $jitter Add random jitter to prevent thundering herd (default: true)
     * @param float $jitterFactor Jitter factor 0.0-1.0 (default: 0.2 = ±20%)
     */
    public function __construct(
        private int $base = 2,
        private int $max = 300,
        private bool $jitter = true,
        private float $jitterFactor = 0.2
    ) {}

    /**
     * {@inheritdoc}
     *
     * Calculate exponential delay with max cap.
     *
     * Performance: O(1) - pow() is constant time
     *
     * Formula: delay = base^attempts (capped at max)
     *
     * CRITICAL: attempts MUST be >= 1 for correct calculation
     * - If attempts = 0, will return 1 (base^0 = 1) which is almost immediate
     * - If attempts = 1, will return base (e.g., 5^1 = 5s)
     * - If attempts = 2, will return base^2 (e.g., 5^2 = 25s)
     * - If attempts = 3, will return base^3 (e.g., 5^3 = 125s, capped at max=60 → 60s)
     *
     * @example
     * $backoff = new ExponentialBackoff(base: 5, max: 60);
     * $backoff->calculate(1); // 5 seconds (5^1)
     * $backoff->calculate(2); // 25 seconds (5^2)
     * $backoff->calculate(3); // 60 seconds (5^3 = 125, capped at 60)
     *
     * @example With base: 2
     * $backoff = new ExponentialBackoff(base: 2, max: 300);
     * $backoff->calculate(1); // 2 seconds (2^1)
     * $backoff->calculate(2); // 4 seconds (2^2)
     * $backoff->calculate(3); // 8 seconds (2^3)
     * $backoff->calculate(4); // 16 seconds (2^4)
     * $backoff->calculate(10); // 300 seconds (2^10 = 1024, capped at max)
     *
     * @param int $attempts Current attempt number (MUST be >= 1)
     * @return int Delay in seconds
     */
    public function calculate(int $attempts): int
    {
        // SAFETY: Ensure attempts is at least 1
        // If attempts = 0, would return 1 second (base^0 = 1) which is too fast
        if ($attempts < 1) {
            $attempts = 1;
        }

        // Calculate: base^attempts
        // Example with base=5: 5^1=5, 5^2=25, 5^3=125
        $delay = (int) pow($this->base, $attempts);

        // Cap at maximum to prevent infinite delays
        // Example: 125 seconds capped at max=60 → 60 seconds
        $delay = min($delay, $this->max);

        // Add jitter to prevent thundering herd effect
        // When multiple jobs fail at the same time, they won't all retry simultaneously
        if ($this->jitter && $delay > 0) {
            $jitterAmount = (int) ($delay * $this->jitterFactor);
            $delay += rand(-$jitterAmount, $jitterAmount);

            // Ensure delay is at least 1 second
            $delay = max(1, $delay);
        }

        return $delay;
    }
}
