<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

/**
 * Class SmtpConnectionPool
 *
 * Singleton connection pool for SMTP transports to enable connection reuse
 * across multiple email sends within the same process/worker.
 *
 * Benefits:
 * - Reuse authenticated connections (save ~2s per email)
 * - Automatic health checks before reuse
 * - Graceful connection recycling on errors
 * - Thread-safe for worker processes
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Mail\Transport
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SmtpConnectionPool
{
    /**
     * @var array<string, array{transport: SmtpTransport, created_at: int, use_count: int}> Pool of connections
     */
    private static array $pool = [];

    /**
     * @var int Maximum age of connection in seconds (default: 5 minutes)
     */
    private static int $maxAge = 300;

    /**
     * @var int Maximum uses per connection (default: 100 emails)
     */
    private static int $maxUses = 100;

    /**
     * Get or create a transport from pool.
     *
     * @param string $host SMTP host
     * @param int $port SMTP port
     * @param string|null $username Auth username
     * @param string|null $password Auth password
     * @param string $encryption Encryption type
     * @param int $timeout Connection timeout
     * @param bool $debug Debug mode
     * @return SmtpTransport
     */
    public static function get(
        string $host,
        int $port = 587,
        ?string $username = null,
        ?string $password = null,
        string $encryption = 'tls',
        int $timeout = 30,
        bool $debug = false
    ): SmtpTransport {
        $key = self::makeKey($host, $port, $username, $encryption);

        // Check if we have a valid connection in pool
        if (isset(self::$pool[$key])) {
            $pooled = self::$pool[$key];

            // Check if connection is still valid
            if (self::isConnectionValid($pooled)) {
                $pooled['use_count']++;
                self::$pool[$key] = $pooled;

                return $pooled['transport'];
            }

            // Connection expired or unhealthy, remove it
            self::remove($key);
        }

        // Create new connection
        $transport = new SmtpTransport(
            host: $host,
            port: $port,
            username: $username,
            password: $password,
            encryption: $encryption,
            timeout: $timeout,
            debug: $debug
        );

        // Add to pool
        self::$pool[$key] = [
            'transport' => $transport,
            'created_at' => time(),
            'use_count' => 0,
        ];

        return $transport;
    }

    /**
     * Remove a connection from pool.
     *
     * @param string $key Connection key
     */
    public static function remove(string $key): void
    {
        if (isset(self::$pool[$key])) {
            $transport = self::$pool[$key]['transport'];
            $transport->disconnect();
            unset(self::$pool[$key]);
        }
    }

    /**
     * Check if a pooled connection is still valid.
     *
     * @param array{transport: SmtpTransport, created_at: int, use_count: int} $pooled Pooled connection
     * @return bool
     */
    private static function isConnectionValid(array $pooled): bool
    {
        // Check age
        if ((time() - $pooled['created_at']) > self::$maxAge) {
            return false;
        }

        // Check use count
        if ($pooled['use_count'] >= self::$maxUses) {
            return false;
        }

        // Check health
        if (!$pooled['transport']->isHealthy()) {
            return false;
        }

        return true;
    }

    /**
     * Make cache key from connection parameters.
     *
     * @param string $host SMTP host
     * @param int $port SMTP port
     * @param string|null $username Auth username
     * @param string $encryption Encryption type
     * @return string
     */
    private static function makeKey(
        string $host,
        int $port,
        ?string $username,
        string $encryption
    ): string {
        return md5(implode(':', [$host, $port, $username ?? '', $encryption]));
    }

    /**
     * Clear all connections in pool.
     */
    public static function clear(): void
    {
        foreach (self::$pool as $key => $pooled) {
            $pooled['transport']->disconnect();
        }

        self::$pool = [];
    }

    /**
     * Get pool statistics.
     *
     * @return array{total: int, connections: array<string, array{age: int, uses: int, healthy: bool}>}
     */
    public static function getStats(): array
    {
        $stats = [
            'total' => count(self::$pool),
            'connections' => [],
        ];

        foreach (self::$pool as $key => $pooled) {
            $stats['connections'][$key] = [
                'age' => time() - $pooled['created_at'],
                'uses' => $pooled['use_count'],
                'healthy' => $pooled['transport']->isHealthy(),
            ];
        }

        return $stats;
    }

    /**
     * Set maximum connection age.
     *
     * @param int $seconds Max age in seconds
     */
    public static function setMaxAge(int $seconds): void
    {
        self::$maxAge = $seconds;
    }

    /**
     * Set maximum uses per connection.
     *
     * @param int $uses Max uses count
     */
    public static function setMaxUses(int $uses): void
    {
        self::$maxUses = $uses;
    }

    /**
     * Private constructor to prevent instantiation.
     */
    private function __construct() {}
}
