<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Contracts;

/**
 * Interface BrokerSubscriptionStrategyInterface
 *
 * Strategy interface for broker subscription in WebSocket transport.
 * Each broker (Redis, RabbitMQ, Kafka, etc.) implements this interface
 * to handle message subscription within Swoole's coroutine context.
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
interface BrokerSubscriptionStrategyInterface
{
    /**
     * Start subscription to broker messages.
     *
     * This method should:
     * - Run inside a Swoole coroutine context
     * - Handle auto-reconnect with exponential backoff
     * - Call the message handler callback when messages arrive
     *
     * @param \Swoole\WebSocket\Server $server WebSocket server instance
     * @param callable $messageHandler Callback to handle messages: fn(string $channel, array $data)
     * @param callable $isRunning Callback to check if server is still running: fn(): bool
     * @return void
     */
    public function subscribe(
        \Swoole\WebSocket\Server $server,
        callable $messageHandler,
        callable $isRunning
    ): void;

    /**
     * Get the broker name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if this strategy supports the given broker name.
     *
     * @param string $brokerName
     * @return bool
     */
    public function supports(string $brokerName): bool;
}
