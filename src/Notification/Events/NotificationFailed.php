<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Events;

use Toporia\Framework\Events\Event;
use Toporia\Framework\Notification\Contracts\{NotifiableInterface, NotificationInterface};

/**
 * Class NotificationFailed
 *
 * Dispatched when a notification fails to send via a specific channel.
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
final class NotificationFailed extends Event
{
    /**
     * @param NotifiableInterface $notifiable The entity that should receive the notification
     * @param NotificationInterface $notification The notification that failed
     * @param string $channel The channel that failed (mail, sms, slack, etc.)
     * @param \Throwable $exception The exception that caused the failure
     */
    public function __construct(
        public readonly NotifiableInterface $notifiable,
        public readonly NotificationInterface $notification,
        public readonly string $channel,
        public readonly \Throwable $exception
    ) {
    }

    /**
     * Get event name for dispatching.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'notification.failed';
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
            'channel' => $this->channel,
            'error_message' => $this->exception->getMessage(),
            'error_class' => get_class($this->exception),
            'timestamp' => now()->toDateTimeString()
        ];
    }
}
