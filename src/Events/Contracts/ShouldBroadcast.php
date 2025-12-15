<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Contracts;

/**
 * Interface ShouldBroadcast
 *
 * Marker interface indicating an event should be broadcasted to realtime channels.
 * Events implementing this interface will automatically broadcast after dispatch.
 *
 * Usage:
 *   class OrderShipped extends Event implements ShouldBroadcast
 *   {
 *       use InteractsWithBroadcasting; // Provides default implementations
 *
 *       public function broadcastOn(): string|array
 *       {
 *           return 'orders.' . $this->order->id;
 *       }
 *   }
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events
 * @since       2025-01-15
 */
interface ShouldBroadcast
{
    /**
     * Get the channels the event should broadcast on.
     *
     * Return channel name(s) as string or array.
     * Supports public, private (prefix: 'private-'), and presence (prefix: 'presence-') channels.
     *
     * Examples:
     *   - 'notifications' => public channel
     *   - 'private-user.123' => private channel
     *   - 'presence-chat.room.1' => presence channel
     *   - ['channel1', 'channel2'] => multiple channels
     *   - new PrivateChannel('user.' . $userId) => channel object
     *   - new PresenceChannel('room.' . $roomId) => presence channel object
     *
     * @return string|array<string>|BroadcastChannelInterface|array<BroadcastChannelInterface>
     */
    public function broadcastOn(): string|array;

    /**
     * Get the event name to broadcast as.
     *
     * If null, uses the event class name.
     * This is the event name that clients will listen for.
     *
     * Example:
     *   return 'order.shipped'; // Client: socket.on('order.shipped', ...)
     *
     * @return string|null
     */
    public function broadcastAs(): ?string;

    /**
     * Get the data to broadcast with the event.
     *
     * Return associative array of data to send to clients.
     * If empty array returned, all public properties will be serialized.
     *
     * Example:
     *   return ['order_id' => $this->order->id, 'user' => $this->user->toArray()];
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array;
}
