<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Toporia\Framework\DateTime\Chronos;
use Toporia\Framework\Realtime\Brokers\CircuitBreaker\CircuitBreaker;
use Toporia\Framework\Realtime\Contracts\{BrokerInterface, HealthCheckableInterface, HealthCheckResult, MessageInterface};
use Toporia\Framework\Realtime\Exceptions\BrokerException;
use Toporia\Framework\Realtime\{Message, Metrics\BrokerMetrics, RealtimeManager};

/**
 * Class RabbitMqBroker
 *
 * RabbitMQ broker with channel pooling, better connection management, and reliability.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RabbitMqBroker implements BrokerInterface, HealthCheckableInterface
{
    private ?AMQPStreamConnection $connection = null;

    /**
     * @var array<AMQPChannel> Channel pool
     */
    private array $channelPool = [];

    private int $currentChannelIndex = 0;
    private string $exchange;
    private string $exchangeType;
    private bool $persistentMessages;
    private bool $connected = false;
    private bool $consuming = false;
    private ?string $queueName = null;
    private bool $consumerInitialized = false;
    private CircuitBreaker $circuitBreaker;
    private MemoryManager $memoryManager;

    /** @var array<string, callable> */
    private array $subscriptions = [];

    /** @var array<string, string> */
    private array $routingMap = [];

    /** @var array<int, string> */
    private array $consumerTags = [];

    private const DEFAULT_MAX_CHANNELS = 10;

    /**
     * @param array<string, mixed> $config
     * @param RealtimeManager|null $manager
     */
    public function __construct(
        private array $config = [],
        private readonly ?RealtimeManager $manager = null
    ) {
        $this->exchange = $config['exchange'] ?? 'realtime';
        $this->exchangeType = $config['exchange_type'] ?? 'topic';
        $this->persistentMessages = (bool) ($config['persistent_messages'] ?? true);

        $this->circuitBreaker = new CircuitBreaker(
            name: 'rabbitmq-broker',
            failureThreshold: $config['circuit_breaker_threshold'] ?? 5,
            timeout: $config['circuit_breaker_timeout'] ?? 60
        );

        $this->memoryManager = new MemoryManager();

        $this->connect();
    }

    /**
     * Establish AMQP connection & channel pool.
     *
     * @return void
     */
    private function connect(): void
    {
        try {
            $this->circuitBreaker->call(function () {
                $this->doConnect();
            });

            $this->connected = true;
            BrokerMetrics::recordConnectionEvent('rabbitmq', 'connect');
        } catch (\Throwable $e) {
            BrokerMetrics::recordConnectionEvent('rabbitmq', 'connect_failed');
            throw BrokerException::connectionFailed('rabbitmq', $e->getMessage(), $e);
        }
    }

    /**
     * Perform actual connection.
     *
     * @return void
     */
    private function doConnect(): void
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int) ($this->config['port'] ?? 5672);
        $user = $this->config['user'] ?? 'guest';
        $password = $this->config['password'] ?? 'guest';
        $vhost = $this->config['vhost'] ?? '/';

        $this->connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost);

        // Initialize channel pool
        $this->initializeChannelPool();

        // Declare exchange on first channel
        $durable = (bool) ($this->config['exchange_durable'] ?? true);
        $autoDelete = (bool) ($this->config['exchange_auto_delete'] ?? false);

        $this->channelPool[0]->exchange_declare(
            $this->exchange,
            $this->exchangeType,
            false,
            $durable,
            $autoDelete
        );
    }

    /**
     * Initialize channel pool.
     *
     * @return void
     */
    private function initializeChannelPool(): void
    {
        $maxChannels = (int) ($this->config['max_channels'] ?? self::DEFAULT_MAX_CHANNELS);
        $prefetch = (int) ($this->config['prefetch_count'] ?? 50);

        $this->channelPool = [];

        for ($i = 0; $i < $maxChannels; $i++) {
            $channel = $this->connection->channel();
            $channel->basic_qos(null, $prefetch, null);
            $this->channelPool[] = $channel;
        }
    }

    /**
     * Get channel from pool (round-robin).
     *
     * @return AMQPChannel
     */
    private function getChannel(): AMQPChannel
    {
        if (empty($this->channelPool)) {
            throw new \RuntimeException('Channel pool is empty');
        }

        // Round-robin channel selection
        $this->currentChannelIndex = ($this->currentChannelIndex + 1) % count($this->channelPool);
        $channel = $this->channelPool[$this->currentChannelIndex];

        // Check if channel is open, recreate if not
        if (!$channel->is_open()) {
            $prefetch = (int) ($this->config['prefetch_count'] ?? 50);
            $channel = $this->connection->channel();
            $channel->basic_qos(null, $prefetch, null);
            $this->channelPool[$this->currentChannelIndex] = $channel;
        }

        return $channel;
    }

    /**
     * Ensure connection is alive.
     *
     * @return void
     */
    private function ensureConnection(): void
    {
        try {
            if ($this->connected && $this->connection !== null && $this->connection->isConnected()) {
                return; // Connection is good
            }
        } catch (\Throwable) {
            // isConnected() failed, need to reconnect
        }

        // Reconnect with retry
        $this->reconnect();
    }

    /**
     * Reconnect with exponential backoff.
     *
     * @return void
     */
    private function reconnect(): void
    {
        $maxRetries = 3;
        $retryCount = 0;

        // Forcefully cleanup old connection
        $this->forceDisconnect();

        while ($retryCount < $maxRetries) {
            try {
                $this->connect();
                BrokerMetrics::recordConnectionEvent('rabbitmq', 'reconnect');
                return;
            } catch (\Throwable $e) {
                $retryCount++;
                if ($retryCount >= $maxRetries) {
                    throw BrokerException::connectionFailed(
                        'rabbitmq',
                        "Failed after {$maxRetries} retries: {$e->getMessage()}",
                        $e
                    );
                }

                $delay = (int) pow(2, $retryCount);
                error_log("RabbitMQ reconnect failed (retry {$retryCount}/{$maxRetries}). Wait {$delay}s");
                sleep($delay);
            }
        }
    }

    /**
     * Force disconnect and cleanup all resources.
     *
     * @return void
     */
    private function forceDisconnect(): void
    {
        // Close all channels in pool
        foreach ($this->channelPool as $channel) {
            try {
                if ($channel->is_open()) {
                    $channel->close();
                }
            } catch (\Throwable) {
                // Ignore
            }
        }
        $this->channelPool = [];

        // Close connection
        if ($this->connection !== null) {
            try {
                if ($this->connection->isConnected()) {
                    $this->connection->close();
                }
            } catch (\Throwable) {
                // Ignore
            }
            $this->connection = null;
        }

        $this->connected = false;
    }

    /**
     * {@inheritdoc}
     */
    public function publish(string $channel, MessageInterface $message): void
    {
        $this->ensureConnection();

        $routingKey = $this->formatRoutingKey($channel);
        $payload = $message->toJson();

        $msg = new AMQPMessage($payload, [
            'content_type' => 'application/json',
            'delivery_mode' => $this->persistentMessages ? AMQPMessage::DELIVERY_MODE_PERSISTENT : AMQPMessage::DELIVERY_MODE_NON_PERSISTENT,
            'timestamp' => Chronos::now()->getTimestamp(),
        ]);

        $startTime = microtime(true);

        try {
            $this->circuitBreaker->call(function () use ($msg, $routingKey) {
                $publishChannel = $this->getChannel();
                $publishChannel->basic_publish($msg, $this->exchange, $routingKey);
            });

            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordPublish('rabbitmq', $channel, $duration, true);
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordPublish('rabbitmq', $channel, $duration, false);
            BrokerMetrics::recordError('rabbitmq', 'publish');
            throw BrokerException::publishFailed('rabbitmq', $channel, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(string $channel, callable $callback): void
    {
        $this->ensureConnection();

        $routingKey = $this->formatRoutingKey($channel);
        $queue = $this->getQueueName();

        $subscribeChannel = $this->getChannel();
        $subscribeChannel->queue_bind($queue, $this->exchange, $routingKey);

        $this->subscriptions[$channel] = $callback;
        $this->routingMap[$routingKey] = $channel;
    }

    /**
     * Consume messages from RabbitMQ queue with health monitoring.
     *
     * @param int $timeoutMs Poll timeout in milliseconds
     * @param int $batchSize Maximum messages per batch
     * @return void
     */
    public function consume(int $timeoutMs = 1000, int $batchSize = 100): void
    {
        if (empty($this->subscriptions)) {
            return;
        }

        $this->consuming = true;
        $consumeChannel = $this->getChannel();

        if (!$this->consumerInitialized) {
            $tag = $consumeChannel->basic_consume(
                $this->getQueueName(),
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $message) {
                    $this->handleIncomingMessage($message);
                }
            );

            if ($tag) {
                $this->consumerTags[] = $tag;
            }

            $this->consumerInitialized = true;
        }

        $timeoutSeconds = max($timeoutMs, 1000) / 1000;
        $lastHealthCheck = time();
        $healthCheckInterval = 60;

        try {
            while ($this->consuming && $consumeChannel->is_consuming()) {
                try {
                    $consumeChannel->wait(null, false, $timeoutSeconds);

                    // Periodic health check
                    $now = time();
                    if ($now - $lastHealthCheck > $healthCheckInterval) {
                        $this->performHealthCheck();
                        $lastHealthCheck = $now;
                    }
                } catch (AMQPTimeoutException) {
                    // Normal timeout - check health
                    $now = time();
                    if ($now - $lastHealthCheck > $healthCheckInterval) {
                        $this->performHealthCheck();
                        $lastHealthCheck = $now;
                    }
                    continue;
                } catch (\Throwable $e) {
                    error_log("RabbitMQ consume error: {$e->getMessage()}");
                    BrokerMetrics::recordError('rabbitmq', 'consume');

                    // Try to recover
                    try {
                        $this->ensureConnection();
                        $this->setupConsumer();
                    } catch (\Throwable $reconnectError) {
                        error_log("RabbitMQ reconnect failed: {$reconnectError->getMessage()}");
                        throw $e;
                    }
                }
            }
        } finally {
            $this->stopConsuming();
        }
    }

    /**
     * Perform health check.
     *
     * @return void
     * @throws \RuntimeException If health check fails
     */
    private function performHealthCheck(): void
    {
        if (!$this->connection || !$this->connection->isConnected()) {
            throw new \RuntimeException('RabbitMQ connection lost');
        }

        // Check at least one channel is open
        $hasOpenChannel = false;
        foreach ($this->channelPool as $channel) {
            if ($channel->is_open()) {
                $hasOpenChannel = true;
                break;
            }
        }

        if (!$hasOpenChannel) {
            throw new \RuntimeException('All RabbitMQ channels are closed');
        }
    }

    /**
     * Setup consumer after reconnection.
     *
     * @return void
     */
    private function setupConsumer(): void
    {
        $this->consumerInitialized = false;
        $this->consumerTags = [];

        // Re-subscribe to all channels
        $tempSubscriptions = $this->subscriptions;
        $this->subscriptions = [];

        foreach ($tempSubscriptions as $channel => $callback) {
            $this->subscribe($channel, $callback);
        }
    }

    /**
     * Handle incoming message with safe ACK/NACK.
     *
     * @param AMQPMessage $message
     * @return void
     */
    private function handleIncomingMessage(AMQPMessage $message): void
    {
        // Memory management
        $this->memoryManager->tick();

        $routingKey = $message->getRoutingKey();
        $channelName = $this->routingMap[$routingKey] ?? $routingKey;
        $callback = $this->subscriptions[$channelName] ?? null;

        if (!$callback) {
            $this->safeAck($message);
            return;
        }

        $startTime = microtime(true);

        try {
            $decoded = Message::fromJson($message->getBody());
            $callback($decoded);
            $this->safeAck($message);

            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordConsume('rabbitmq', 1, $duration);
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            BrokerMetrics::recordConsume('rabbitmq', 0, $duration);
            BrokerMetrics::recordError('rabbitmq', 'process_message');

            error_log("RabbitMQ consumer error on {$routingKey}: {$e->getMessage()}");
            $this->safeNack($message, true); // Requeue
        }
    }

    /**
     * Safe message acknowledgment.
     *
     * @param AMQPMessage $message
     * @return void
     */
    private function safeAck(AMQPMessage $message): void
    {
        try {
            $message->ack();
        } catch (\Throwable $e) {
            error_log("WARNING: Failed to ACK message: {$e->getMessage()}");
        }
    }

    /**
     * Safe message negative acknowledgment.
     *
     * @param AMQPMessage $message
     * @param bool $requeue
     * @return void
     */
    private function safeNack(AMQPMessage $message, bool $requeue): void
    {
        try {
            $message->nack($requeue);
        } catch (\Throwable $e) {
            error_log("WARNING: Failed to NACK message: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopConsuming(): void
    {
        $this->consuming = false;

        foreach ($this->consumerTags as $tag) {
            foreach ($this->channelPool as $channel) {
                try {
                    if ($channel->is_open()) {
                        $channel->basic_cancel($tag);
                    }
                } catch (\Throwable $e) {
                    error_log("RabbitMQ cancel error: {$e->getMessage()}");
                }
            }
        }

        $this->consumerTags = [];
        $this->consumerInitialized = false;
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(string $channel): void
    {
        if (!isset($this->subscriptions[$channel])) {
            return;
        }

        $routingKey = $this->formatRoutingKey($channel);

        $unbindChannel = $this->getChannel();
        $unbindChannel->queue_unbind($this->getQueueName(), $this->exchange, $routingKey);

        unset($this->subscriptions[$channel], $this->routingMap[$routingKey]);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriberCount(string $channel): int
    {
        if ($this->queueName === null) {
            return 0;
        }

        try {
            $checkChannel = $this->getChannel();
            [$queue,, $consumerCount] = $checkChannel->queue_declare($this->queueName, true);
            return (int) $consumerCount;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->connection?->isConnected();
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        $this->stopConsuming();
        $this->forceDisconnect();

        $this->queueName = null;
        $this->consumerInitialized = false;

        BrokerMetrics::recordConnectionEvent('rabbitmq', 'disconnect');
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'rabbitmq';
    }

    /**
     * Get or create queue name.
     *
     * @return string
     */
    private function getQueueName(): string
    {
        if ($this->queueName !== null) {
            return $this->queueName;
        }

        $durable = (bool) ($this->config['queue_durable'] ?? false);
        $exclusive = (bool) ($this->config['queue_exclusive'] ?? true);
        $autoDelete = (bool) ($this->config['queue_auto_delete'] ?? true);
        $queuePrefix = $this->config['queue_prefix'] ?? 'realtime';

        $hostname = $_ENV['HOSTNAME'] ?? getenv('HOSTNAME') ?: (gethostname() ?: 'app');
        $uniqueSuffix = bin2hex(random_bytes(4));

        $queueName = $exclusive ? '' : sprintf('%s.%s.%s', $queuePrefix, $hostname, $uniqueSuffix);

        $declareChannel = $this->getChannel();
        [$queue] = $declareChannel->queue_declare($queueName, false, $durable, $exclusive, $autoDelete);
        $this->queueName = $queue;

        return $this->queueName;
    }

    /**
     * Format routing key from channel name.
     *
     * @param string $channel
     * @return string
     */
    private function formatRoutingKey(string $channel): string
    {
        return str_replace(':', '.', $channel);
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(): HealthCheckResult
    {
        if (!$this->connected || $this->connection === null) {
            return HealthCheckResult::unhealthy('RabbitMQ broker not connected');
        }

        $start = microtime(true);

        try {
            if (!$this->connection->isConnected()) {
                return HealthCheckResult::unhealthy('RabbitMQ connection lost');
            }

            $openChannels = 0;
            foreach ($this->channelPool as $channel) {
                if ($channel->is_open()) {
                    $openChannels++;
                }
            }

            $latencyMs = (microtime(true) - $start) * 1000;

            return HealthCheckResult::healthy(
                message: 'RabbitMQ connection healthy',
                details: [
                    'exchange' => $this->exchange,
                    'exchange_type' => $this->exchangeType,
                    'queue' => $this->queueName,
                    'subscriptions' => count($this->subscriptions),
                    'channel_pool_size' => count($this->channelPool),
                    'open_channels' => $openChannels,
                    'circuit_breaker' => $this->circuitBreaker->getState()->value,
                    'memory_stats' => $this->memoryManager->getStats(),
                    'metrics' => BrokerMetrics::getMetrics('rabbitmq'),
                ],
                latencyMs: $latencyMs
            );
        } catch (\Throwable $e) {
            return HealthCheckResult::unhealthy(
                message: "RabbitMQ health check failed: {$e->getMessage()}",
                details: ['exception' => $e::class]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHealthCheckName(): string
    {
        return 'rabbitmq-broker-improved';
    }

    /**
     * Get circuit breaker instance.
     *
     * @return CircuitBreaker
     */
    public function getCircuitBreaker(): CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    /**
     * Get memory manager instance.
     *
     * @return MemoryManager
     */
    public function getMemoryManager(): MemoryManager
    {
        return $this->memoryManager;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
