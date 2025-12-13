<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

/**
 * Class Cookie
 *
 * Represents an HTTP cookie with security options.
 * Immutable value object following Clean Architecture principles.
 *
 * Security Features:
 * - Secure flag auto-detected from HTTPS connection
 * - HttpOnly enabled by default (XSS protection)
 * - SameSite=Lax by default (CSRF protection)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value = '',
        public readonly int $expires = 0,
        public readonly string $path = '/',
        public readonly string $domain = '',
        public readonly bool $secure = false,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'Lax' // Lax, Strict, None
    ) {}

    /**
     * Check if the current connection is secure (HTTPS).
     *
     * @return bool
     */
    private static function isSecureConnection(): bool
    {
        // Direct HTTPS
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Standard port check
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        // SECURITY: Only trust X-Forwarded-Proto if from trusted proxies
        // This prevents attackers from spoofing HTTPS by setting the header
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            // Get list of trusted proxy IPs from config or environment
            $trustedProxies = self::getTrustedProxies();
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

            // Only trust the header if request comes from a trusted proxy
            if (self::isTrustedProxy($clientIp, $trustedProxies)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of trusted proxy IP addresses.
     *
     * @return array<string> List of trusted proxy IPs/CIDRs
     */
    private static function getTrustedProxies(): array
    {
        // Get from environment or config
        $proxies = getenv('TRUSTED_PROXIES') ?: ($_ENV['TRUSTED_PROXIES'] ?? '');

        if (empty($proxies)) {
            // Default to common private network ranges (for behind load balancers)
            // You can set TRUSTED_PROXIES=* to trust all (not recommended)
            return ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
        }

        if ($proxies === '*') {
            return ['*'];
        }

        return array_map('trim', explode(',', $proxies));
    }

    /**
     * Check if IP address is from a trusted proxy.
     *
     * @param string $ip Client IP address
     * @param array<string> $trustedProxies List of trusted IPs/CIDRs
     * @return bool True if IP is trusted
     */
    private static function isTrustedProxy(string $ip, array $trustedProxies): bool
    {
        if (in_array('*', $trustedProxies, true)) {
            return true;
        }

        foreach ($trustedProxies as $trusted) {
            if (str_contains($trusted, '/')) {
                // CIDR notation - check if IP is in range
                if (self::ipInCidr($ip, $trusted)) {
                    return true;
                }
            } elseif ($ip === $trusted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is within CIDR range.
     *
     * @param string $ip IP address
     * @param string $cidr CIDR range (e.g., "10.0.0.0/8")
     * @return bool True if IP is in range
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        // IPv4 check
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $mask = -1 << (32 - (int)$bits);

            return ($ipLong & $mask) === ($subnetLong & $mask);
        }

        return false;
    }

    /**
     * Create a cookie that expires in specified minutes
     *
     * Security: Auto-detects HTTPS and sets Secure flag accordingly.
     * This ensures cookies are only sent over HTTPS in production.
     *
     * @param string $name
     * @param string $value
     * @param int $minutes
     * @param array $options
     * @return self
     */
    public static function make(string $name, string $value, int $minutes = 60, array $options = []): self
    {
        return new self(
            name: $name,
            value: $value,
            expires: now()->getTimestamp() + ($minutes * 60),
            path: $options['path'] ?? '/',
            domain: $options['domain'] ?? '',
            secure: $options['secure'] ?? self::isSecureConnection(),
            httpOnly: $options['httpOnly'] ?? true,
            sameSite: $options['sameSite'] ?? 'Lax'
        );
    }

    /**
     * Create a cookie that lasts forever (5 years)
     *
     * @param string $name
     * @param string $value
     * @param array $options
     * @return self
     */
    public static function forever(string $name, string $value, array $options = []): self
    {
        return self::make($name, $value, 60 * 24 * 365 * 5, $options);
    }

    /**
     * Create a cookie that expires immediately (for deletion)
     *
     * Security: Uses same secure options as make() to ensure proper deletion.
     *
     * @param string $name
     * @param array $options
     * @return self
     */
    public static function forget(string $name, array $options = []): self
    {
        return new self(
            name: $name,
            value: '',
            expires: now()->getTimestamp() - 3600,
            path: $options['path'] ?? '/',
            domain: $options['domain'] ?? '',
            secure: $options['secure'] ?? self::isSecureConnection(),
            httpOnly: $options['httpOnly'] ?? true,
            sameSite: $options['sameSite'] ?? 'Lax'
        );
    }

    /**
     * Send the cookie to the browser
     *
     * @return bool
     */
    public function send(): bool
    {
        return setcookie(
            $this->name,
            $this->value,
            [
                'expires' => $this->expires,
                'path' => $this->path,
                'domain' => $this->domain,
                'secure' => $this->secure,
                'httponly' => $this->httpOnly,
                'samesite' => $this->sameSite,
            ]
        );
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'expires' => $this->expires,
            'path' => $this->path,
            'domain' => $this->domain,
            'secure' => $this->secure,
            'httpOnly' => $this->httpOnly,
            'sameSite' => $this->sameSite,
        ];
    }
}
