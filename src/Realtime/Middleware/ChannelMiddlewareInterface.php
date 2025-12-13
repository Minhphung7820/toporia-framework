<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Middleware;

use Toporia\Framework\Realtime\Contracts\ConnectionInterface;

/**
 * Channel Middleware Interface
 *
 * Middleware for realtime channel authorization.
 * Similar to HTTP middleware but for realtime connections.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ChannelMiddlewareInterface
{
    /**
     * Handle channel authorization middleware.
     *
     * @param ConnectionInterface $connection Current connection
     * @param string $channelName Channel being subscribed to
     * @param callable $next Next middleware in pipeline
     * @return bool True if authorized, false otherwise
     */
    public function handle(ConnectionInterface $connection, string $channelName, callable $next): bool;
}

