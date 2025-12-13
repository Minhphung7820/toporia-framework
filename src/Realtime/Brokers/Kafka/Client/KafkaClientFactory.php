<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\Client;

use Toporia\Framework\Realtime\Exceptions\BrokerException;

/**
 * Class KafkaClientFactory
 *
 * Factory for creating Kafka client instances. Automatically selects the best available Kafka client library.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers\Kafka\Client
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class KafkaClientFactory
{
    /**
     * Create a Kafka client based on configuration and availability.
     *
     * @param array<string, mixed> $config Broker configuration
     * @return KafkaClientInterface
     * @throws BrokerException If no Kafka client is available
     */
    public static function create(array $config): KafkaClientInterface
    {
        $preference = strtolower((string) ($config['client'] ?? 'auto'));
        $brokers = self::normalizeBrokers($config['brokers'] ?? ['localhost:9092']);
        $consumerGroup = (string) ($config['consumer_group'] ?? 'realtime-servers');
        $manualCommit = (bool) ($config['manual_commit'] ?? false);
        $bufferSize = (int) ($config['buffer_size'] ?? 100);
        $flushIntervalMs = (int) ($config['flush_interval_ms'] ?? 100);
        $producerConfig = self::sanitizeConfig($config['producer_config'] ?? []);
        $consumerConfig = self::sanitizeConfig($config['consumer_config'] ?? []);

        $rdkafkaAvailable = extension_loaded('rdkafka') && class_exists(\RdKafka\Producer::class);
        $phpClientAvailable = class_exists(\Kafka\Producer::class);

        if (!$rdkafkaAvailable && !$phpClientAvailable) {
            throw BrokerException::invalidConfiguration(
                'kafka',
                "No Kafka client library found. Install either:\n" .
                "  1. rdkafka extension (recommended): pecl install rdkafka\n" .
                "  2. nmred/kafka-php: composer require nmred/kafka-php"
            );
        }

        $useRdKafka = match ($preference) {
            'rdkafka' => $rdkafkaAvailable,
            'php', 'kafka-php', 'nmred' => !$phpClientAvailable && $rdkafkaAvailable,
            default => $rdkafkaAvailable, // auto: prefer rdkafka
        };

        if ($useRdKafka && $rdkafkaAvailable) {
            return new RdKafkaClient(
                brokers: $brokers,
                consumerGroup: $consumerGroup,
                manualCommit: $manualCommit,
                bufferSize: $bufferSize,
                flushIntervalMs: $flushIntervalMs,
                producerConfig: $producerConfig,
                consumerConfig: $consumerConfig
            );
        }

        if ($phpClientAvailable) {
            return new KafkaPhpClient(
                brokers: $brokers,
                consumerGroup: $consumerGroup,
                producerConfig: $producerConfig,
                consumerConfig: $consumerConfig
            );
        }

        throw BrokerException::invalidConfiguration('kafka', 'No compatible Kafka client found');
    }

    /**
     * Check if any Kafka client is available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return (extension_loaded('rdkafka') && class_exists(\RdKafka\Producer::class))
            || class_exists(\Kafka\Producer::class);
    }

    /**
     * Get available client name.
     *
     * @return string|null Client name or null if none available
     */
    public static function getAvailableClient(): ?string
    {
        if (extension_loaded('rdkafka') && class_exists(\RdKafka\Producer::class)) {
            return 'rdkafka';
        }

        if (class_exists(\Kafka\Producer::class)) {
            return 'kafka-php';
        }

        return null;
    }

    /**
     * Normalize broker list to array.
     *
     * @param mixed $brokers Broker configuration
     * @return array<string>
     */
    private static function normalizeBrokers(mixed $brokers): array
    {
        if (is_string($brokers)) {
            $brokers = explode(',', $brokers);
        }

        if (!is_array($brokers)) {
            $brokers = ['localhost:9092'];
        }

        return array_filter(
            array_map('trim', $brokers),
            fn($b) => !empty($b)
        );
    }

    /**
     * Sanitize Kafka configuration.
     *
     * Removes invalid/sentinel values.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private static function sanitizeConfig(array $config): array
    {
        $sanitized = [];

        foreach ($config as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $strValue = (string) $value;

            // Remove empty compression settings
            if (in_array($key, ['compression.type', 'compression.codec'])) {
                if ($strValue === '' || strcasecmp($strValue, 'none') === 0 || strcasecmp($strValue, 'off') === 0) {
                    continue;
                }
            }

            $sanitized[$key] = $strValue;
        }

        return $sanitized;
    }
}
