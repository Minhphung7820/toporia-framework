<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Middleware;

use Toporia\Framework\Realtime\Contracts\ConnectionInterface;
use Toporia\Framework\Realtime\Security\DDoSProtection;
use Toporia\Framework\Realtime\Security\IpWhitelist;

/**
 * Security Middleware
 *
 * Comprehensive security checks for realtime connections.
 *
 * Features:
 * - DDoS protection
 * - IP whitelist/blacklist
 * - Connection fingerprinting
 * - Anomaly detection
 *
 * This middleware should be applied globally or to sensitive channels.
 *
 * Usage in routes/channels.php:
 *
 * ```php
 * ChannelRoute::channel('admin.*', fn($conn) => true)
 *     ->middleware(['security', 'auth', 'role:admin']);
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
final class SecurityMiddleware implements ChannelMiddlewareInterface
{
    /**
     * @param DDoSProtection $ddosProtection DDoS protection
     * @param IpWhitelist $ipWhitelist IP whitelist/blacklist
     */
    public function __construct(
        private readonly DDoSProtection $ddosProtection,
        private readonly IpWhitelist $ipWhitelist
    ) {}

    /**
     * {@inheritdoc}
     */
    public function handle(ConnectionInterface $connection, string $channelName, callable $next): bool
    {
        $ipAddress = $this->getIpAddress($connection);

        // Check IP whitelist/blacklist
        if (!$this->ipWhitelist->isAllowed($ipAddress)) {
            error_log("[Security] Blocked connection from blacklisted IP: {$ipAddress}");
            return false;
        }

        // Check DDoS protection
        if (!$this->ddosProtection->isAllowed($ipAddress)) {
            error_log("[Security] Blocked connection from IP under DDoS protection: {$ipAddress}");
            return false;
        }

        // Additional security checks
        if (!$this->validateConnection($connection)) {
            error_log("[Security] Connection failed validation: {$connection->getId()}");
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
     * Validate connection integrity.
     *
     * Performs additional security checks:
     * - Connection age (not too new, prevent rapid reconnections)
     * - Metadata integrity
     * - User agent validation
     *
     * @param ConnectionInterface $connection
     * @return bool
     */
    private function validateConnection(ConnectionInterface $connection): bool
    {
        // Check connection age (prevent rapid reconnections)
        $connectedAt = $connection->getConnectedAt();
        $minAge = 1; // 1 second minimum

        if ((time() - $connectedAt) < $minAge) {
            return false;
        }

        // Check user agent (if required)
        $userAgent = $connection->get('user_agent');
        if ($userAgent === null || $userAgent === '') {
            // Optionally require user agent
            // return false;
        }

        // Check for suspicious patterns
        if ($this->isSuspiciousConnection($connection)) {
            return false;
        }

        return true;
    }

    /**
     * Check for suspicious connection patterns.
     *
     * @param ConnectionInterface $connection
     * @return bool True if suspicious
     */
    private function isSuspiciousConnection(ConnectionInterface $connection): bool
    {
        // Check for missing critical metadata
        $metadata = $connection->getMetadata();

        if (empty($metadata)) {
            return true;
        }

        // Check for suspicious user agents
        $userAgent = $connection->get('user_agent', '');
        $suspiciousPatterns = [
            'curl',
            'wget',
            'python-requests',
            'bot',
            'crawler',
            'spider',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
