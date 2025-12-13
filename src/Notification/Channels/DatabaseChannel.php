<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Channels;

use Toporia\Framework\Notification\Contracts\{ChannelInterface, NotifiableInterface, NotificationInterface};
use Toporia\Framework\Database\Connection;

/**
 * Class DatabaseChannel
 *
 * Stores notifications in database for in-app notifications.
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
final class DatabaseChannel implements ChannelInterface
{
    private string $table;

    public function __construct(
        private readonly Connection $connection,
        array $config = []
    ) {
        $this->table = $config['table'] ?? 'notifications';
    }

    /**
     * {@inheritdoc}
     */
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        // Get notifiable identifier
        $notifiableId = $notifiable->routeNotificationFor('database');

        if (!$notifiableId) {
            return; // No identifier configured
        }

        // Build notification data
        $data = $notification->toChannel($notifiable, 'database');

        if (!is_array($data)) {
            throw new \InvalidArgumentException(
                'Database notification must return array from toDatabase() method'
            );
        }

        // Store in database
        $this->connection->table($this->table)->insert([
            'id' => $notification->getId(),
            'type' => get_class($notification),
            'notifiable_type' => get_class($notifiable),
            'notifiable_id' => (string) $notifiableId,
            'data' => json_encode($data),
            'read_at' => null,
            'created_at' => now()->getTimestamp()
        ]);
    }

    /**
     * Mark notification as read.
     *
     * @param string $notificationId
     * @return void
     */
    public function markAsRead(string $notificationId): void
    {
        $this->connection->table($this->table)
            ->where('id', $notificationId)
            ->update(['read_at' => now()->getTimestamp()]);
    }

    /**
     * Mark all notifications as read for a notifiable.
     *
     * @param string|int $notifiableId
     * @param string|null $notifiableType
     * @return void
     */
    public function markAllAsRead(string|int $notifiableId, ?string $notifiableType = null): void
    {
        $query = $this->connection->table($this->table)
            ->where('notifiable_id', (string) $notifiableId)
            ->whereNull('read_at');

        if ($notifiableType) {
            $query->where('notifiable_type', $notifiableType);
        }

        $query->update(['read_at' => now()->getTimestamp()]);
    }

    /**
     * Delete old notifications.
     *
     * @param int $days Delete notifications older than N days
     * @return int Number of deleted notifications
     */
    public function deleteOld(int $days = 30): int
    {
        $timestamp = now()->getTimestamp() - ($days * 86400);

        return $this->connection->table($this->table)
            ->where('created_at', '<', $timestamp)
            ->delete();
    }
}
