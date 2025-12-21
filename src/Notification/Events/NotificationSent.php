<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Events;

use Toporia\Framework\Events\Event;
use Toporia\Framework\Notification\Contracts\{NotifiableInterface, NotificationInterface};

/**
 * Class NotificationSent
 *
 * Dispatched when a notification is successfully sent via one or more channels.
 *
 * Use this event for:
 * - Analytics and tracking
 * - Audit logging
 * - Triggering follow-up actions
 * - Notification history
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification\Events
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class NotificationSent extends Event
{
    /**
     * @param NotifiableInterface $notifiable The entity that received the notification
     * @param NotificationInterface $notification The notification that was sent
     * @param array<string> $channels The channels through which it was sent
     */
    public function __construct(
        public readonly NotifiableInterface $notifiable,
        public readonly NotificationInterface $notification,
        public readonly array $channels
    ) {
    }

    /**
     * Get event name for dispatching.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'notification.sent';
    }

    /**
     * Check if notification was sent via a specific channel.
     *
     * @param string $channel
     * @return bool
     */
    public function wasSentVia(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    /**
     * Get number of channels the notification was sent through.
     *
     * @return int
     */
    public function getChannelCount(): int
    {
        return count($this->channels);
    }

    /**
     * Convert event to array for logging/serialization.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'notification_id' => $this->notification->getId(),
            'notification_class' => get_class($this->notification),
            'notifiable_class' => get_class($this->notifiable),
            'channels' => $this->channels,
            'channel_count' => $this->getChannelCount(),
            'timestamp' => now()->toDateTimeString()
        ];
    }
}
