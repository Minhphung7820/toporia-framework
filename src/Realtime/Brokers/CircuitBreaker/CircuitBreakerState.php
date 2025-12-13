<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\CircuitBreaker;

/**
 * Enum CircuitBreakerState
 *
 * Circuit breaker state enumeration.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers\CircuitBreaker
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
enum CircuitBreakerState: string
{
    case CLOSED = 'closed';         // Normal operation
    case OPEN = 'open';             // Too many failures, reject requests
    case HALF_OPEN = 'half_open';   // Testing if service recovered
}
