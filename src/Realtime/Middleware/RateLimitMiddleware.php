<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Middleware;

use Toporia\Framework\Realtime\Contracts\ConnectionInterface;
use Toporia\Framework\Realtime\RateLimiting\MultiLayerRateLimiter;
use Toporia\Framework\Realtime\Exceptions\RateLimitException;

/**
 * Rate Limit Middleware
 *
 * Applies multi-layer rate limiting to channel subscriptions.
 *
 * Features:
 * - Multi-layer protection (IP, Connection, User, Channel)
 * - Configurable limits per layer
 * - Automatic blocking on abuse
 * - Statistics and monitoring
 *
 * Usage in routes/channels.php:
 *
 * ```php
 * ChannelRoute::channel('chat.{roomId}', fn($conn, $roomId) => true)
 *     ->middleware(['auth', 'ratelimit:100,60']); // 100 requests per 60 seconds
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Middleware
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RateLimitMiddleware implements ChannelMiddlewareInterface
{
    /**
     * @param MultiLayerRateLimiter $limiter Multi-layer rate limiter
     * @param int $limit Maximum requests per window (default: 60)
     * @param int $window Window size in seconds (default: 60)
     */
    public function __construct(
        private readonly MultiLayerRateLimiter $limiter,
        private readonly int $limit = 60,
        private readonly int $window = 60
    ) {}

    /**
     * {@inheritdoc}
     */
    public function handle(ConnectionInterface $connection, string $channelName, callable $next): bool
    {
        try {
            // Check rate limits (all layers)
            $this->limiter->check($connection, $channelName);

            // Pass to next middleware
            return $next($connection, $channelName);
        } catch (RateLimitException $e) {
            // Log rate limit violation
            $this->logViolation($connection, $channelName, $e);

            // Reject subscription
            return false;
        }
    }

    /**
     * Log rate limit violation.
     *
     * @param ConnectionInterface $connection
     * @param string $channelName
     * @param RateLimitException $exception
     */
    private function logViolation(
        ConnectionInterface $connection,
        string $channelName,
        RateLimitException $exception
    ): void {
        $userId = $connection->getUserId() ?? 'guest';
        $ip = $connection->get('ip_address', 'unknown');

        error_log(sprintf(
            "[Rate Limit] Blocked subscription to '%s' - User: %s, IP: %s, Reason: %s, Retry after: %ds",
            $channelName,
            $userId,
            $ip,
            $exception->getMessage(),
            $exception->getRetryAfter()
        ));
    }

    /**
     * Create middleware from parameters.
     *
     * Format: 'ratelimit:limit,window'
     * Example: 'ratelimit:100,60' = 100 requests per 60 seconds
     *
     * @param int $limit Maximum requests per window
     * @param int $window Window size in seconds
     * @return self
     */
    public static function create(int $limit = 60, int $window = 60): self
    {
        // In production, inject limiter via container
        // This is simplified for demonstration
        throw new \RuntimeException(
            'RateLimitMiddleware must be instantiated via container with proper MultiLayerRateLimiter'
        );
    }
}
