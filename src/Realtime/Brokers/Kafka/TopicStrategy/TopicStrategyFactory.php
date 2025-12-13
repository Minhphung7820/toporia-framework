<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\TopicStrategy;

use Toporia\Framework\Realtime\Contracts\TopicStrategyInterface;

/**
 * Class TopicStrategyFactory
 *
 * Factory for creating topic strategies based on configuration.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers\Kafka\TopicStrategy
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class TopicStrategyFactory
{
    /**
     * Create topic strategy from configuration.
     *
     * @param array<string, mixed> $config Kafka configuration
     * @return TopicStrategyInterface
     */
    public static function create(array $config): TopicStrategyInterface
    {
        $strategyType = $config['topic_strategy'] ?? 'grouped';
        $topicPrefix = $config['topic_prefix'] ?? 'realtime';

        return match ($strategyType) {
            'one-per-channel' => new OneTopicPerChannelStrategy($topicPrefix),
            'grouped' => self::createGroupedStrategy($config, $topicPrefix),
            default => throw new \InvalidArgumentException(
                "Unknown topic strategy: {$strategyType}. " .
                    "Supported: 'one-per-channel', 'grouped'"
            )
        };
    }

    /**
     * Create grouped topic strategy.
     *
     * @param array<string, mixed> $config Configuration
     * @param string $topicPrefix Topic prefix
     * @return GroupedTopicStrategy
     */
    private static function createGroupedStrategy(array $config, string $topicPrefix): GroupedTopicStrategy
    {
        $topicMapping = $config['topic_mapping'] ?? self::getDefaultTopicMapping($topicPrefix);
        $defaultTopic = $config['default_topic'] ?? $topicPrefix;
        $defaultPartitions = (int) ($config['default_partitions'] ?? 10);

        return new GroupedTopicStrategy(
            topicMapping: $topicMapping,
            defaultTopic: $defaultTopic,
            defaultPartitions: $defaultPartitions
        );
    }

    /**
     * Get default topic mapping.
     *
     * @param string $topicPrefix Topic prefix
     * @return array<string, array{topic: string, partitions: int}>
     */
    private static function getDefaultTopicMapping(string $topicPrefix): array
    {
        return [
            'user.*' => [
                'topic' => "{$topicPrefix}.user",
                'partitions' => 10,
            ],
            'public.*' => [
                'topic' => "{$topicPrefix}.public",
                'partitions' => 3,
            ],
            'presence-*' => [
                'topic' => "{$topicPrefix}.presence",
                'partitions' => 5,
            ],
            'chat.*' => [
                'topic' => "{$topicPrefix}.chat",
                'partitions' => 10,
            ],
        ];
    }
}
