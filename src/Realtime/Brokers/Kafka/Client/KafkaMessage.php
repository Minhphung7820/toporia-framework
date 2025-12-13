<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\Client;

/**
 * Class KafkaMessage
 *
 * Represents a Kafka message in a client-agnostic format.
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
final class KafkaMessage
{
    /**
     * @param string $topic Topic name
     * @param string $payload Message payload
     * @param int $partition Partition number
     * @param int $offset Message offset
     * @param string|null $key Message key
     * @param int $timestamp Message timestamp (ms)
     * @param int $errorCode Error code (0 = no error)
     * @param string $errorMessage Error message
     * @param mixed $raw Raw message from underlying library
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $payload,
        public readonly int $partition,
        public readonly int $offset,
        public readonly ?string $key = null,
        public readonly int $timestamp = 0,
        public readonly int $errorCode = 0,
        public readonly string $errorMessage = '',
        public readonly mixed $raw = null
    ) {
    }

    /**
     * Check if message has an error.
     *
     * @return bool
     */
    public function hasError(): bool
    {
        return $this->errorCode !== 0;
    }

    /**
     * Check if error is EOF (end of partition).
     *
     * @return bool
     */
    public function isEof(): bool
    {
        // RD_KAFKA_RESP_ERR__PARTITION_EOF = -191
        return $this->errorCode === -191;
    }

    /**
     * Check if error is timeout.
     *
     * @return bool
     */
    public function isTimeout(): bool
    {
        // RD_KAFKA_RESP_ERR__TIMED_OUT = -185
        return $this->errorCode === -185;
    }

    /**
     * Check if error is unknown topic/partition.
     *
     * @return bool
     */
    public function isUnknownTopicOrPartition(): bool
    {
        // RD_KAFKA_RESP_ERR_UNKNOWN_TOPIC_OR_PART = 3
        return $this->errorCode === 3;
    }

    /**
     * Create from rdkafka message.
     *
     * @param \RdKafka\Message $message
     * @return static
     */
    public static function fromRdKafka(\RdKafka\Message $message): static
    {
        // Safely extract properties, handling precision issues
        $topic = '';
        $payload = '';
        $key = null;
        $partition = 0;
        $offset = 0;
        $timestamp = 0;

        try {
            $topic = (string) ($message->topic_name ?? '');
            $payload = (string) ($message->payload ?? '');
            $key = $message->key !== null ? (string) $message->key : null;
            $partition = (int) ($message->partition ?? 0);
            // Handle large offsets safely
            $offset = is_numeric($message->offset) ? (int) $message->offset : 0;
            $timestamp = is_numeric($message->timestamp) ? (int) $message->timestamp : 0;
        } catch (\Throwable) {
            // Silently handle precision loss warnings
        }

        return new static(
            topic: $topic,
            payload: $payload,
            partition: $partition,
            offset: $offset,
            key: $key,
            timestamp: $timestamp,
            errorCode: $message->err,
            errorMessage: $message->errstr(),
            raw: $message
        );
    }

    /**
     * Create from kafka-php message array.
     *
     * @param string $topic Topic name
     * @param int $partition Partition number
     * @param array<string, mixed> $message Message data
     * @return static
     */
    public static function fromKafkaPhp(string $topic, int $partition, array $message): static
    {
        return new static(
            topic: $topic,
            payload: (string) ($message['value'] ?? ''),
            partition: $partition,
            offset: (int) ($message['offset'] ?? 0),
            key: isset($message['key']) ? (string) $message['key'] : null,
            timestamp: (int) ($message['timestamp'] ?? 0),
            errorCode: 0,
            errorMessage: '',
            raw: $message
        );
    }
}
