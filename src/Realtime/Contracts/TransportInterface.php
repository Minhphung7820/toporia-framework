<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Contracts;


/**
 * Interface TransportInterface
 *
 * Contract defining the interface for TransportInterface implementations
 * in the Real-time broadcasting layer of the Toporia Framework.
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
interface TransportInterface
{
    /**
     * Send message to a connection.
     *
     * Delivers message to single client connection.
     *
     * Performance: O(1) - Direct connection write
     *
     * @param ConnectionInterface $connection Target connection
     * @param MessageInterface $message Message to send
     * @return void
     * @throws \RuntimeException If send fails
     */
    public function send(ConnectionInterface $connection, MessageInterface $message): void;

    /**
     * Broadcast message to all connections.
     *
     * Sends message to all active connections.
     *
     * Performance: O(N) where N = number of connections
     * Optimization: Can be parallelized for WebSocket
     *
     * @param MessageInterface $message Message to broadcast
     * @return void
     */
    public function broadcast(MessageInterface $message): void;

    /**
     * Broadcast message to connections in a channel.
     *
     * Sends message only to clients subscribed to channel.
     *
     * Performance: O(M) where M = subscribers in channel
     *
     * @param string $channel Channel name
     * @param MessageInterface $message Message to send
     * @return void
     */
    public function broadcastToChannel(string $channel, MessageInterface $message): void;

    /**
     * Get active connections count.
     *
     * @return int Number of active connections
     */
    public function getConnectionCount(): int;

    /**
     * Check if connection is active.
     *
     * @param string $connectionId Connection identifier
     * @return bool
     */
    public function hasConnection(string $connectionId): bool;

    /**
     * Close a connection.
     *
     * @param ConnectionInterface $connection Connection to close
     * @param int $code Close code (1000 = normal closure)
     * @param string $reason Close reason
     * @return void
     */
    public function close(ConnectionInterface $connection, int $code = 1000, string $reason = ''): void;

    /**
     * Start transport server.
     *
     * Blocks until server is stopped.
     *
     * @param string $host Server host
     * @param int $port Server port
     * @return void
     */
    public function start(string $host, int $port): void;

    /**
     * Stop transport server.
     *
     * @return void
     */
    public function stop(): void;

    /**
     * Get transport name.
     *
     * @return string (websocket, sse, longpolling)
     */
    public function getName(): string;
}
