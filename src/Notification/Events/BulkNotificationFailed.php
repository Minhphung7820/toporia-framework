<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Events;

use Toporia\Framework\Events\Event;
use Toporia\Framework\Notification\Contracts\NotificationInterface;

/**
 * Class BulkNotificationFailed
 *
 * Dispatched when a bulk notification job fails completely.
 *
 * Use this event for:
 * - Monitoring bulk notification failures
 * - Alerting administrators
 * - Implementing retry logic
 * - Debugging notification issues
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
final class BulkNotificationFailed extends Event
{
    /**
     * @param NotificationInterface $notification The notification that failed
     * @param int $totalCount Total number of intended recipients
     * @param int $processedCount Number of successfully processed recipients
     * @param int $failedCount Number of failed recipients
     * @param \Throwable $exception The exception that caused the failure
     * @param array<array{notifiable_id: mixed, error: string}> $failures Individual failure details
     */
    public function __construct(
        public readonly NotificationInterface $notification,
        public readonly int $totalCount,
        public readonly int $processedCount,
        public readonly int $failedCount,
        public readonly \Throwable $exception,
        public readonly array $failures = []
    ) {
    }

    /**
     * Get event name for dispatching.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'notification.bulk_failed';
    }

    /**
     * Get success rate as percentage.
     *
     * @return float
     */
    public function getSuccessRate(): float
    {
        if ($this->totalCount === 0) {
            return 0.0;
        }

        return round(($this->processedCount / $this->totalCount) * 100, 2);
    }

    /**
     * Check if the job was a complete failure (no successful sends).
     *
     * @return bool
     */
    public function isCompleteFailure(): bool
    {
        return $this->processedCount === 0;
    }

    /**
     * Check if the job was a partial failure (some successful sends).
     *
     * @return bool
     */
    public function isPartialFailure(): bool
    {
        return $this->processedCount > 0 && $this->failedCount > 0;
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
            'total_count' => $this->totalCount,
            'processed_count' => $this->processedCount,
            'failed_count' => $this->failedCount,
            'success_rate' => $this->getSuccessRate(),
            'is_complete_failure' => $this->isCompleteFailure(),
            'error_message' => $this->exception->getMessage(),
            'error_class' => get_class($this->exception),
            'failures' => array_slice($this->failures, 0, 10), // Limit to first 10 for logging
            'timestamp' => now()->toDateTimeString()
        ];
    }
}
