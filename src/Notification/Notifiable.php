<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification;

use Toporia\Framework\Notification\Contracts\{NotifiableInterface, NotificationInterface};


/**
 * Trait Notifiable
 *
 * Trait providing reusable functionality for Notifiable in the
 * Multi-channel notifications layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait Notifiable
{
    /**
     * Send a notification to this entity.
     *
     * Convenience method that delegates to NotificationManager.
     * Renamed from notify() to sendNotification() to avoid conflict with Observable::notify()
     *
     * @param NotificationInterface $notification
     * @return void
     */
    public function sendNotification(NotificationInterface $notification): void
    {
        app('notification')->send($this, $notification);
    }

    /**
     * Send a notification asynchronously via queue.
     *
     * @param NotificationInterface|Notification $notification
     * @param string $queueName
     * @return void
     */
    public function notifyLater(NotificationInterface $notification, string $queueName = 'notifications'): void
    {
        // onQueue() is available on Notification base class, not interface
        if ($notification instanceof Notification) {
            $notification->onQueue($queueName);
        }
        $this->sendNotification($notification);
    }
}
