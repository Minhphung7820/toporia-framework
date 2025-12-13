<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

use Toporia\Framework\Realtime\Contracts\BrokerInterface;
use Toporia\Framework\Realtime\RealtimeManager;

/**
 * Class BrokerFactory
 *
 * Factory for creating broker instances with validation.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     3.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class BrokerFactory
{
    /**
     * Supported broker drivers.
     */
    private const SUPPORTED_DRIVERS = [
        'redis',
        'kafka',
        'rabbitmq',
    ];

    /**
     * Create broker instance with validation.
     *
     * @param string $driver Broker driver name
     * @param array<string, mixed> $config Broker configuration
     * @param RealtimeManager|null $manager Realtime manager instance
     * @return BrokerInterface
     * @throws \InvalidArgumentException
     */
    public static function create(string $driver, array $config, ?RealtimeManager $manager = null): BrokerInterface
    {
        // Normalize driver name (support legacy -improved suffix for backward compatibility)
        $normalizedDriver = str_replace('-improved', '', $driver);

        // Validate driver
        if (!in_array($normalizedDriver, self::SUPPORTED_DRIVERS, true)) {
            throw new \InvalidArgumentException(
                "Unsupported broker driver: {$driver}. " .
                    "Supported: " . implode(', ', self::SUPPORTED_DRIVERS)
            );
        }

        // Validate configuration
        self::validateConfig($normalizedDriver, $config);

        // Create broker instance
        return match ($normalizedDriver) {
            'redis' => new RedisBroker($config, $manager),
            'kafka' => new KafkaBroker($config, $manager),
            'rabbitmq' => new RabbitMqBroker($config, $manager),
        };
    }

    /**
     * Validate broker configuration.
     *
     * @param string $driver Broker driver
     * @param array<string, mixed> $config Configuration
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateConfig(string $driver, array $config): void
    {
        match ($driver) {
            'redis' => self::validateRedisConfig($config),
            'kafka' => self::validateKafkaConfig($config),
            'rabbitmq' => self::validateRabbitMqConfig($config),
            default => null,
        };
    }

    /**
     * Validate Redis configuration.
     *
     * @param array<string, mixed> $config
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateRedisConfig(array $config): void
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;

        if (empty($host)) {
            throw new \InvalidArgumentException('Redis host is required');
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('Redis port must be between 1 and 65535');
        }

        $timeout = $config['timeout'] ?? null;
        if ($timeout !== null && $timeout < 0) {
            throw new \InvalidArgumentException('Redis timeout must be non-negative');
        }
    }

    /**
     * Validate Kafka configuration.
     *
     * @param array<string, mixed> $config
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateKafkaConfig(array $config): void
    {
        $brokers = $config['brokers'] ?? [];

        if (empty($brokers)) {
            throw new \InvalidArgumentException('Kafka brokers list is required');
        }

        if (is_string($brokers)) {
            $brokers = explode(',', $brokers);
        }

        if (!is_array($brokers)) {
            throw new \InvalidArgumentException('Kafka brokers must be array or comma-separated string');
        }

        foreach ($brokers as $broker) {
            if (empty($broker)) {
                throw new \InvalidArgumentException('Kafka broker address cannot be empty');
            }
        }

        // Validate consumer group
        $consumerGroup = $config['consumer_group'] ?? null;
        if (!empty($consumerGroup) && !is_string($consumerGroup)) {
            throw new \InvalidArgumentException('Kafka consumer_group must be string');
        }
    }

    /**
     * Validate RabbitMQ configuration.
     *
     * @param array<string, mixed> $config
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateRabbitMqConfig(array $config): void
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;

        if (empty($host)) {
            throw new \InvalidArgumentException('RabbitMQ host is required');
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('RabbitMQ port must be between 1 and 65535');
        }

        // Validate prefetch count
        $prefetch = $config['prefetch_count'] ?? null;
        if ($prefetch !== null && $prefetch < 1) {
            throw new \InvalidArgumentException('RabbitMQ prefetch_count must be positive integer');
        }
    }

    /**
     * Get supported drivers.
     *
     * @return array<string>
     */
    public static function getSupportedDrivers(): array
    {
        return self::SUPPORTED_DRIVERS;
    }

    /**
     * Check if driver is supported.
     *
     * @param string $driver Driver name
     * @return bool
     */
    public static function isSupported(string $driver): bool
    {
        $normalizedDriver = str_replace('-improved', '', $driver);
        return in_array($normalizedDriver, self::SUPPORTED_DRIVERS, true);
    }

    /**
     * Get driver capabilities.
     *
     * @param string $driver Driver name
     * @return array<string, bool>
     */
    public static function getCapabilities(string $driver): array
    {
        return [
            'connection_pooling' => true,
            'circuit_breaker' => true,
            'auto_reconnect' => true,
            'metrics' => true,
            'memory_management' => true,
            'backpressure' => str_contains($driver, 'kafka'),
            'channel_pooling' => str_contains($driver, 'rabbitmq'),
            'shared_memory_queue' => str_contains($driver, 'kafka'),
            'batch_processing' => true,
        ];
    }
}
