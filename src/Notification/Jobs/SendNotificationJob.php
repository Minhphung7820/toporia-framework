<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Jobs;

use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Notification\Contracts\{NotifiableInterface, NotificationInterface};
use Toporia\Framework\Notification\Events\NotificationFailed;
use Toporia\Framework\Queue\Job;

/**
 * Class SendNotificationJob
 *
 * Queue job for sending notifications asynchronously.
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
final class SendNotificationJob extends Job
{
    /**
     * @param array $notifiableData Serialized notifiable data
     * @param string $notifiableClass Notifiable class name
     * @param NotificationInterface $notification Notification instance
     */
    public function __construct(
        private readonly array $notifiableData,
        private readonly string $notifiableClass,
        private readonly NotificationInterface $notification
    ) {
        parent::__construct();
    }

    /**
     * Execute the job.
     *
     * Reconstructs the notifiable and sends notification via NotificationManager.
     *
     * IMPORTANT: Calls sendNow() directly to avoid infinite queue loop.
     * Using send() would check shouldQueue() again and create another job!
     *
     * @return void
     */
    public function handle(): void
    {
        // Reconstruct notifiable from serialized data
        $notifiable = $this->reconstructNotifiable();

        // Send notification immediately (bypass queue check)
        // We're already in the queue worker, so we must send NOW
        app('notification')->sendNow($notifiable, $this->notification);
    }

    /**
     * Handle job failure.
     *
     * Logs the error and dispatches NotificationFailed event for each channel.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        error_log(sprintf(
            "Failed to send notification %s: %s",
            $this->notification->getId(),
            $exception->getMessage()
        ));

        // Dispatch NotificationFailed event
        try {
            $notifiable = $this->reconstructNotifiable();
            $channels = $this->notification->via($notifiable);

            // Dispatch event for each channel that failed
            foreach ($channels as $channel) {
                $event = new NotificationFailed(
                    notifiable: $notifiable,
                    notification: $this->notification,
                    channel: $channel,
                    exception: $exception
                );

                // Try to dispatch via event dispatcher if available
                if (function_exists('app') && app()->has('events')) {
                    app('events')->dispatch($event);
                }
            }
        } catch (\Throwable $e) {
            // Log but don't throw - we're already in error handling
            error_log(sprintf(
                "Failed to dispatch NotificationFailed event: %s",
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
     * @return NotifiableInterface
     * @throws \RuntimeException If notifiable cannot be reconstructed
     */
    private function reconstructNotifiable(): NotifiableInterface
    {
        // Check if notifiable is an ORM Model
        if (is_subclass_of($this->notifiableClass, Model::class)) {
            // Refetch from database for fresh data
            $id = $this->notifiableData['id'] ?? null;

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
        // This assumes the class has a method to reconstruct from array
        if (method_exists($this->notifiableClass, 'fromArray')) {
            return $this->notifiableClass::fromArray($this->notifiableData);
        }

        throw new \RuntimeException(
            "Cannot reconstruct notifiable {$this->notifiableClass}. " .
                "Implement fromArray() method or ensure it's an ORM Model."
        );
    }

    /**
     * Create job from notifiable instance.
     *
     * Static factory method for easier job creation.
     *
     * @param NotifiableInterface $notifiable
     * @param NotificationInterface $notification
     * @return self
     */
    public static function make(NotifiableInterface $notifiable, NotificationInterface $notification): self
    {
        // Serialize notifiable data
        $data = self::serializeNotifiable($notifiable);

        return new self(
            notifiableData: $data,
            notifiableClass: get_class($notifiable),
            notification: $notification
        );
    }

    /**
     * Serialize notifiable for queue storage.
     *
     * @param NotifiableInterface $notifiable
     * @return array
     */
    private static function serializeNotifiable(NotifiableInterface $notifiable): array
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
