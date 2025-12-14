<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\Client;

use Toporia\Framework\Realtime\Exceptions\BrokerException;

/**
 * Class KafkaClientFactory
 *
 * Factory for creating Kafka client instances using rdkafka extension.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers\Kafka\Client
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class KafkaClientFactory
{
    /**
     * Create a Kafka client based on configuration.
     *
     * Requires rdkafka extension for high-performance Kafka operations.
     *
     * @param array<string, mixed> $config Broker configuration
     * @return KafkaClientInterface
     * @throws BrokerException If rdkafka extension is not available
     */
    public static function create(array $config): KafkaClientInterface
    {
        $brokers = self::normalizeBrokers($config['brokers'] ?? ['localhost:9092']);
        $consumerGroup = (string) ($config['consumer_group'] ?? 'realtime-servers');
        $manualCommit = (bool) ($config['manual_commit'] ?? false);
        $producerConfig = self::sanitizeConfig($config['producer_config'] ?? []);
        $consumerConfig = self::sanitizeConfig($config['consumer_config'] ?? []);

        if (!self::isAvailable()) {
            throw BrokerException::invalidConfiguration(
                'kafka',
                "rdkafka extension not found. Install via: pecl install rdkafka"
            );
        }

        return new RdKafkaClient(
            brokers: $brokers,
            consumerGroup: $consumerGroup,
            manualCommit: $manualCommit,
            producerConfig: $producerConfig,
            consumerConfig: $consumerConfig
        );
    }

    /**
     * Check if rdkafka extension is available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('rdkafka') && class_exists(\RdKafka\Producer::class);
    }

    /**
     * Get available client name.
     *
     * @return string|null Client name or null if not available
     */
    public static function getAvailableClient(): ?string
    {
        return self::isAvailable() ? 'rdkafka' : null;
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
