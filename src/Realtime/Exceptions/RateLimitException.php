<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Exceptions;

/**
 * Class RateLimitException
 *
 * Exception for rate limiting violations.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class RateLimitException extends RealtimeException
{
    /**
     * Number of seconds until rate limit resets.
     */
    protected int $retryAfter;

    /**
     * Current limit.
     */
    protected int $limit;

    /**
     * Current count.
     */
    protected int $current;

    /**
     * Create rate limit exception.
     *
     * @param string $identifier Rate limit identifier (channel, connection, user)
     * @param int $limit Rate limit
     * @param int $current Current count
     * @param int $retryAfter Seconds until reset
     */
    public function __construct(
        string $identifier,
        int $limit,
        int $current,
        int $retryAfter = 60
    ) {
        $this->limit = $limit;
        $this->current = $current;
        $this->retryAfter = $retryAfter;

        parent::__construct(
            "Rate limit exceeded for '{$identifier}': {$current}/{$limit} (retry after {$retryAfter}s)",
            [
                'identifier' => $identifier,
                'limit' => $limit,
                'current' => $current,
                'retry_after' => $retryAfter,
            ]
        );
    }

    /**
     * Get seconds until rate limit resets.
     *
     * @return int
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * Get rate limit.
     *
     * @return int
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get current count.
     *
     * @return int
     */
    public function getCurrent(): int
    {
        return $this->current;
    }

    /**
     * Create exception for channel rate limit.
     *
     * @param string $channel Channel name
     * @param int $limit Rate limit
     * @param int $current Current count
     * @param int $retryAfter Seconds until reset
     * @return static
     */
    public static function channelLimit(string $channel, int $limit, int $current, int $retryAfter = 60): static
    {
        return new static("channel:{$channel}", $limit, $current, $retryAfter);
    }

    /**
     * Create exception for connection rate limit.
     *
     * @param string $connectionId Connection ID
     * @param int $limit Rate limit
     * @param int $current Current count
     * @param int $retryAfter Seconds until reset
     * @return static
     */
    public static function connectionLimit(string $connectionId, int $limit, int $current, int $retryAfter = 60): static
    {
        return new static("connection:{$connectionId}", $limit, $current, $retryAfter);
    }
}
