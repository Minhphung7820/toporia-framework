<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\Client;

use Toporia\Framework\Realtime\Contracts\MessageInterface;

/**
 * Interface KafkaClientInterface
 *
 * Abstracts the underlying Kafka client library (rdkafka or kafka-php).
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
interface KafkaClientInterface
{
    /**
     * Get client name.
     *
     * @return string Client identifier ('rdkafka' or 'kafka-php')
     */
    public function getName(): string;

    /**
     * Check if client is connected.
     *
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * Connect to Kafka brokers.
     *
     * @return void
     * @throws \Toporia\Framework\Realtime\Exceptions\BrokerException On connection failure
     */
    public function connect(): void;

    /**
     * Disconnect from Kafka brokers.
     *
     * @return void
     */
    public function disconnect(): void;

    /**
     * Publish a message to a topic.
     *
     * @param string $topic Topic name
     * @param string $payload Message payload
     * @param int|null $partition Target partition (null for auto)
     * @param string|null $key Message key for partitioning
     * @return void
     * @throws \Toporia\Framework\Realtime\Exceptions\BrokerException On publish failure
     */
    public function publish(string $topic, string $payload, ?int $partition = null, ?string $key = null): void;

    /**
     * Flush pending messages.
     *
     * @param int $timeoutMs Flush timeout in milliseconds
     * @return void
     */
    public function flush(int $timeoutMs = 5000): void;

    /**
     * Subscribe to topics.
     *
     * @param array<string> $topics Topic names
     * @return void
     * @throws \Toporia\Framework\Realtime\Exceptions\BrokerException On subscribe failure
     */
    public function subscribe(array $topics): void;

    /**
     * Consume messages in a loop.
     *
     * @param callable(KafkaMessage): bool $callback Message handler, return false to stop
     * @param int $timeoutMs Poll timeout in milliseconds
     * @param int $batchSize Maximum messages per batch
     * @return void
     */
    public function consume(callable $callback, int $timeoutMs = 1000, int $batchSize = 100): void;

    /**
     * Stop consuming.
     *
     * @return void
     */
    public function stopConsuming(): void;

    /**
     * Commit offset for a message.
     *
     * @param KafkaMessage $message Message to commit
     * @return void
     */
    public function commit(KafkaMessage $message): void;
}
