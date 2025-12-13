<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\ConnectionPool;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use Redis;

/**
 * Class BrokerConnectionPool
 *
 * Universal connection pool for all broker types with health monitoring.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers\ConnectionPool
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class BrokerConnectionPool implements ConnectionPoolInterface
{
    /**
     * @var array<string, BrokerConnectionPool> Pool instances by broker type
     */
    private static array $pools = [];

    /**
     * @var array<string, array{connection: mixed, created_at: int, last_used: int, use_count: int}> Pooled connections
     */
    private array $connections = [];

    private const MAX_AGE = 300; // 5 minutes
    private const MAX_USES = 1000;
    private const MAX_IDLE_TIME = 60; // 1 minute

    /**
     * Get or create pool instance for broker type.
     *
     * @param string $brokerType Broker type (redis, kafka, rabbitmq)
     * @return BrokerConnectionPool
     */
    public static function forBroker(string $brokerType): BrokerConnectionPool
    {
        if (!isset(self::$pools[$brokerType])) {
            self::$pools[$brokerType] = new self($brokerType);
        }

        return self::$pools[$brokerType];
    }

    private function __construct(
        private readonly string $brokerType
    ) {}

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        // Cleanup expired connections first
        $this->cleanup();

        if (isset($this->connections[$key])) {
            $pooled = $this->connections[$key];

            if ($this->isHealthy($pooled)) {
                $pooled['use_count']++;
                $pooled['last_used'] = time();
                $this->connections[$key] = $pooled;

                return $pooled['connection'];
            }

            // Connection unhealthy, remove it
            $this->release($key);
        }

        return null; // Caller must create new connection
    }

    /**
     * {@inheritdoc}
     */
    public function store(string $key, mixed $connection): void
    {
        $this->connections[$key] = [
            'connection' => $connection,
            'created_at' => time(),
            'last_used' => time(),
            'use_count' => 0,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function release(string $key): void
    {
        if (isset($this->connections[$key])) {
            $pooled = $this->connections[$key];

            // Cleanup connection
            $this->cleanupConnection($pooled['connection']);

            unset($this->connections[$key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        foreach (array_keys($this->connections) as $key) {
            $this->release($key);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        return [
            'broker_type' => $this->brokerType,
            'total_connections' => count($this->connections),
            'connections' => array_map(fn($p) => [
                'age' => time() - $p['created_at'],
                'idle' => time() - $p['last_used'],
                'uses' => $p['use_count'],
            ], $this->connections),
        ];
    }

    /**
     * Check if a pooled connection is still healthy.
     *
     * @param array{connection: mixed, created_at: int, last_used: int, use_count: int} $pooled
     * @return bool
     */
    private function isHealthy(array $pooled): bool
    {
        $now = time();

        // Check age
        if (($now - $pooled['created_at']) > self::MAX_AGE) {
            return false;
        }

        // Check use count
        if ($pooled['use_count'] >= self::MAX_USES) {
            return false;
        }

        // Check idle time
        if (($now - $pooled['last_used']) > self::MAX_IDLE_TIME) {
            return false;
        }

        // Broker-specific health check
        return $this->checkConnectionHealth($pooled['connection']);
    }

    /**
     * Check connection health based on broker type.
     *
     * @param mixed $connection
     * @return bool
     */
    private function checkConnectionHealth(mixed $connection): bool
    {
        try {
            return match ($this->brokerType) {
                'redis' => $connection instanceof Redis && $connection->ping(),
                'rabbitmq' => $connection instanceof AMQPStreamConnection && $connection->isConnected(),
                'kafka' => true, // Kafka client handles health internally
                default => false,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Cleanup connection resources.
     *
     * @param mixed $connection
     * @return void
     */
    private function cleanupConnection(mixed $connection): void
    {
        try {
            if ($connection instanceof Redis) {
                $connection->close();
            } elseif ($connection instanceof AMQPStreamConnection) {
                $connection->close();
            }
            // Kafka connections are handled by their own disconnect methods
        } catch (\Throwable $e) {
            error_log("Error cleaning up connection: {$e->getMessage()}");
        }
    }

    /**
     * Cleanup expired connections.
     *
     * @return void
     */
    private function cleanup(): void
    {
        foreach ($this->connections as $key => $pooled) {
            if (!$this->isHealthy($pooled)) {
                $this->release($key);
            }
        }
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}
}
