<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

use Toporia\Framework\Realtime\Contracts\BrokerInterface;
use Toporia\Framework\Realtime\RealtimeManager;

/**
 * Class BrokerFactory
 *
 * Factory for creating broker instances with validation and optimization.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
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
        // Legacy (v1)
        'redis',
        'kafka',
        'rabbitmq',

        // Improved (v2)
        'redis-improved',
        'kafka-improved',
        'rabbitmq-improved',
    ];

    /**
     * Recommended drivers for production.
     */
    private const RECOMMENDED_DRIVERS = [
        'redis-improved',
        'kafka-improved',
        'rabbitmq-improved',
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
        // Validate driver
        if (!in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new \InvalidArgumentException(
                "Unsupported broker driver: {$driver}. " .
                    "Supported: " . implode(', ', self::SUPPORTED_DRIVERS)
            );
        }

        // Validate configuration
        self::validateConfig($driver, $config);

        // Warn if using legacy driver in production
        if (!in_array($driver, self::RECOMMENDED_DRIVERS, true) && self::isProduction()) {
            error_log(
                "WARNING: Using legacy broker driver '{$driver}' in production. " .
                    "Consider upgrading to '{$driver}-improved' for better performance, reliability, and observability."
            );
        }

        // Create broker instance
        return match ($driver) {
            // Legacy brokers (v1)
            'redis' => new RedisBroker($config, $manager),
            'kafka' => new KafkaBroker($config, $manager),
            'rabbitmq' => new RabbitMqBroker($config, $manager),

            // Improved brokers (v2) - Production-ready
            'redis-improved' => new RedisBrokerImproved($config, $manager),
            'kafka-improved' => new KafkaBrokerImproved($config, $manager),
            'rabbitmq-improved' => new RabbitMqBrokerImproved($config, $manager),
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
        // Common validations
        if (str_contains($driver, 'redis')) {
            self::validateRedisConfig($config);
        } elseif (str_contains($driver, 'kafka')) {
            self::validateKafkaConfig($config);
        } elseif (str_contains($driver, 'rabbitmq')) {
            self::validateRabbitMqConfig($config);
        }

        // Improved brokers specific validations
        if (str_ends_with($driver, '-improved')) {
            self::validateImprovedConfig($config);
        }
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
     * Validate improved broker configuration.
     *
     * @param array<string, mixed> $config
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateImprovedConfig(array $config): void
    {
        // Validate circuit breaker settings
        $threshold = $config['circuit_breaker_threshold'] ?? null;
        if ($threshold !== null && $threshold < 1) {
            throw new \InvalidArgumentException('circuit_breaker_threshold must be positive integer');
        }

        $timeout = $config['circuit_breaker_timeout'] ?? null;
        if ($timeout !== null && $timeout < 1) {
            throw new \InvalidArgumentException('circuit_breaker_timeout must be positive integer');
        }

        // Validate channel pool for RabbitMQ
        $maxChannels = $config['max_channels'] ?? null;
        if ($maxChannels !== null && $maxChannels < 1) {
            throw new \InvalidArgumentException('max_channels must be positive integer');
        }

        if ($maxChannels !== null && $maxChannels > 100) {
            error_log('WARNING: max_channels > 100 may cause resource exhaustion. Recommended: 10-20');
        }
    }

    /**
     * Check if running in production environment.
     *
     * @return bool
     */
    private static function isProduction(): bool
    {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        return in_array(strtolower($env), ['production', 'prod'], true);
    }

    /**
     * Get recommended driver for broker type.
     *
     * @param string $type Broker type (redis, kafka, rabbitmq)
     * @return string Recommended driver
     */
    public static function getRecommendedDriver(string $type): string
    {
        return match (strtolower($type)) {
            'redis' => 'redis-improved',
            'kafka' => 'kafka-improved',
            'rabbitmq' => 'rabbitmq-improved',
            default => throw new \InvalidArgumentException("Unknown broker type: {$type}")
        };
    }

    /**
     * Check if driver is improved version.
     *
     * @param string $driver Driver name
     * @return bool
     */
    public static function isImprovedDriver(string $driver): bool
    {
        return str_ends_with($driver, '-improved');
    }

    /**
     * Get driver capabilities.
     *
     * @param string $driver Driver name
     * @return array<string, bool>
     */
    public static function getCapabilities(string $driver): array
    {
        $isImproved = self::isImprovedDriver($driver);

        return [
            'connection_pooling' => $isImproved,
            'circuit_breaker' => $isImproved,
            'auto_reconnect' => $isImproved,
            'metrics' => $isImproved,
            'memory_management' => $isImproved,
            'backpressure' => $isImproved && str_contains($driver, 'kafka'),
            'channel_pooling' => $isImproved && str_contains($driver, 'rabbitmq'),
        ];
    }
}
