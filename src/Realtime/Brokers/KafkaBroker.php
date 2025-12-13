<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

use Toporia\Framework\Realtime\Contracts\{BrokerInterface, HealthCheckableInterface, HealthCheckResult, MessageInterface};
use Toporia\Framework\Realtime\{Message, RealtimeManager};
use Toporia\Framework\Realtime\Contracts\TopicStrategyInterface;
use Toporia\Framework\Realtime\Brokers\Kafka\TopicStrategy\{GroupedTopicStrategy, TopicStrategyFactory};
use Toporia\Framework\Realtime\Brokers\Kafka\Client\{KafkaClientFactory, KafkaClientInterface, KafkaMessage};
use Toporia\Framework\Realtime\Exceptions\{BrokerException, BrokerTemporaryException};

/**
 * Class KafkaBroker
 *
 * Apache Kafka broker for high-throughput, persistent realtime communication. Enables horizontal scaling with message replay and history support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class KafkaBroker implements BrokerInterface, HealthCheckableInterface
{
    private KafkaClientInterface $client;
    private TopicStrategyInterface $topicStrategy;
    private bool $connected = false;

    /**
     * @var array<string, array<string, callable>> Channel subscriptions [topic => [channel => callback]]
     */
    private array $subscriptions = [];

    /**
     * @var array<string, int> Partition cache [topic:channel => partition]
     */
    private array $partitionCache = [];

    /**
     * @param array<string, mixed> $config Kafka configuration
     * @param RealtimeManager|null $manager Realtime manager instance
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?RealtimeManager $manager = null
    ) {
        $this->client = KafkaClientFactory::create($config);
        $this->topicStrategy = TopicStrategyFactory::create($config);
        $this->initialize();
    }

    /**
     * Initialize Kafka client connection.
     */
    private function initialize(): void
    {
        $this->client->connect();
        $this->connected = true;
    }

    /**
     * {@inheritdoc}
     */
    public function publish(string $channel, MessageInterface $message): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('kafka');
        }

        $topicName = $this->getTopicName($channel);
        $key = $this->getMessageKey($channel);
        $payload = $message->toJson();

        // Use null partition to let Kafka auto-assign based on key
        // This avoids "Unknown partition" errors when topic has fewer partitions than calculated
        $partition = null;

        try {
            $this->client->publish($topicName, $payload, $partition, $key);
        } catch (BrokerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw BrokerException::publishFailed('kafka', $channel, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(string $channel, callable $callback): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('kafka');
        }

        $topicName = $this->getTopicName($channel);

        // Store callback with channel mapping
        if (!isset($this->subscriptions[$topicName])) {
            $this->subscriptions[$topicName] = [];
        }
        $this->subscriptions[$topicName][$channel] = $callback;
    }

    /**
     * Start consuming messages from subscribed topics.
     *
     * @param int $timeoutMs Poll timeout in milliseconds
     * @param int $batchSize Maximum messages per batch
     */
    public function consume(int $timeoutMs = 1000, int $batchSize = 100): void
    {
        if (empty($this->subscriptions)) {
            return;
        }

        $topics = array_keys($this->subscriptions);

        try {
            $this->client->subscribe($topics);
        } catch (\Throwable $e) {
            throw BrokerException::subscribeFailed('kafka', implode(',', $topics), $e->getMessage(), $e);
        }

        $this->client->consume(
            callback: fn(KafkaMessage $msg) => $this->processMessage($msg),
            timeoutMs: $timeoutMs,
            batchSize: $batchSize
        );
    }

    /**
     * Process a consumed Kafka message.
     *
     * @param KafkaMessage $kafkaMessage
     * @return bool True to continue consuming
     */
    private function processMessage(KafkaMessage $kafkaMessage): bool
    {
        if ($kafkaMessage->hasError()) {
            return true; // Continue on error (handled by client)
        }

        $topicName = $kafkaMessage->topic;
        $payload = $kafkaMessage->payload;
        $messageKey = $kafkaMessage->key;

        if (empty($topicName) || empty($payload)) {
            return true;
        }

        $subscriptions = $this->subscriptions[$topicName] ?? null;
        if (!$subscriptions) {
            return true;
        }

        try {
            $message = Message::fromJson($payload);
            $channel = $messageKey ?? $this->extractChannelFromTopic($topicName);

            // Find and execute callback
            $callback = $subscriptions[$channel] ?? null;

            if ($callback !== null) {
                $callback($message);
            } else {
                // Fallback: execute first available callback
                foreach ($subscriptions as $cb) {
                    if (is_callable($cb)) {
                        $cb($message);
                        break;
                    }
                }
            }

            // Commit offset after successful processing
            if ($this->config['manual_commit'] ?? false) {
                $this->client->commit($kafkaMessage);
            }

        } catch (\Throwable $e) {
            error_log("Kafka message processing error on {$topicName}: {$e->getMessage()}");
            // Don't re-throw - let consumer continue
        }

        return true;
    }

    /**
     * Stop consuming messages.
     */
    public function stopConsuming(): void
    {
        $this->client->stopConsuming();
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(string $channel): void
    {
        $topicName = $this->getTopicName($channel);

        if (isset($this->subscriptions[$topicName][$channel])) {
            unset($this->subscriptions[$topicName][$channel]);

            if (empty($this->subscriptions[$topicName])) {
                unset($this->subscriptions[$topicName]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriberCount(string $channel): int
    {
        $topicName = $this->getTopicName($channel);
        return isset($this->subscriptions[$topicName][$channel]) ? 1 : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->client->isConnected();
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        $this->client->disconnect();
        $this->connected = false;
        $this->subscriptions = [];
        $this->partitionCache = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'kafka';
    }

    /**
     * Get the underlying Kafka client.
     *
     * @return KafkaClientInterface
     */
    public function getClient(): KafkaClientInterface
    {
        return $this->client;
    }

    /**
     * Get Kafka topic name for a channel.
     *
     * @param string $channel Channel name
     * @return string Topic name
     */
    private function getTopicName(string $channel): string
    {
        return $this->topicStrategy->getTopicName($channel);
    }

    /**
     * Get partition number for a channel.
     *
     * @param string $channel Channel name
     * @param string $topicName Topic name
     * @return int Partition number
     */
    private function getPartition(string $channel, string $topicName): int
    {
        $cacheKey = "{$topicName}:{$channel}";

        if (isset($this->partitionCache[$cacheKey])) {
            return $this->partitionCache[$cacheKey];
        }

        $partitionCount = (int) ($this->config['default_partitions'] ?? 10);

        if ($this->topicStrategy instanceof GroupedTopicStrategy) {
            $partitionCount = $this->topicStrategy->getPartitionCount($channel);
        }

        $partition = $this->topicStrategy->getPartition($channel, $partitionCount);
        $this->partitionCache[$cacheKey] = $partition;

        return $partition;
    }

    /**
     * Get message key for partitioning.
     *
     * @param string $channel Channel name
     * @return string|null Message key
     */
    private function getMessageKey(string $channel): ?string
    {
        return $this->topicStrategy->getMessageKey($channel);
    }

    /**
     * Extract channel name from topic name.
     *
     * @param string $topicName Topic name
     * @return string Channel name
     */
    private function extractChannelFromTopic(string $topicName): string
    {
        $prefix = $this->config['topic_prefix'] ?? 'realtime';

        if (str_starts_with($topicName, $prefix . '_')) {
            return substr($topicName, strlen($prefix) + 1);
        }

        return $topicName;
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(): HealthCheckResult
    {
        if (!$this->connected) {
            return HealthCheckResult::unhealthy('Kafka broker not connected');
        }

        $start = microtime(true);

        try {
            // Check if client is connected
            if (!$this->client->isConnected()) {
                return HealthCheckResult::unhealthy('Kafka client connection lost');
            }

            $latencyMs = (microtime(true) - $start) * 1000;

            return HealthCheckResult::healthy(
                message: 'Kafka connection healthy',
                details: [
                    'client' => $this->client->getName(),
                    'subscriptions' => count($this->subscriptions),
                    'topics' => array_keys($this->subscriptions),
                ],
                latencyMs: $latencyMs
            );
        } catch (\Throwable $e) {
            return HealthCheckResult::unhealthy(
                message: "Kafka health check failed: {$e->getMessage()}",
                details: ['exception' => $e::class]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHealthCheckName(): string
    {
        return 'kafka-broker';
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
