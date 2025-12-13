<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Backoff;


/**
 * Interface BackoffStrategy
 *
 * Contract defining the interface for BackoffStrategy implementations in
 * the Backoff layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Backoff
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface BackoffStrategy
{
    /**
     * Calculate delay before next retry.
     *
     * @param int $attempts Current attempt number (1-indexed)
     * @return int Delay in seconds before retry
     *
     * @example
     * // Constant backoff: always 10 seconds
     * $delay = $strategy->calculate(1); // 10
     * $delay = $strategy->calculate(2); // 10
     *
     * // Exponential backoff: 2^attempt
     * $delay = $strategy->calculate(1); // 2
     * $delay = $strategy->calculate(2); // 4
     * $delay = $strategy->calculate(3); // 8
     */
    public function calculate(int $attempts): int;
}
