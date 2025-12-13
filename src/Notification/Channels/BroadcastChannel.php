<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Channels;

use Toporia\Framework\Notification\Contracts\{ChannelInterface, NotifiableInterface, NotificationInterface};
use Toporia\Framework\Notification\Messages\BroadcastMessage;
use Toporia\Framework\Realtime\Contracts\RealtimeManagerInterface;

/**
 * Class BroadcastChannel
 *
 * Sends realtime notifications via WebSocket/SSE to connected clients.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification\Channels
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class BroadcastChannel implements ChannelInterface
{
    /**
     * @param RealtimeManagerInterface $realtime Realtime manager
     * @param array $config Channel configuration
     */
    public function __construct(
        private readonly RealtimeManagerInterface $realtime,
        private readonly array $config = []
    ) {}

    /**
     * {@inheritdoc}
     *
     * Performance: O(C) where C = number of user's active connections
     */
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        // Build broadcast message
        $broadcastMessage = $notification->toChannel($notifiable, 'broadcast');

        if (!$broadcastMessage instanceof BroadcastMessage) {
            throw new \InvalidArgumentException(
                'Broadcast notification must return BroadcastMessage instance from toBroadcast() method'
            );
        }

        // Get target channel (default: user-specific private channel)
        $channel = $broadcastMessage->getChannel()
            ?? $this->getDefaultChannel($notifiable);

        // Get event name
        $event = $broadcastMessage->getEvent();

        // Get data payload
        $data = $broadcastMessage->getData();

        // Broadcast to channel OR send to specific user
        if ($broadcastMessage->isUserSpecific()) {
            $this->sendToUser($notifiable, $event, $data);
        } else {
            $this->broadcastToChannel($channel, $event, $data);
        }
    }

    /**
     * Send notification to specific user (all their connections).
     *
     * This is more efficient than broadcasting to a channel when
     * targeting a single user.
     *
     * Performance: O(C) where C = user's connections (typically 1-3)
     *
     * @param NotifiableInterface $notifiable
     * @param string $event
     * @param mixed $data
     * @return void
     */
    private function sendToUser(NotifiableInterface $notifiable, string $event, mixed $data): void
    {
        // Get user ID from notifiable
        $userId = $notifiable->routeNotificationFor('broadcast');

        if (!$userId) {
            // No routing configured, silently skip
            return;
        }

        try {
            // Send to all user's connections
            $this->realtime->sendToUser($userId, $event, $data);
        } catch (\Throwable $e) {
            // User not connected, silently fail
            // This is expected behavior - user may be offline
            $this->handleError($notifiable, $event, $e);
        }
    }

    /**
     * Broadcast to a channel (all subscribers).
     *
     * Use this for public channels, chat rooms, or presence channels.
     *
     * Performance: O(N) where N = channel subscribers
     *
     * @param string $channel
     * @param string $event
     * @param mixed $data
     * @return void
     */
    private function broadcastToChannel(string $channel, string $event, mixed $data): void
    {
        try {
            // Broadcast to all channel subscribers
            $this->realtime->broadcast($channel, $event, $data);
        } catch (\Throwable $e) {
            // Channel error, log and continue
            error_log("Broadcast failed for channel {$channel}: {$e->getMessage()}");
        }
    }

    /**
     * Get default channel for notifiable.
     *
     * Creates a private user-specific channel: `user.{id}`
     *
     * @param NotifiableInterface $notifiable
     * @return string
     */
    private function getDefaultChannel(NotifiableInterface $notifiable): string
    {
        $userId = $notifiable->routeNotificationFor('broadcast');

        if (!$userId) {
            throw new \RuntimeException(
                'Notifiable must implement routeNotificationFor("broadcast") ' .
                    'returning user ID or channel name'
            );
        }

        // Default to user-specific private channel
        return "user.{$userId}";
    }

    /**
     * Handle broadcast error.
     *
     * Logs error but doesn't throw - broadcast failures should not
     * break the application flow.
     *
     * @param NotifiableInterface $notifiable
     * @param string $event
     * @param \Throwable $exception
     * @return void
     */
    private function handleError(
        NotifiableInterface $notifiable,
        string $event,
        \Throwable $exception
    ): void {
        // Get user identifier for logging
        $userId = $notifiable->routeNotificationFor('broadcast') ?? 'unknown';

        // Log error (user may be offline, this is expected)
        error_log(sprintf(
            "Broadcast notification failed for user %s, event %s: %s",
            $userId,
            $event,
            $exception->getMessage()
        ));

        // NOTE: We don't throw here because:
        // 1. User being offline is expected
        // 2. Broadcast is supplementary to other channels (mail, database)
        // 3. Throwing would break the notification flow
    }
}
