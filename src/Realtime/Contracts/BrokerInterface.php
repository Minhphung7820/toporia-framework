<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Contracts;


/**
 * Interface BrokerInterface
 *
 * Contract defining the interface for BrokerInterface implementations in
 * the Real-time broadcasting layer of the Toporia Framework.
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
interface BrokerInterface
{
    /**
     * Publish message to a channel.
     *
     * Sends message to all subscribers of the channel across all servers.
     *
     * Performance: O(1) for Redis/NATS, O(log N) for Kafka
     *
     * @param string $channel Channel name
     * @param MessageInterface $message Message to publish
     * @return void
     */
    public function publish(string $channel, MessageInterface $message): void;

    /**
     * Subscribe to a channel.
     *
     * Receives messages published to the channel.
     * Callback is invoked for each message.
     *
     * Performance: O(1) subscription setup
     *
     * @param string $channel Channel name (supports wildcards for NATS)
     * @param callable $callback Invoked with (MessageInterface $message)
     * @return void
     */
    public function subscribe(string $channel, callable $callback): void;

    /**
     * Unsubscribe from a channel.
     *
     * @param string $channel Channel name
     * @return void
     */
    public function unsubscribe(string $channel): void;

    /**
     * Get number of subscribers for a channel.
     *
     * @param string $channel Channel name
     * @return int Subscriber count
     */
    public function getSubscriberCount(string $channel): int;

    /**
     * Check if broker is connected.
     *
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * Disconnect from broker.
     *
     * @return void
     */
    public function disconnect(): void;

    /**
     * Get broker name.
     *
     * @return string (redis, rabbitmq, nats, kafka, postgres)
     */
    public function getName(): string;

    /**
     * Publish multiple messages in a single batch operation.
     *
     * Optimized for high-throughput scenarios. Messages are:
     * - Grouped by topic/partition
     * - Compressed together (lz4/snappy)
     * - Sent in minimal network requests
     *
     * Performance: 10-100x faster than individual publish() calls
     *
     * @param array<array{channel: string, message: MessageInterface}> $messages
     * @param int $flushTimeoutMs Timeout for final flush
     * @return array{queued: int, failed: int, total_time_ms: float, throughput: int}
     */
    public function publishBatch(array $messages, int $flushTimeoutMs = 10000): array;
}
