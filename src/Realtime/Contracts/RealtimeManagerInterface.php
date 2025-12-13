<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Contracts;


/**
 * Interface RealtimeManagerInterface
 *
 * Contract defining the interface for RealtimeManagerInterface
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
interface RealtimeManagerInterface
{
    /**
     * Broadcast message to a channel.
     *
     * Sends message to all subscribers of the channel.
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param mixed $data Event data
     * @return void
     */
    public function broadcast(string $channel, string $event, mixed $data): void;

    /**
     * Send message to a specific connection.
     *
     * @param string $connectionId Connection identifier
     * @param string $event Event name
     * @param mixed $data Event data
     * @return void
     */
    public function send(string $connectionId, string $event, mixed $data): void;

    /**
     * Send message to a specific user.
     *
     * Finds all connections for the user and sends to each.
     *
     * @param string|int $userId User identifier
     * @param string $event Event name
     * @param mixed $data Event data
     * @return void
     */
    public function sendToUser(string|int $userId, string $event, mixed $data): void;

    /**
     * Get channel instance.
     *
     * @param string $name Channel name
     * @return ChannelInterface
     */
    public function channel(string $name): ChannelInterface;

    /**
     * Get transport instance.
     *
     * @param string|null $name Transport name (null = default)
     * @return TransportInterface
     */
    public function transport(?string $name = null): TransportInterface;

    /**
     * Get broker instance.
     *
     * @param string|null $name Broker name (null = default)
     * @return BrokerInterface|null
     */
    public function broker(?string $name = null): ?BrokerInterface;

    /**
     * Get active connections count.
     *
     * @return int
     */
    public function getConnectionCount(): int;

    /**
     * Get connections for a user.
     *
     * @param string|int $userId User identifier
     * @return array<ConnectionInterface>
     */
    public function getUserConnections(string|int $userId): array;

    /**
     * Disconnect a connection.
     *
     * @param string $connectionId Connection identifier
     * @return void
     */
    public function disconnect(string $connectionId): void;
}
