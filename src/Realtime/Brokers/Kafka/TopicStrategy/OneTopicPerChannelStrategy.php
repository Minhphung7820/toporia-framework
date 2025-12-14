<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\TopicStrategy;

use Toporia\Framework\Realtime\Contracts\TopicStrategyInterface;

/**
 * Class OneTopicPerChannelStrategy
 *
 * Legacy strategy: Each channel maps to its own topic. Used for backward compatibility.
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
final class OneTopicPerChannelStrategy implements TopicStrategyInterface
{
    public function __construct(
        private readonly string $topicPrefix = 'realtime'
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getTopicName(string $channel): string
    {
        // Sanitize channel name for Kafka topic naming
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $channel);
        return "{$this->topicPrefix}_{$sanitized}";
    }

    /**
     * {@inheritdoc}
     */
    public function getPartition(string $channel, int $totalPartitions): int
    {
        // Each topic has 1 partition (legacy behavior)
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getMessageKey(string $channel): ?string
    {
        // No key needed (each channel = 1 topic)
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getTopicsForChannels(array $channels): array
    {
        $topics = [];
        foreach ($channels as $channel) {
            $topics[] = $this->getTopicName($channel);
        }
        return array_unique($topics);
    }

    /**
     * {@inheritdoc}
     */
    public function getPartitionCount(string $channel): int
    {
        // Legacy strategy: 1 partition per topic
        return 1;
    }
}

