<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Messages;

/**
 * Class BroadcastMessage
 *
 * Fluent builder for realtime broadcast notifications via WebSocket/SSE.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification\Messages
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class BroadcastMessage
{
    private ?string $channel = null;
    private string $event = 'notification';
    private mixed $data = [];
    private bool $userSpecific = true;

    /**
     * Set broadcast channel.
     *
     * Channel types:
     * - User-specific: `user.{id}` (private, one user)
     * - Presence: `presence-room.{id}` (chat, online tracking)
     * - Public: `announcements` (all subscribers)
     *
     * If not set, defaults to user-specific channel.
     *
     * @param string $channel Channel name
     * @return $this
     */
    public function channel(string $channel): self
    {
        $this->channel = $channel;
        $this->userSpecific = false; // Custom channel = not user-specific
        return $this;
    }

    /**
     * Set event name.
     *
     * Event naming conventions:
     * - Use dot notation: `resource.action` (e.g., `order.shipped`)
     * - Be specific: `user.followed` not just `followed`
     * - Past tense for completed actions
     *
     * @param string $event Event name
     * @return $this
     */
    public function event(string $event): self
    {
        $this->event = $event;
        return $this;
    }

    /**
     * Set notification data.
     *
     * Data will be JSON-encoded and sent to client.
     *
     * Recommended structure:
     * ```php
     * [
     *     'title' => 'Short title',
     *     'message' => 'Detailed message',
     *     'action_url' => url('/path'),
     *     'type' => 'success|info|warning|error',
     *     'icon' => '🎉',
     *     'timestamp' => time(),
     *     'resource_id' => 123,
     *     'resource_type' => 'order'
     * ]
     * ```
     *
     * @param mixed $data Notification data (will be JSON-encoded)
     * @return $this
     */
    public function data(mixed $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Set as user-specific notification.
     *
     * This optimizes delivery by sending directly to user's connections
     * instead of broadcasting to a channel.
     *
     * @param bool $userSpecific
     * @return $this
     */
    public function toUser(bool $userSpecific = true): self
    {
        $this->userSpecific = $userSpecific;
        return $this;
    }

    /**
     * Get channel name.
     *
     * @return string|null
     */
    public function getChannel(): ?string
    {
        return $this->channel;
    }

    /**
     * Get event name.
     *
     * @return string
     */
    public function getEvent(): string
    {
        return $this->event;
    }

    /**
     * Get notification data.
     *
     * @return mixed
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Check if notification is user-specific.
     *
     * User-specific = send to user's connections only (more efficient)
     * Non-user-specific = broadcast to channel (all subscribers)
     *
     * @return bool
     */
    public function isUserSpecific(): bool
    {
        return $this->userSpecific && $this->channel === null;
    }

    /**
     * Convert to array format.
     *
     * Used for serialization and debugging.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'event' => $this->event,
            'data' => $this->data,
            'user_specific' => $this->userSpecific
        ];
    }
}
