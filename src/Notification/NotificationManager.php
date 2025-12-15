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
 * Features:
 * - Multi-channel dispatch (mail, database, sms, slack, broadcast)
 * - Queue integration for async delivery
 * - Bulk notification optimization
 * - Custom channel registration
 * - Conditional sending via shouldSend()
 * - Event dispatch for monitoring
 *
 * Performance:
 * - O(C) for single notification where C = channels
 * - O(1) job dispatch for bulk queued notifications
 * - Lazy channel loading with caching
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
final class NotificationManager implements NotificationManagerInterface
{
    /**
     * @var array<string, ChannelInterface> Resolved channel instances (cached)
     */
    private array $channels = [];

    /**
     * @var array<string, callable> Custom channel factories
     */
    private array $customChannels = [];

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
        // Check if notification should be sent (conditional sending)
        if (!$notification->shouldSend($notifiable)) {
            return; // Skip silently
        }

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
        // Check if notification should be sent (conditional sending)
        if (!$notification->shouldSend($notifiable)) {
            return;
        }

        // Get channels for this notification
        $channelNames = $notification->via($notifiable);

        if (empty($channelNames)) {
            return; // No channels specified
        }

        $sentChannels = [];

        // Send to each channel
        foreach ($channelNames as $channelName) {
            try {
                $channel = $this->channel($channelName);
                $channel->send($notifiable, $notification);
                $sentChannels[] = $channelName;
            } catch (\Throwable $e) {
                // Log error and continue to next channel
                $this->handleChannelError($channelName, $notifiable, $notification, $e);
            }
        }

        // Dispatch NotificationSent event if any channel succeeded
        if (!empty($sentChannels)) {
            $this->dispatchSentEvent($notifiable, $notification, $sentChannels);
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

        // Set delay if specified
        $delay = $notification->getDelay();
        if ($delay > 0) {
            $job->delay($delay);
        }

        // Dispatch to queue
        dispatch($job);
    }

    /**
     * {@inheritdoc}
     *
     * Performance Optimization:
     * - If queued: Dispatches single bulk job instead of N separate jobs
     * - If sync: Sends immediately to each notifiable
     * - Filters notifiables through shouldSend() for sync sends
     *
     * Before: O(N) job dispatches
     * After:  O(1) job dispatch for queued, O(N) for sync
     */
    public function sendToMany(iterable $notifiables, NotificationInterface $notification): void
    {
        // Convert to array for count and bulk processing
        $notifiableList = is_array($notifiables) ? $notifiables : iterator_to_array($notifiables);

        if (empty($notifiableList)) {
            return; // Nothing to send
        }

        // If queued, dispatch single bulk job (OPTIMIZED)
        // shouldSend() check happens in the job for each notifiable
        if ($notification->shouldQueue()) {
            $job = Jobs\SendBulkNotificationJob::make($notifiableList, $notification);
            $job->onQueue($notification->getQueueName());

            $delay = $notification->getDelay();
            if ($delay > 0) {
                $job->delay($delay);
            }

            dispatch($job);
            return;
        }

        // Sync: send to each immediately (with shouldSend check)
        foreach ($notifiableList as $notifiable) {
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
     * Register a custom notification channel.
     *
     * Allows extending the notification system with custom channels:
     * ```php
     * $manager->extend('telegram', function ($config, $container) {
     *     return new TelegramChannel($config['bot_token']);
     * });
     * ```
     *
     * @param string $name Channel name
     * @param callable $factory Factory function: fn(array $config, ?ContainerInterface $container): ChannelInterface
     * @return $this
     */
    public function extend(string $name, callable $factory): self
    {
        $this->customChannels[$name] = $factory;

        // Clear cached instance if exists
        unset($this->channels[$name]);

        return $this;
    }

    /**
     * Check if a channel is registered (built-in or custom).
     *
     * @param string $name
     * @return bool
     */
    public function hasChannel(string $name): bool
    {
        // Check custom channels first
        if (isset($this->customChannels[$name])) {
            return true;
        }

        // Check configured channels
        return isset($this->config['channels'][$name]);
    }

    /**
     * Get list of available channel names.
     *
     * @return array<string>
     */
    public function getAvailableChannels(): array
    {
        $configuredChannels = array_keys($this->config['channels'] ?? []);
        $customChannels = array_keys($this->customChannels);

        return array_unique(array_merge($configuredChannels, $customChannels));
    }

    /**
     * Create anonymous notifiable for specific channel route.
     *
     * Allows sending notifications to arbitrary channels without a model:
     * ```php
     * app('notification')->route('mail', 'admin@example.com')
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
     * Custom channels take precedence over built-in channels.
     *
     * Performance: O(1) - Direct class instantiation
     *
     * @param string $name Channel name
     * @return ChannelInterface
     * @throws \InvalidArgumentException If channel not configured
     */
    private function createChannel(string $name): ChannelInterface
    {
        // Check for custom channel first
        if (isset($this->customChannels[$name])) {
            $config = $this->config['channels'][$name] ?? [];
            return ($this->customChannels[$name])($config, $this->container);
        }

        // Check configured channels
        $channels = $this->config['channels'] ?? [];

        if (!isset($channels[$name])) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Notification channel '%s' is not configured. Available channels: %s",
                    $name,
                    implode(', ', $this->getAvailableChannels()) ?: 'none'
                )
            );
        }

        $channelConfig = $channels[$name];
        $driver = $channelConfig['driver'] ?? $name;

        return match ($driver) {
            'mail' => $this->createMailChannel($channelConfig),
            'database' => $this->createDatabaseChannel($channelConfig),
            'sms' => $this->createSmsChannel($channelConfig),
            'slack' => $this->createSlackChannel($channelConfig),
            'broadcast' => $this->createBroadcastChannel($channelConfig),
            default => throw new \InvalidArgumentException(
                sprintf(
                    "Unsupported notification driver: '%s'. Use extend() to register custom drivers.",
                    $driver
                )
            )
        };
    }

    /**
     * Create Mail channel.
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
     * Dispatch NotificationSent event.
     *
     * @param NotifiableInterface $notifiable
     * @param NotificationInterface $notification
     * @param array<string> $channels
     * @return void
     */
    private function dispatchSentEvent(
        NotifiableInterface $notifiable,
        NotificationInterface $notification,
        array $channels
    ): void {
        if (!$this->container?->has('events')) {
            return;
        }

        $event = new Events\NotificationSent(
            notifiable: $notifiable,
            notification: $notification,
            channels: $channels
        );

        $this->container->get('events')->dispatch($event);
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
