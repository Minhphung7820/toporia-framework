<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka\Client;

use Toporia\Framework\Realtime\Exceptions\BrokerException;

/**
 * Class KafkaPhpClient
 *
 * Kafka client adapter using nmred/kafka-php library. Pure PHP client for Kafka.
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
final class KafkaPhpClient implements KafkaClientInterface
{
    private ?\Kafka\Producer $producer = null;
    private ?\Kafka\Consumer $consumer = null;
    private bool $connected = false;
    private bool $consuming = false;

    /**
     * @param array<string> $brokers Broker addresses
     * @param string $consumerGroup Consumer group ID
     * @param array<string, string> $producerConfig Additional producer config
     * @param array<string, string> $consumerConfig Additional consumer config
     */
    public function __construct(
        private readonly array $brokers,
        private readonly string $consumerGroup = 'realtime-servers',
        private readonly array $producerConfig = [],
        private readonly array $consumerConfig = []
    ) {
        if (empty($this->brokers)) {
            throw BrokerException::invalidConfiguration('kafka', 'Broker list is required');
        }
    }

    public function getName(): string
    {
        return 'kafka-php';
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        if (!class_exists(\Kafka\ProducerConfig::class)) {
            throw BrokerException::invalidConfiguration('kafka', 'nmred/kafka-php library not found');
        }

        $brokerList = implode(',', array_filter($this->brokers, fn($b) => !empty($b)));

        if (empty($brokerList)) {
            throw BrokerException::invalidConfiguration(
                'kafka',
                'Broker list is empty. Configure KAFKA_BROKERS or set in config/realtime.php'
            );
        }

        // Configure producer
        /** @var \Kafka\ProducerConfig $producerConfig */
        $producerConfig = \Kafka\ProducerConfig::getInstance();
        $producerConfig->clear();

        try {
            $producerConfig->setMetadataBrokerList($brokerList);
        } catch (\Kafka\Exception\Config $e) {
            throw BrokerException::invalidConfiguration('kafka', $e->getMessage());
        }

        // Apply default performance settings
        $defaults = [
            'compression.type' => 'gzip',
            'batch.size' => '16384',
            'linger.ms' => '10',
        ];

        foreach (array_merge($defaults, $this->producerConfig) as $key => $value) {
            try {
                $producerConfig->set($key, $value);
            } catch (\Throwable) {
                // Ignore invalid config keys
            }
        }

        $this->connected = true;
    }

    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        $this->stopConsuming();
        $this->producer = null;
        $this->consumer = null;
        $this->connected = false;
    }

    public function publish(string $topic, string $payload, ?int $partition = null, ?string $key = null): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('kafka');
        }

        // Lazy initialize producer
        if ($this->producer === null) {
            $this->producer = new \Kafka\Producer();
        }

        try {
            $this->producer->send([
                [
                    'topic' => $topic,
                    'value' => $payload,
                    'partition' => $partition ?? 0,
                    'key' => $key,
                ]
            ]);
        } catch (\Throwable $e) {
            throw BrokerException::publishFailed('kafka', $topic, $e->getMessage(), $e);
        }
    }

    public function flush(int $timeoutMs = 5000): void
    {
        // kafka-php doesn't have explicit flush - sends are synchronous
    }

    public function subscribe(array $topics): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('kafka');
        }

        // kafka-php handles subscription during consume()
        // Store topics for later use
    }

    public function consume(callable $callback, int $timeoutMs = 1000, int $batchSize = 100): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('kafka');
        }

        $this->consuming = true;

        // Configure consumer
        /** @var \Kafka\ConsumerConfig $consumerConfig */
        $consumerConfig = \Kafka\ConsumerConfig::getInstance();
        $consumerConfig->clear();

        $brokerList = implode(',', $this->brokers);

        try {
            $consumerConfig->setMetadataBrokerList($brokerList);
        } catch (\Kafka\Exception\Config $e) {
            throw BrokerException::invalidConfiguration('kafka', $e->getMessage());
        }

        $consumerConfig->setGroupId($this->consumerGroup);
        $consumerConfig->setOffsetReset('earliest');
        $consumerConfig->setMaxBytes(1024 * 1024);

        foreach ($this->consumerConfig as $key => $value) {
            try {
                $consumerConfig->set($key, $value);
            } catch (\Throwable) {
                // Ignore invalid config keys
            }
        }

        $this->consumer = new \Kafka\Consumer();

        try {
            $this->consumer->start(function ($topic, $partition, $message) use ($callback) {
                if (!$this->consuming) {
                    return false;
                }

                $kafkaMessage = KafkaMessage::fromKafkaPhp($topic, $partition, $message);
                return $callback($kafkaMessage);
            }, true);
        } catch (\Kafka\Exception $e) {
            throw BrokerException::consumeFailed('kafka', $e->getMessage(), $e);
        }
    }

    public function stopConsuming(): void
    {
        $this->consuming = false;
    }

    public function commit(KafkaMessage $message): void
    {
        // kafka-php handles commits automatically
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
