<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Backoff;

/**
 * Class ConstantBackoff
 *
 * Returns same delay for all retry attempts.
 * Simple, predictable retry timing.
 *
 * Performance: O(1) - Constant time calculation
 *
 * Use Cases:
 * - API rate limiting (fixed wait between calls)
 * - Database deadlock retry (consistent wait)
 * - Simple retry scenarios
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
final class ConstantBackoff implements BackoffStrategy
{
    /**
     * @param int $delay Delay in seconds (default: 10)
     */
    public function __construct(
        private int $delay = 10
    ) {}

    /**
     * {@inheritdoc}
     *
     * Always returns same delay regardless of attempt number.
     *
     * @example
     * $backoff = new ConstantBackoff(5);
     * $backoff->calculate(1); // 5 seconds
     * $backoff->calculate(2); // 5 seconds
     * $backoff->calculate(10); // 5 seconds
     */
    public function calculate(int $attempts): int
    {
        return $this->delay;
    }
}
