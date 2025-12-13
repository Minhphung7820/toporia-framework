<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification;

use Toporia\Framework\Notification\Contracts\{ChannelInterface, NotifiableInterface, NotificationInterface, NotificationManagerInterface};
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class NotificationManager
 *
 * Multi-channel notification dispatcher with driver management.
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
final class NotificationManager implements NotificationManagerInterface
{
    /**
     * @var array<string, ChannelInterface> Resolved channel instances
     */
    private array $channels = [];

    private string $defaultChannel;

    /**
     * @param array $config Notification configuration
     * @param ContainerInterface|null $container DI container
     */
    public function __construct(
        private array $config = [],
        private readonly ?ContainerInterface $container = null
    ) {
        $this->defaultChannel = $config['default'] ?? 'mail';
    }

    /**
     * {@inheritdoc}
     */
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        // Check if notification should be queued
        if ($notification->shouldQueue()) {
            $this->sendQueued($notifiable, $notification);
            return;
        }

        // Send immediately (sync)
        $this->sendNow($notifiable, $notification);
    }

    /**
     * {@inheritdoc}
     */
    public function sendNow(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        // Get channels for this notification
        $channels = $notification->via($notifiable);

        if (empty($channels)) {
            return; // No channels specified
        }

        // Send to each channel
        foreach ($channels as $channelName) {
            try {
                $channel = $this->channel($channelName);
                $channel->send($notifiable, $notification);
            } catch (\Throwable $e) {
                // Log error and continue to next channel
                $this->handleChannelError($channelName, $notifiable, $notification, $e);
            }
        }
    }

    /**
     * Send notification via queue (asynchronous).
     *
     * @param NotifiableInterface $notifiable
     * @param NotificationInterface $notification
     * @return void
     */
    private function sendQueued(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        // Create job
        $job = Jobs\SendNotificationJob::make($notifiable, $notification);

        // Set queue name from notification
        $job->onQueue($notification->getQueueName());

        // Dispatch to queue
        dispatch($job);
    }

    /**
     * {@inheritdoc}
     *
     * Performance Optimization:
     * - If queued: Dispatches single bulk job instead of N separate jobs
     * - If sync: Sends immediately to each notifiable
     *
     * Before: O(N) job dispatches
     * After:  O(1) job dispatch for queued, O(N) for sync
     */
    public function sendToMany(iterable $notifiables, NotificationInterface $notification): void
    {
        // Convert to array for count and bulk processing
        $notifiables = is_array($notifiables) ? $notifiables : iterator_to_array($notifiables);

        if (empty($notifiables)) {
            return; // Nothing to send
        }

        // If queued, dispatch single bulk job (OPTIMIZED)
        if ($notification->shouldQueue()) {
            $job = Jobs\SendBulkNotificationJob::make($notifiables, $notification);
            $job->onQueue($notification->getQueueName());
            dispatch($job);
            return;
        }

        // Sync: send to each immediately
        foreach ($notifiables as $notifiable) {
            $this->sendNow($notifiable, $notification);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function channel(string $name): ChannelInterface
    {
        // Return cached instance if exists
        if (isset($this->channels[$name])) {
            return $this->channels[$name];
        }

        // Create and cache new channel
        $this->channels[$name] = $this->createChannel($name);

        return $this->channels[$name];
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultChannel(): string
    {
        return $this->defaultChannel;
    }

    /**
     * Create anonymous notifiable for specific channel route.
     *
     * Allows sending notifications to arbitrary channels without a model:
     * ```php
     * Notification::route('mail', 'admin@example.com')
     *     ->notify(new OrderShipped());
     * ```
     *
     * @param string $channel Channel name
     * @param mixed $route Route value (email, phone, etc.)
     * @return AnonymousNotifiable
     */
    public function route(string $channel, mixed $route): AnonymousNotifiable
    {
        return (new AnonymousNotifiable)->route($channel, $route);
    }

    /**
     * Create a notification channel instance.
     *
     * Uses configuration to instantiate the correct channel driver.
     *
     * Performance: O(1) - Direct class instantiation
     *
     * @param string $name Channel name
     * @return ChannelInterface
     * @throws \InvalidArgumentException If channel not configured
     */
    private function createChannel(string $name): ChannelInterface
    {
        $channels = $this->config['channels'] ?? [];

        if (!isset($channels[$name])) {
            throw new \InvalidArgumentException("Notification channel '{$name}' is not configured");
        }

        $channelConfig = $channels[$name];
        $driver = $channelConfig['driver'] ?? $name;

        return match ($driver) {
            'mail' => $this->createMailChannel($channelConfig),
            'database' => $this->createDatabaseChannel($channelConfig),
            'sms' => $this->createSmsChannel($channelConfig),
            'slack' => $this->createSlackChannel($channelConfig),
            'broadcast' => $this->createBroadcastChannel($channelConfig),
            default => throw new \InvalidArgumentException("Unsupported notification driver: {$driver}")
        };
    }

    /**
     * Create Mail channel.
     *
     * Injects mail configuration for DIP compliance.
     *
     * @param array $config Channel-specific config
     * @return ChannelInterface
     */
    private function createMailChannel(array $config): ChannelInterface
    {
        $mailer = $this->container?->get('mailer');

        if (!$mailer) {
            throw new \RuntimeException('Mail channel requires MailManager in container');
        }

        // Get mail config from container
        $mailConfig = [];
        if ($this->container?->has('config')) {
            $mailConfig = $this->container->get('config')->get('mail', []);
        }

        return new Channels\MailChannel($mailer, $mailConfig);
    }

    /**
     * Create Database channel.
     *
     * @param array $config
     * @return ChannelInterface
     */
    private function createDatabaseChannel(array $config): ChannelInterface
    {
        $connection = $this->container?->get('db');

        if (!$connection) {
            throw new \RuntimeException('Database channel requires database connection');
        }

        return new Channels\DatabaseChannel($connection, $config);
    }

    /**
     * Create SMS channel.
     *
     * @param array $config
     * @return ChannelInterface
     */
    private function createSmsChannel(array $config): ChannelInterface
    {
        return new Channels\SmsChannel($config);
    }

    /**
     * Create Slack channel.
     *
     * @param array $config
     * @return ChannelInterface
     */
    private function createSlackChannel(array $config): ChannelInterface
    {
        return new Channels\SlackChannel($config);
    }

    /**
     * Create Broadcast channel.
     *
     * Integrates with Realtime system for WebSocket/SSE notifications.
     *
     * @param array $config
     * @return ChannelInterface
     */
    private function createBroadcastChannel(array $config): ChannelInterface
    {
        $realtime = $this->container?->get('realtime');

        if (!$realtime) {
            throw new \RuntimeException(
                'Broadcast channel requires RealtimeManager in container. ' .
                    'Ensure RealtimeServiceProvider is registered in bootstrap/app.php'
            );
        }

        return new Channels\BroadcastChannel($realtime, $config);
    }

    /**
     * Handle channel delivery error.
     *
     * Logs error and dispatches NotificationFailed event for monitoring.
     *
     * @param string $channelName
     * @param NotifiableInterface $notifiable
     * @param NotificationInterface $notification
     * @param \Throwable $exception
     * @return void
     */
    private function handleChannelError(
        string $channelName,
        NotifiableInterface $notifiable,
        NotificationInterface $notification,
        \Throwable $exception
    ): void {
        // Log error
        error_log(sprintf(
            "Notification channel '%s' failed for notification %s: %s",
            $channelName,
            $notification->getId(),
            $exception->getMessage()
        ));

        // Dispatch event for monitoring/retry logic
        if ($this->container?->has('events')) {
            $event = new Events\NotificationFailed(
                notifiable: $notifiable,
                notification: $notification,
                channel: $channelName,
                exception: $exception
            );

            $this->container->get('events')->dispatch($event);
        }
    }
}
