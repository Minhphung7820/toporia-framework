<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Realtime\RealtimeManager;
use Toporia\Framework\Realtime\Contracts\{ChannelInterface, TransportInterface, BrokerInterface};

/**
 * Class Realtime
 *
 * Realtime Service Accessor - Provides static-like access to the realtime manager.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @method static void broadcast(string $channel, string $event, mixed $data) Broadcast to channel
 * @method static void send(string $connectionId, string $event, mixed $data) Send to connection
 * @method static void sendToUser(string|int $userId, string $event, mixed $data) Send to user
 * @method static ChannelInterface channel(string $name) Get channel
 * @method static TransportInterface transport(?string $name = null) Get transport
 * @method static BrokerInterface|null broker(?string $name = null) Get broker
 * @method static int getConnectionCount() Get active connections count
 * @method static void disconnect(string $connectionId) Disconnect connection
 *
 * @see RealtimeManager
 *
 * @example
 * // Broadcast to channel
 * Realtime::broadcast('chat.room.1', 'message.sent', [
 *     'user' => 'John',
 *     'text' => 'Hello!'
 * ]);
 *
 * // Send to specific user
 * Realtime::sendToUser($userId, 'notification.new', [
 *     'title' => 'New Message',
 *     'body' => 'You have a new message'
 * ]);
 *
 * // Get channel
 * $channel = Realtime::channel('chat.room.1');
 * $subscribers = $channel->getSubscriberCount();
 */
final class Realtime extends ServiceAccessor
{
    /**
     * Get the service name for this accessor.
     *
     * This is the only method needed - all other methods are automatically
     * delegated to the underlying service via __callStatic().
     *
     * @return string Service name in container
     */
    protected static function getServiceName(): string
    {
        return 'realtime';
    }
}
