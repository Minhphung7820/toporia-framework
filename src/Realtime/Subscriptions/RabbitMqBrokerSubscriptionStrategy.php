<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Subscriptions;

use Toporia\Framework\Realtime\Contracts\BrokerSubscriptionStrategyInterface;

/**
 * Class RabbitMqBrokerSubscriptionStrategy
 *
 * RabbitMQ AMQP subscription strategy for WebSocket transport.
 * Uses PhpAmqpLib with non-blocking wait for Swoole compatibility.
 *
 * Features:
 * - Auto-reconnect with exponential backoff (1s -> 2s -> 4s -> ... -> 30s max)
 * - Non-blocking message consumption with periodic yield
 * - Topic exchange with wildcard routing
 * - Production-ready reliability
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Subscriptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RabbitMqBrokerSubscriptionStrategy implements BrokerSubscriptionStrategyInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(
        \Swoole\WebSocket\Server $server,
        callable $messageHandler,
        callable $isRunning
    ): void {
        \Swoole\Coroutine::create(function () use ($server, $messageHandler, $isRunning) {
            $host = $this->config['host'] ?? env('RABBITMQ_HOST', '127.0.0.1');
            $port = (int) ($this->config['port'] ?? env('RABBITMQ_PORT', 5672));
            $user = $this->config['user'] ?? env('RABBITMQ_USER', 'guest');
            $password = $this->config['password'] ?? env('RABBITMQ_PASSWORD', 'guest');
            $vhost = $this->config['vhost'] ?? env('RABBITMQ_VHOST', '/');
            $exchange = $this->config['exchange'] ?? 'realtime';
            $exchangeType = $this->config['exchange_type'] ?? 'topic';

            // Exponential backoff settings
            $baseDelay = 1.0;
            $maxDelay = 30.0;
            $currentDelay = $baseDelay;
            $consecutiveFailures = 0;

            // Auto-reconnect loop
            while ($isRunning()) {
                $connection = null;
                $channel = null;

                try {
                    echo "[RabbitMQ Broker] Connecting to {$host}:{$port}...\n";

                    $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection(
                        $host,
                        $port,
                        $user,
                        $password,
                        $vhost,
                        false,      // insist
                        'AMQPLAIN', // login_method
                        null,       // login_response
                        'en_US',    // locale
                        3.0,        // connection_timeout
                        3.0,        // read_write_timeout
                        null,       // context
                        false,      // keepalive
                        60          // heartbeat
                    );

                    $channel = $connection->channel();

                    // Declare exchange
                    $channel->exchange_declare(
                        $exchange,
                        $exchangeType,
                        false,  // passive
                        true,   // durable
                        false   // auto_delete
                    );

                    // Declare exclusive queue for this WebSocket server
                    [$queueName] = $channel->queue_declare(
                        '',     // Let RabbitMQ generate name
                        false,  // passive
                        false,  // durable
                        true,   // exclusive
                        true    // auto_delete
                    );

                    // Bind queue to receive all messages (using # wildcard)
                    $channel->queue_bind($queueName, $exchange, '#');

                    // Set prefetch count for performance
                    $channel->basic_qos(null, 100, null);

                    // Set up consumer callback
                    $channel->basic_consume(
                        $queueName,
                        '',     // consumer_tag
                        false,  // no_local
                        true,   // no_ack (auto-ack for simplicity)
                        false,  // exclusive
                        false,  // nowait
                        function (\PhpAmqpLib\Message\AMQPMessage $message) use ($messageHandler) {
                            $this->handleMessage($message, $messageHandler);
                        }
                    );

                    // Reset backoff on successful connection
                    $consecutiveFailures = 0;
                    $currentDelay = $baseDelay;
                    echo "[RabbitMQ Broker] Connected and consuming from exchange: {$exchange}\n";

                    // Non-blocking consume loop
                    while ($isRunning() && $channel->is_consuming()) {
                        try {
                            $channel->wait(null, true, 0.1);
                        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                            \Swoole\Coroutine::sleep(0.01);
                            continue;
                        }

                        \Swoole\Coroutine::sleep(0.001);
                    }
                } catch (\Throwable $e) {
                    $consecutiveFailures++;
                    echo "[RabbitMQ Broker] Error: {$e->getMessage()} (attempt #{$consecutiveFailures})\n";

                    $currentDelay = min($baseDelay * pow(2, $consecutiveFailures - 1), $maxDelay);
                    echo "[RabbitMQ Broker] Retrying in {$currentDelay}s...\n";
                } finally {
                    try {
                        $channel?->close();
                    } catch (\Throwable $e) {
                        // Ignore close errors
                    }
                    try {
                        $connection?->close();
                    } catch (\Throwable $e) {
                        // Ignore close errors
                    }
                }

                if ($isRunning()) {
                    \Swoole\Coroutine::sleep($currentDelay);
                }
            }

            echo "[RabbitMQ Broker] Subscription stopped\n";
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'rabbitmq';
    }

    /**
     * {@inheritdoc}
     */
    public function supports(string $brokerName): bool
    {
        return $brokerName === 'rabbitmq';
    }

    /**
     * Handle incoming AMQP message.
     *
     * @param \PhpAmqpLib\Message\AMQPMessage $message
     * @param callable $messageHandler
     * @return void
     */
    private function handleMessage(\PhpAmqpLib\Message\AMQPMessage $message, callable $messageHandler): void
    {
        try {
            $routingKey = $message->getRoutingKey();
            $payload = $message->getBody();

            $messageData = json_decode($payload, true);
            if (!$messageData) {
                return;
            }

            // Convert routing key to channel name (. -> :)
            $channelName = str_replace('.', ':', $routingKey);
            $event = $messageData['event'] ?? 'message';
            $data = $messageData['data'] ?? [];

            $messageHandler($channelName, $event, $data);
        } catch (\Throwable $e) {
            error_log("[RabbitMQ] Error: {$e->getMessage()}");
        }
    }
}
