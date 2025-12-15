<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification;

use Toporia\Framework\Notification\Contracts\NotificationInterface;
use Toporia\Framework\Notification\Channels\DatabaseChannel;

/**
 * Trait Notifiable
 *
 * Trait providing notification capabilities to models/entities.
 * Use this trait on User model or any entity that can receive notifications.
 *
 * Usage:
 * ```php
 * class User extends Model implements NotifiableInterface
 * {
 *     use Notifiable;
 *
 *     public function routeNotificationFor(string $channel): mixed
 *     {
 *         return match ($channel) {
 *             'mail' => $this->email,
 *             'sms' => $this->phone,
 *             'database', 'broadcast' => $this->id,
 *             default => null
 *         };
 *     }
 * }
 *
 * // Send notification
 * $user->sendNotification(new OrderShipped($order));
 *
 * // Get unread notifications
 * $notifications = $user->unreadNotifications();
 *
 * // Mark all as read
 * $user->markNotificationsAsRead();
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
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

    /**
     * Send a notification with delay.
     *
     * @param NotificationInterface|Notification $notification
     * @param int $delaySeconds
     * @return void
     */
    public function notifyAfter(NotificationInterface $notification, int $delaySeconds): void
    {
        if ($notification instanceof Notification) {
            $notification->delay($delaySeconds)->onQueue('notifications');
        }
        $this->sendNotification($notification);
    }

    /**
     * Get unread notifications from database.
     *
     * @param int $limit
     * @return array
     */
    public function unreadNotifications(int $limit = 50): array
    {
        $channel = $this->getDatabaseChannel();
        if (!$channel) {
            return [];
        }

        $notifiableId = $this->routeNotificationFor('database');
        if (!$notifiableId) {
            return [];
        }

        return $channel->getUnread($notifiableId, static::class, $limit);
    }

    /**
     * Get all notifications from database.
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function notifications(int $limit = 50, int $offset = 0): array
    {
        $channel = $this->getDatabaseChannel();
        if (!$channel) {
            return [];
        }

        $notifiableId = $this->routeNotificationFor('database');
        if (!$notifiableId) {
            return [];
        }

        return $channel->getAll($notifiableId, static::class, $limit, $offset);
    }

    /**
     * Count unread notifications.
     *
     * @return int
     */
    public function unreadNotificationCount(): int
    {
        $channel = $this->getDatabaseChannel();
        if (!$channel) {
            return 0;
        }

        $notifiableId = $this->routeNotificationFor('database');
        if (!$notifiableId) {
            return 0;
        }

        return $channel->countUnread($notifiableId, static::class);
    }

    /**
     * Mark a notification as read.
     *
     * @param string $notificationId
     * @return bool
     */
    public function markNotificationAsRead(string $notificationId): bool
    {
        $channel = $this->getDatabaseChannel();
        if (!$channel) {
            return false;
        }

        return $channel->markAsRead($notificationId);
    }

    /**
     * Mark a notification as unread.
     *
     * @param string $notificationId
     * @return bool
     */
    public function markNotificationAsUnread(string $notificationId): bool
    {
        $channel = $this->getDatabaseChannel();
        if (!$channel) {
            return false;
        }

        return $channel->markAsUnread($notificationId);
    }

    /**
     * Mark all notifications as read.
     *
     * @return int Number of notifications marked as read
     */
    public function markNotificationsAsRead(): int
    {
        $channel = $this->getDatabaseChannel();
        if (!$channel) {
            return 0;
        }

        $notifiableId = $this->routeNotificationFor('database');
        if (!$notifiableId) {
            return 0;
        }

        return $channel->markAllAsRead($notifiableId, static::class);
    }

    /**
     * Delete a notification.
     *
     * @param string $notificationId
     * @return bool
     */
    public function deleteNotification(string $notificationId): bool
    {
        $channel = $this->getDatabaseChannel();
        if (!$channel) {
            return false;
        }

        return $channel->delete($notificationId);
    }

    /**
     * Delete all notifications.
     *
     * @return int Number of deleted notifications
     */
    public function deleteAllNotifications(): int
    {
        $channel = $this->getDatabaseChannel();
        if (!$channel) {
            return 0;
        }

        $notifiableId = $this->routeNotificationFor('database');
        if (!$notifiableId) {
            return 0;
        }

        return $channel->deleteAll($notifiableId, static::class);
    }

    /**
     * Check if entity has unread notifications.
     *
     * @return bool
     */
    public function hasUnreadNotifications(): bool
    {
        return $this->unreadNotificationCount() > 0;
    }

    /**
     * Get the database channel instance.
     *
     * @return DatabaseChannel|null
     */
    private function getDatabaseChannel(): ?DatabaseChannel
    {
        try {
            $manager = app('notification');
            if (!$manager->hasChannel('database')) {
                return null;
            }

            $channel = $manager->channel('database');
            return $channel instanceof DatabaseChannel ? $channel : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
