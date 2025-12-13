<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Middleware;

use Toporia\Framework\Realtime\Contracts\ConnectionInterface;
use Toporia\Framework\Realtime\Security\IpWhitelist;

/**
 * IP Whitelist Middleware
 *
 * Restricts channel access to whitelisted IP addresses.
 *
 * Use this for:
 * - Admin channels
 * - Internal-only channels
 * - VPN-restricted channels
 * - Geographic restrictions
 *
 * Usage in routes/channels.php:
 *
 * ```php
 * ChannelRoute::channel('internal.*', fn($conn) => true)
 *     ->middleware(['ip_whitelist:192.168.0.0/24,10.0.0.0/8']);
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
final class IpWhitelistMiddleware implements ChannelMiddlewareInterface
{
    /**
     * @param IpWhitelist $ipWhitelist IP whitelist
     */
    public function __construct(
        private readonly IpWhitelist $ipWhitelist
    ) {}

    /**
     * {@inheritdoc}
     */
    public function handle(ConnectionInterface $connection, string $channelName, callable $next): bool
    {
        $ipAddress = $this->getIpAddress($connection);

        // Check if IP is whitelisted
        if (!$this->ipWhitelist->isAllowed($ipAddress)) {
            error_log("[IP Whitelist] Denied access to '{$channelName}' from IP: {$ipAddress}");
            return false;
        }

        // Pass to next middleware
        return $next($connection, $channelName);
    }

    /**
     * Get IP address from connection.
     *
     * @param ConnectionInterface $connection
     * @return string
     */
    private function getIpAddress(ConnectionInterface $connection): string
    {
        return $connection->get('ip_address')
            ?? $connection->get('remote_address')
            ?? 'unknown';
    }

    /**
     * Create middleware from parameters.
     *
     * @param string ...$allowedIps Allowed IPs or CIDR ranges
     * @return self
     */
    public static function create(string ...$allowedIps): self
    {
        $ipWhitelist = new IpWhitelist(
            whitelist: $allowedIps,
            whitelistMode: true
        );

        return new self($ipWhitelist);
    }
}
