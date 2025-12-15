<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Jobs;

use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Queue\Job;
use Toporia\Framework\Notification\Contracts\NotificationInterface;
use Toporia\Framework\Notification\Events\BulkNotificationFailed;

/**
 * Class SendBulkNotificationJob
 *
 * Optimized queue job for sending notifications to multiple recipients.
 *
 * Performance Benefits:
 * - Single job dispatch instead of N individual jobs
 * - Batched database queries for model reconstruction
 * - Graceful error handling per notifiable
 * - Event dispatch for monitoring
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
 * @package     toporia/framework
 * @subpackage  Notification\Jobs
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SendBulkNotificationJob extends Job
{
    /** @var array<array{notifiable_id: mixed, error: string}> Track individual failures */
    private array $failures = [];

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
     * Includes shouldSend() check for each notifiable.
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
        $skipped = 0;
        $total = count($this->notifiablesData);

        foreach ($this->notifiablesData as $data) {
            try {
                // Reconstruct notifiable from serialized data
                $notifiable = $this->reconstructNotifiable($data);

                // Check if should send (respect conditional sending)
                if (!$this->notification->shouldSend($notifiable)) {
                    $skipped++;
                    continue;
                }

                // Send notification immediately (bypass queue check)
                $manager->sendNow($notifiable, $this->notification);

                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->failures[] = [
                    'notifiable_id' => $data['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];

                error_log(sprintf(
                    "Bulk notification failed for notifiable %s: %s",
                    $data['id'] ?? 'unknown',
                    $e->getMessage()
                ));
            }
        }

        // Log summary
        if (function_exists('log_info')) {
            log_info(sprintf(
                "Bulk notification job completed: %d sent, %d failed, %d skipped (total: %d)",
                $processed,
                $failed,
                $skipped,
                $total
            ));
        }

        // Dispatch partial failure event if there were failures but some successes
        if ($failed > 0 && $processed > 0) {
            $this->dispatchPartialFailureEvent($total, $processed, $failed);
        }
    }

    /**
     * Handle job failure.
     *
     * Dispatches BulkNotificationFailed event for monitoring.
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

        // Dispatch BulkNotificationFailed event
        $this->dispatchFailedEvent($exception);
    }

    /**
     * Dispatch BulkNotificationFailed event.
     *
     * @param \Throwable $exception
     * @return void
     */
    private function dispatchFailedEvent(\Throwable $exception): void
    {
        try {
            if (function_exists('app') && app()->has('events')) {
                $event = new BulkNotificationFailed(
                    notification: $this->notification,
                    totalCount: count($this->notifiablesData),
                    processedCount: 0,
                    failedCount: count($this->notifiablesData),
                    exception: $exception,
                    failures: $this->failures
                );

                app('events')->dispatch($event);
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                "Failed to dispatch BulkNotificationFailed event: %s",
                $e->getMessage()
            ));
        }
    }

    /**
     * Dispatch partial failure event.
     *
     * @param int $total
     * @param int $processed
     * @param int $failed
     * @return void
     */
    private function dispatchPartialFailureEvent(int $total, int $processed, int $failed): void
    {
        try {
            if (function_exists('app') && app()->has('events')) {
                $event = new BulkNotificationFailed(
                    notification: $this->notification,
                    totalCount: $total,
                    processedCount: $processed,
                    failedCount: $failed,
                    exception: new \RuntimeException('Partial bulk notification failure'),
                    failures: $this->failures
                );

                app('events')->dispatch($event);
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                "Failed to dispatch partial BulkNotificationFailed event: %s",
                $e->getMessage()
            ));
        }
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
        $notifiableList = is_array($notifiables) ? $notifiables : iterator_to_array($notifiables);

        if (empty($notifiableList)) {
            throw new \InvalidArgumentException('Cannot create bulk job with empty notifiables');
        }

        // Get class from first notifiable (assume all same type)
        $first = reset($notifiableList);
        $notifiableClass = get_class($first);

        // Serialize all notifiables
        $serializedData = array_map(
            fn($notifiable) => self::serializeNotifiable($notifiable),
            $notifiableList
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
     * For ORM models, only stores ID for security and performance.
     * For other notifiables, attempts safe serialization.
     *
     * @param mixed $notifiable
     * @return array
     */
    private static function serializeNotifiable(mixed $notifiable): array
    {
        // For ORM models, just store ID (security + performance)
        if ($notifiable instanceof Model) {
            return ['id' => $notifiable->id];
        }

        // For other notifiables, try toArray() (safe serialization)
        if (method_exists($notifiable, 'toArray')) {
            return $notifiable->toArray();
        }

        // For notifiables with getKey() method
        if (method_exists($notifiable, 'getKey')) {
            return ['id' => $notifiable->getKey()];
        }

        // Fallback: only serialize public properties (safer than get_object_vars)
        $reflection = new \ReflectionClass($notifiable);
        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $properties[$name] = $property->getValue($notifiable);
        }

        return $properties;
    }

    /**
     * Get total count of notifiables.
     *
     * @return int
     */
    public function getTotalCount(): int
    {
        return count($this->notifiablesData);
    }

    /**
     * Get notification instance.
     *
     * @return NotificationInterface
     */
    public function getNotification(): NotificationInterface
    {
        return $this->notification;
    }
}
