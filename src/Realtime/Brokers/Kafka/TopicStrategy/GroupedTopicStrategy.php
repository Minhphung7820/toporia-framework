<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\TopicStrategy;

use Toporia\Framework\Realtime\Contracts\TopicStrategyInterface;

/**
 * Class GroupedTopicStrategy
 *
 * Groups channels into fewer topics with partitioning. Recommended for production use.
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
final class GroupedTopicStrategy implements TopicStrategyInterface
{
    /**
     * @var array<string, array{topic: string, partitions: int}> Channel pattern → topic config
     */
    private array $topicMapping = [];

    /**
     * @var string Default topic name
     */
    private string $defaultTopic;

    /**
     * @var int Default number of partitions
     */
    private int $defaultPartitions;

    /**
     * @param array<string, array{topic: string, partitions: int}> $topicMapping Channel patterns → topic config
     * @param string $defaultTopic Default topic name
     * @param int $defaultPartitions Default number of partitions
     */
    public function __construct(
        array $topicMapping = [],
        string $defaultTopic = 'realtime',
        int $defaultPartitions = 10
    ) {
        $this->topicMapping = $topicMapping;
        $this->defaultTopic = $defaultTopic;
        $this->defaultPartitions = $defaultPartitions;
    }

    /**
     * {@inheritdoc}
     */
    public function getTopicName(string $channel): string
    {
        // Find matching pattern
        foreach ($this->topicMapping as $pattern => $config) {
            if ($this->matchesPattern($channel, $pattern)) {
                return $config['topic'];
            }
        }

        return $this->defaultTopic;
    }

    /**
     * {@inheritdoc}
     */
    public function getPartition(string $channel, int $totalPartitions): int
    {
        // Use consistent hashing based on channel name
        // This ensures same channel always goes to same partition
        $hash = crc32($channel);
        return abs($hash) % $totalPartitions;
    }

    /**
     * {@inheritdoc}
     */
    public function getMessageKey(string $channel): ?string
    {
        // Use channel as key for consistent partitioning
        // Messages with same key go to same partition
        return $channel;
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
     * Get number of partitions for a topic.
     *
     * @param string $channel Channel name
     * @return int Number of partitions
     */
    public function getPartitionCount(string $channel): int
    {
        foreach ($this->topicMapping as $pattern => $config) {
            if ($this->matchesPattern($channel, $pattern)) {
                return $config['partitions'] ?? $this->defaultPartitions;
            }
        }

        return $this->defaultPartitions;
    }

    /**
     * Check if channel matches pattern.
     *
     * @param string $channel Channel name
     * @param string $pattern Pattern (supports * wildcard)
     * @return bool
     */
    private function matchesPattern(string $channel, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        // IMPORTANT: Replace '.' first, then '*' to avoid conflicts
        // Pattern: 'orders.*' -> 'orders\..*' (correct)
        // If we replace '*' first: 'orders.*' -> 'orders..*' (wrong!)
        $regex = str_replace(['.', '*'], ['\\.', '.*'], $pattern);
        return (bool) preg_match("/^{$regex}$/", $channel);
    }
}
