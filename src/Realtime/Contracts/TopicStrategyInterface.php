<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Contracts;


/**
 * Interface TopicStrategyInterface
 *
 * Contract defining the interface for TopicStrategyInterface
 * implementations in the Real-time broadcasting layer of the Toporia
 * Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface TopicStrategyInterface
{
    /**
     * Get topic name for a channel.
     *
     * @param string $channel Channel name
     * @return string Topic name
     */
    public function getTopicName(string $channel): string;

    /**
     * Get partition number for a channel.
     *
     * @param string $channel Channel name
     * @param int $totalPartitions Total partitions in topic
     * @return int Partition number (0-based)
     */
    public function getPartition(string $channel, int $totalPartitions): int;

    /**
     * Get message key for a channel.
     *
     * Used for partitioning and message ordering.
     *
     * @param string $channel Channel name
     * @return string|null Message key (null = no key, Kafka will round-robin)
     */
    public function getMessageKey(string $channel): ?string;

    /**
     * Get all topics that match a channel pattern.
     *
     * Used for subscribing to multiple channels.
     *
     * @param array<string> $channels Channel names
     * @return array<string> Unique topic names
     */
    public function getTopicsForChannels(array $channels): array;

    /**
     * Get number of partitions for a channel's topic.
     *
     * @param string $channel Channel name
     * @return int Number of partitions
     */
    public function getPartitionCount(string $channel): int;
}
