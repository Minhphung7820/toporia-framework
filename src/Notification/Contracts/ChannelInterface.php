<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Contracts;


/**
 * Interface ChannelInterface
 *
 * Contract defining the interface for ChannelInterface implementations in
 * the Multi-channel notifications layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ChannelInterface
{
    /**
     * Send notification via this channel.
     *
     * Channel receives notification and notifiable, extracts routing info,
     * builds message, and delivers it.
     *
     * Flow:
     * 1. Get routing info: $notifiable->routeNotificationFor($channelName)
     * 2. Build message: $notification->toChannel($notifiable, $channelName)
     * 3. Deliver message via channel-specific mechanism
     *
     * Performance:
     * - O(1) for single notification
     * - O(N) for bulk notifications (with batching optimization)
     * - Async delivery via queue (optional)
     *
     * @param NotifiableInterface $notifiable Entity receiving notification
     * @param NotificationInterface $notification Notification to send
     * @return void
     * @throws \Throwable If delivery fails
     */
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void;
}
