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
 * Performance:
 * - O(1) for single insert/update
 * - Indexed queries for efficient retrieval
 *
 * Table Schema Requirements:
 * - id: VARCHAR(255) PRIMARY KEY
 * - type: VARCHAR(255)
 * - notifiable_type: VARCHAR(255)
 * - notifiable_id: VARCHAR(255) INDEXED
 * - data: JSON/TEXT
 * - read_at: DATETIME NULL INDEXED
 * - created_at: DATETIME INDEXED
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
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
                sprintf(
                    'Database notification %s must return array from toDatabase() method, got %s',
                    get_class($notification),
                    gettype($data)
                )
            );
        }

        // Store in database with proper datetime format
        $this->connection->table($this->table)->insert([
            'id' => $notification->getId(),
            'type' => get_class($notification),
            'notifiable_type' => get_class($notifiable),
            'notifiable_id' => (string) $notifiableId,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'read_at' => null,
            'created_at' => now()->toDateTimeString() // Use datetime string format
        ]);
    }

    /**
     * Mark notification as read.
     *
     * @param string $notificationId
     * @return bool True if notification was updated
     */
    public function markAsRead(string $notificationId): bool
    {
        $affected = $this->connection->table($this->table)
            ->where('id', $notificationId)
            ->whereNull('read_at')
            ->update(['read_at' => now()->toDateTimeString()]);

        return $affected > 0;
    }

    /**
     * Mark notification as unread.
     *
     * @param string $notificationId
     * @return bool True if notification was updated
     */
    public function markAsUnread(string $notificationId): bool
    {
        $affected = $this->connection->table($this->table)
            ->where('id', $notificationId)
            ->whereNotNull('read_at')
            ->update(['read_at' => null]);

        return $affected > 0;
    }

    /**
     * Mark all notifications as read for a notifiable.
     *
     * @param string|int $notifiableId
     * @param string|null $notifiableType
     * @return int Number of notifications marked as read
     */
    public function markAllAsRead(string|int $notifiableId, ?string $notifiableType = null): int
    {
        $query = $this->connection->table($this->table)
            ->where('notifiable_id', (string) $notifiableId)
            ->whereNull('read_at');

        if ($notifiableType) {
            $query->where('notifiable_type', $notifiableType);
        }

        return $query->update(['read_at' => now()->toDateTimeString()]);
    }

    /**
     * Get unread notifications for a notifiable.
     *
     * @param string|int $notifiableId
     * @param string|null $notifiableType
     * @param int $limit
     * @return array
     */
    public function getUnread(string|int $notifiableId, ?string $notifiableType = null, int $limit = 50): array
    {
        $query = $this->connection->table($this->table)
            ->where('notifiable_id', (string) $notifiableId)
            ->whereNull('read_at')
            ->orderBy('created_at', 'DESC')
            ->limit($limit);

        if ($notifiableType) {
            $query->where('notifiable_type', $notifiableType);
        }

        $results = $query->get()
            ->toArray();

        // Decode JSON data for each notification
        return array_map(fn($row) => $this->hydrateNotification($row), $results);
    }

    /**
     * Get all notifications for a notifiable.
     *
     * @param string|int $notifiableId
     * @param string|null $notifiableType
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAll(
        string|int $notifiableId,
        ?string $notifiableType = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $query = $this->connection->table($this->table)
            ->where('notifiable_id', (string) $notifiableId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset);

        if ($notifiableType) {
            $query->where('notifiable_type', $notifiableType);
        }

        $results = $query->get()
            ->toArray();

        return array_map(fn($row) => $this->hydrateNotification($row), $results);
    }

    /**
     * Count unread notifications for a notifiable.
     *
     * @param string|int $notifiableId
     * @param string|null $notifiableType
     * @return int
     */
    public function countUnread(string|int $notifiableId, ?string $notifiableType = null): int
    {
        $query = $this->connection->table($this->table)
            ->where('notifiable_id', (string) $notifiableId)
            ->whereNull('read_at');

        if ($notifiableType) {
            $query->where('notifiable_type', $notifiableType);
        }

        return $query->count();
    }

    /**
     * Find notification by ID.
     *
     * @param string $notificationId
     * @return array|null
     */
    public function find(string $notificationId): ?array
    {
        $row = $this->connection->table($this->table)
            ->where('id', $notificationId)
            ->first();

        return $row ? $this->hydrateNotification($row) : null;
    }

    /**
     * Delete a notification.
     *
     * @param string $notificationId
     * @return bool True if deleted
     */
    public function delete(string $notificationId): bool
    {
        $affected = $this->connection->table($this->table)
            ->where('id', $notificationId)
            ->delete();

        return $affected > 0;
    }

    /**
     * Delete all notifications for a notifiable.
     *
     * @param string|int $notifiableId
     * @param string|null $notifiableType
     * @return int Number of deleted notifications
     */
    public function deleteAll(string|int $notifiableId, ?string $notifiableType = null): int
    {
        $query = $this->connection->table($this->table)
            ->where('notifiable_id', (string) $notifiableId);

        if ($notifiableType) {
            $query->where('notifiable_type', $notifiableType);
        }

        return $query->delete();
    }

    /**
     * Delete old notifications.
     *
     * @param int $days Delete notifications older than N days
     * @return int Number of deleted notifications
     */
    public function deleteOld(int $days = 30): int
    {
        $cutoffDate = now()->subDays($days)->toDateTimeString();

        return $this->connection->table($this->table)
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }

    /**
     * Delete read notifications older than specified days.
     *
     * @param int $days
     * @return int Number of deleted notifications
     */
    public function deleteOldRead(int $days = 7): int
    {
        $cutoffDate = now()->subDays($days)->toDateTimeString();

        return $this->connection->table($this->table)
            ->whereNotNull('read_at')
            ->where('read_at', '<', $cutoffDate)
            ->delete();
    }

    /**
     * Hydrate notification row with decoded data.
     *
     * @param object|array $row
     * @return array
     */
    private function hydrateNotification(object|array $row): array
    {
        $row = (array) $row;

        // Decode JSON data
        if (isset($row['data']) && is_string($row['data'])) {
            $row['data'] = json_decode($row['data'], true) ?? [];
        }

        return $row;
    }
}
