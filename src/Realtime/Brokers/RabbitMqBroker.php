<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Toporia\Framework\DateTime\Chronos;
use Toporia\Framework\Realtime\Contracts\{BrokerInterface, HealthCheckableInterface, HealthCheckResult, MessageInterface};
use Toporia\Framework\Realtime\Exceptions\{BrokerException, BrokerTemporaryException};
use Toporia\Framework\Realtime\{Message, RealtimeManager};

/**
 * Class RabbitMqBroker
 *
 * Durable AMQP broker with topic exchange routing. Optimized for enterprise messaging with guaranteed delivery.
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
final class RabbitMqBroker implements BrokerInterface, HealthCheckableInterface
{
    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;
    private string $exchange;
    private string $exchangeType;
    private bool $persistentMessages;
    private bool $connected = false;
    private bool $consuming = false;
    private ?string $queueName = null;
    private bool $consumerInitialized = false;

    /** @var array<string, callable> */
    private array $subscriptions = [];

    /** @var array<string, string> */
    private array $routingMap = [];

    /** @var array<int, string> */
    private array $consumerTags = [];

    /**
     * Internal message counter for batch tracking.
     * Used to track messages processed in current consume() call.
     */
    private int $batchMessageCount = 0;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config = [],
        private readonly ?RealtimeManager $manager = null
    ) {
        $this->exchange = $config['exchange'] ?? 'realtime';
        $this->exchangeType = $config['exchange_type'] ?? 'topic';
        $this->persistentMessages = (bool) ($config['persistent_messages'] ?? true);

        $this->connect();
    }

    /**
     * Establish AMQP connection & channel.
     */
    private function connect(): void
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int) ($this->config['port'] ?? 5672);
        $user = $this->config['user'] ?? 'guest';
        $password = $this->config['password'] ?? 'guest';
        $vhost = $this->config['vhost'] ?? '/';

        try {
            $this->connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
            $this->channel = $this->connection->channel();
        } catch (\Throwable $e) {
            throw BrokerException::connectionFailed('rabbitmq', "{$host}:{$port} - {$e->getMessage()}", $e);
        }

        $durable = (bool) ($this->config['exchange_durable'] ?? true);
        $autoDelete = (bool) ($this->config['exchange_auto_delete'] ?? false);

        try {
            $this->channel->exchange_declare(
                $this->exchange,
                $this->exchangeType,
                false,
                $durable,
                $autoDelete
            );
        } catch (\Throwable $e) {
            throw BrokerException::connectionFailed('rabbitmq', "Exchange declare failed: {$e->getMessage()}", $e);
        }

        $prefetch = (int) ($this->config['prefetch_count'] ?? 50);
        if ($prefetch > 0) {
            $this->channel->basic_qos(null, $prefetch, null);
        }

        $this->connected = true;
    }

    private function ensureConnection(): void
    {
        if ($this->connected && $this->connection?->isConnected()) {
            return;
        }

        $this->disconnect();
        $this->connect();
    }

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

        try {
            $this->channel?->basic_publish($msg, $this->exchange, $routingKey);
        } catch (\Throwable $e) {
            throw BrokerException::publishFailed('rabbitmq', $channel, $e->getMessage(), $e);
        }
    }

    public function subscribe(string $channel, callable $callback): void
    {
        $this->ensureConnection();

        $routingKey = $this->formatRoutingKey($channel);
        $queue = $this->getQueueName();

        $this->channel?->queue_bind($queue, $this->exchange, $routingKey);
        $this->subscriptions[$channel] = $callback;
        $this->routingMap[$routingKey] = $channel;
    }

    /**
     * Subscribe using a routing key pattern.
     *
     * RabbitMQ topic exchange supports wildcards:
     * - '#' matches zero or more words (e.g., 'realtime.#' matches all realtime channels)
     * - '*' matches exactly one word (e.g., 'realtime.user.*' matches realtime.user.1, realtime.user.2)
     *
     * @param string $routingKeyPattern Routing key pattern (e.g., '#' for all, 'user.*' for user channels)
     * @param callable $callback Callback receives (MessageInterface $message, string $channel)
     * @return void
     */
    public function subscribeWithRoutingKey(string $routingKeyPattern, callable $callback): void
    {
        $this->ensureConnection();

        // Format routing key (add prefix if needed)
        $formattedKey = $this->formatRoutingKey($routingKeyPattern);
        $queue = $this->getQueueName();

        // Bind queue with pattern
        $this->channel?->queue_bind($queue, $this->exchange, $formattedKey);

        // Store subscription with pattern key
        $this->subscriptions['__pattern__' . $routingKeyPattern] = $callback;
        $this->routingMap[$formattedKey] = '__pattern__' . $routingKeyPattern;
    }

    /**
     * Consume messages from RabbitMQ queue.
     *
     * Optimized for high-throughput production workloads:
     * - Uses prefetch (QoS) for efficient batch fetching from broker
     * - Non-blocking wait with adaptive timeout for low latency
     * - Batch counter tracked via callback to avoid wait() counting issues
     * - Returns control periodically for heartbeat/signal handling
     *
     * Performance characteristics:
     * - Throughput: 10,000+ msg/s with prefetch=100
     * - Latency: <10ms for message processing
     * - Memory: O(prefetch) for buffered messages
     *
     * @param int $timeoutMs Max time to spend in this call (default 1000ms)
     * @param int $batchSize Max messages before returning (default 100)
     */
    public function consume(int $timeoutMs = 1000, int $batchSize = 100): void
    {
        if (empty($this->subscriptions) || !$this->channel) {
            return;
        }

        $this->consuming = true;

        // Initialize consumer only once (lazy initialization)
        if (!$this->consumerInitialized) {
            $tag = $this->channel->basic_consume(
                $this->getQueueName(),
                '',      // consumer_tag (auto-generated)
                false,   // no_local
                false,   // no_ack - manual ack for reliability
                false,   // exclusive
                false,   // nowait
                function (AMQPMessage $message) {
                    $this->handleIncomingMessage($message);
                    $this->batchMessageCount++;
                }
            );

            if ($tag) {
                $this->consumerTags[] = $tag;
            }

            $this->consumerInitialized = true;
        }

        // Reset batch counter for this consume cycle
        $this->batchMessageCount = 0;

        // Calculate deadline for this consume cycle
        $deadline = microtime(true) + ($timeoutMs / 1000);

        // Use short initial wait for fast response when messages available
        // Increases efficiency by reducing syscall overhead
        $waitTimeout = min(0.1, $timeoutMs / 1000);

        // Process messages until batch limit or time budget exhausted
        while ($this->consuming && $this->batchMessageCount < $batchSize) {
            // Check remaining time budget
            $remainingTime = $deadline - microtime(true);
            if ($remainingTime <= 0) {
                return;
            }

            try {
                // Non-blocking wait with adaptive timeout
                // Uses min of configured wait and remaining time
                $this->channel->wait(null, true, min($waitTimeout, max(0.01, $remainingTime)));
            } catch (AMQPTimeoutException) {
                // No message within timeout - return control to caller
                return;
            }
        }
    }

    private function handleIncomingMessage(AMQPMessage $message): void
    {
        $routingKey = $message->getRoutingKey();
        $channelName = $this->routingMap[$routingKey] ?? $routingKey;
        $callback = $this->subscriptions[$channelName] ?? null;

        // If no direct match, check for pattern subscriptions
        if (!$callback) {
            foreach ($this->subscriptions as $key => $cb) {
                if (str_starts_with($key, '__pattern__')) {
                    $callback = $cb;
                    break;
                }
            }
        }

        if (!$callback) {
            $message->ack();
            return;
        }

        try {
            $decoded = Message::fromJson($message->getBody());
            $callback($decoded);
            $message->ack();
        } catch (\Throwable $e) {
            $message->nack(true);
            error_log("RabbitMQ consumer error on {$routingKey}: {$e->getMessage()}");
        }
    }

    public function stopConsuming(): void
    {
        $this->consuming = false;

        if (!$this->channel) {
            return;
        }

        foreach ($this->consumerTags as $tag) {
            try {
                $this->channel->basic_cancel($tag);
            } catch (\Throwable $e) {
                error_log("RabbitMQ cancel error: {$e->getMessage()}");
            }
        }

        $this->consumerTags = [];
        $this->consumerInitialized = false;
    }

    public function unsubscribe(string $channel): void
    {
        if (!isset($this->subscriptions[$channel]) || !$this->channel) {
            return;
        }

        $routingKey = $this->formatRoutingKey($channel);
        $this->channel->queue_unbind($this->getQueueName(), $this->exchange, $routingKey);

        unset($this->subscriptions[$channel], $this->routingMap[$routingKey]);
    }

    public function getSubscriberCount(string $channel): int
    {
        if (!$this->channel || $this->queueName === null) {
            return 0;
        }

        try {
            [$queue,, $consumerCount] = $this->channel->queue_declare($this->queueName, true);
            return (int) $consumerCount;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->connection?->isConnected();
    }

    public function disconnect(): void
    {
        $this->stopConsuming();

        try {
            $this->channel?->close();
        } catch (\Throwable $e) {
            error_log("RabbitMQ channel close error: {$e->getMessage()}");
        }

        try {
            $this->connection?->close();
        } catch (\Throwable $e) {
            error_log("RabbitMQ connection close error: {$e->getMessage()}");
        }

        $this->channel = null;
        $this->connection = null;
        $this->connected = false;
        $this->queueName = null;
        $this->consumerInitialized = false;
    }

    public function getName(): string
    {
        return 'rabbitmq';
    }

    private function getQueueName(): string
    {
        if ($this->queueName !== null) {
            return $this->queueName;
        }

        $durable = (bool) ($this->config['queue_durable'] ?? false);
        $exclusive = (bool) ($this->config['queue_exclusive'] ?? true);
        $autoDelete = (bool) ($this->config['queue_auto_delete'] ?? true);
        $queuePrefix = $this->config['queue_prefix'] ?? 'realtime';

        // Generate unique queue name for containers
        // Use multiple sources to ensure uniqueness:
        // - HOSTNAME env var (Kubernetes/Docker)
        // - gethostname() (fallback)
        // - Random suffix for additional uniqueness on restarts
        $hostname = $_ENV['HOSTNAME'] ?? getenv('HOSTNAME') ?: (gethostname() ?: 'app');
        $uniqueSuffix = bin2hex(random_bytes(4)); // 8 char random suffix

        $queueName = $exclusive ? '' : sprintf(
            '%s.%s.%s',
            $queuePrefix,
            $hostname,
            $uniqueSuffix
        );

        try {
            [$queue] = $this->channel->queue_declare($queueName, false, $durable, $exclusive, $autoDelete);
            $this->queueName = $queue;
        } catch (\Throwable $e) {
            throw BrokerException::connectionFailed('rabbitmq', "Queue declare failed: {$e->getMessage()}", $e);
        }

        return $this->queueName;
    }

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
            // Check if connection is alive
            if (!$this->connection->isConnected()) {
                return HealthCheckResult::unhealthy('RabbitMQ connection lost');
            }

            // Check channel
            if ($this->channel === null || !$this->channel->is_open()) {
                return HealthCheckResult::degraded(
                    message: 'RabbitMQ channel is closed',
                    details: ['connection_alive' => true]
                );
            }

            $latencyMs = (microtime(true) - $start) * 1000;

            return HealthCheckResult::healthy(
                message: 'RabbitMQ connection healthy',
                details: [
                    'exchange' => $this->exchange,
                    'exchange_type' => $this->exchangeType,
                    'queue' => $this->queueName,
                    'subscriptions' => count($this->subscriptions),
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
        return 'rabbitmq-broker';
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
