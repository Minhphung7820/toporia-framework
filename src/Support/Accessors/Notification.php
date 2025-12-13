<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Notification\NotificationManager;
use Toporia\Framework\Notification\Contracts\{NotifiableInterface, NotificationInterface};

/**
 * Class Notification
 *
 * Notification Service Accessor - Provides static-like access to the notification manager.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @method static void send(NotifiableInterface $notifiable, NotificationInterface $notification) Send notification
 * @method static void sendToMany(iterable $notifiables, NotificationInterface $notification) Send to multiple
 * @method static \Toporia\Framework\Notification\Contracts\ChannelInterface channel(string $name) Get channel
 *
 * @see NotificationManager
 *
 * @example
 * // Send notification
 * Notification::send($user, new WelcomeNotification());
 *
 * // Send to multiple users
 * Notification::sendToMany($users, new AnnouncementNotification());
 *
 * // Access specific channel
 * $mailChannel = Notification::channel('mail');
 */
final class Notification extends ServiceAccessor
{
    /**
     * Get the service name for this accessor.
     *
     * This is the only method needed - all other methods are automatically
     * delegated to the underlying service via __callStatic().
     *
     * @return string Service name in container
     */
    protected static function getServiceName(): string
    {
        return 'notification';
    }
}
