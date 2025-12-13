<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Jobs;

use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Queue\Job;
use Toporia\Framework\Notification\Contracts\NotificationInterface;

/**
 * Class SendBulkNotificationJob
 *
 * Optimized queue job for sending notifications to multiple recipients.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification\Jobs
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SendBulkNotificationJob extends Job
{
    /**
     * @param array<array> $notifiablesData Serialized notifiable data array
     * @param string $notifiableClass Notifiable class name
     * @param NotificationInterface $notification Notification instance
     */
    public function __construct(
        private readonly array $notifiablesData,
        private readonly string $notifiableClass,
        private readonly NotificationInterface $notification
    ) {
        parent::__construct();
    }

    /**
     * Execute the job.
     *
     * Processes all notifiables in batch using sendNow() to avoid re-queuing.
     *
     * Performance: O(N*C) where N = notifiables, C = channels
     *
     * @return void
     */
    public function handle(): void
    {
        $manager = app('notification');
        $processed = 0;
        $failed = 0;

        foreach ($this->notifiablesData as $data) {
            try {
                // Reconstruct notifiable from serialized data
                $notifiable = $this->reconstructNotifiable($data);

                // Send notification immediately (bypass queue check)
                $manager->sendNow($notifiable, $this->notification);

                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                error_log(sprintf(
                    "Bulk notification failed for notifiable: %s",
                    $e->getMessage()
                ));
            }
        }

        // Log summary
        error_log(sprintf(
            "Bulk notification job completed: %d sent, %d failed",
            $processed,
            $failed
        ));
    }

    /**
     * Handle job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        error_log(sprintf(
            "Failed to send bulk notification %s: %s",
            $this->notification->getId(),
            $exception->getMessage()
        ));

        // TODO: Dispatch BulkNotificationFailed event
    }

    /**
     * Reconstruct notifiable from serialized data.
     *
     * For ORM models, refetch from database to ensure fresh data.
     * For other notifiables, use cached data.
     *
     * @param array $data Serialized notifiable data
     * @return mixed
     * @throws \RuntimeException If notifiable cannot be reconstructed
     */
    private function reconstructNotifiable(array $data): mixed
    {
        // Check if notifiable is an ORM Model
        if (is_subclass_of($this->notifiableClass, Model::class)) {
            // Refetch from database for fresh data
            $id = $data['id'] ?? null;

            if (!$id) {
                throw new \RuntimeException('Cannot reconstruct model without ID');
            }

            $model = $this->notifiableClass::find($id);

            if (!$model) {
                throw new \RuntimeException("Model {$this->notifiableClass}#{$id} not found");
            }

            return $model;
        }

        // For non-models, reconstruct from cached data
        if (method_exists($this->notifiableClass, 'fromArray')) {
            return $this->notifiableClass::fromArray($data);
        }

        throw new \RuntimeException(
            "Cannot reconstruct notifiable {$this->notifiableClass}. " .
            "Implement fromArray() method or ensure it's an ORM Model."
        );
    }

    /**
     * Create job from notifiables collection.
     *
     * Static factory method for easier job creation.
     *
     * @param iterable $notifiables Collection of notifiables
     * @param NotificationInterface $notification Notification instance
     * @return self
     */
    public static function make(iterable $notifiables, NotificationInterface $notification): self
    {
        // Convert to array if needed
        $notifiables = is_array($notifiables) ? $notifiables : iterator_to_array($notifiables);

        if (empty($notifiables)) {
            throw new \InvalidArgumentException('Cannot create bulk job with empty notifiables');
        }

        // Get class from first notifiable (assume all same type)
        $first = reset($notifiables);
        $notifiableClass = get_class($first);

        // Serialize all notifiables
        $serializedData = array_map(
            fn($notifiable) => self::serializeNotifiable($notifiable),
            $notifiables
        );

        return new self(
            notifiablesData: $serializedData,
            notifiableClass: $notifiableClass,
            notification: $notification
        );
    }

    /**
     * Serialize notifiable for queue storage.
     *
     * @param mixed $notifiable
     * @return array
     */
    private static function serializeNotifiable(mixed $notifiable): array
    {
        // For ORM models, just store ID
        if ($notifiable instanceof Model) {
            return ['id' => $notifiable->id];
        }

        // For other notifiables, try toArray()
        if (method_exists($notifiable, 'toArray')) {
            return $notifiable->toArray();
        }

        // Fallback: serialize all public properties
        return get_object_vars($notifiable);
    }
}
