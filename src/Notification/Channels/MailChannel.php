<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Channels;

use Toporia\Framework\Notification\Contracts\{ChannelInterface, NotifiableInterface, NotificationInterface};
use Toporia\Framework\Mail\Contracts\MailManagerInterface;
use Toporia\Framework\Mail\Message;
use Toporia\Framework\Notification\Messages\MailMessage;

/**
 * Class MailChannel
 *
 * Sends notifications via email using MailManager.
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
final class MailChannel implements ChannelInterface
{
    public function __construct(
        private readonly MailManagerInterface $mailer,
        private readonly array $config = []
    ) {}

    /**
     * {@inheritdoc}
     */
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        // Get recipient email address
        // Check if notification has custom routing first (for override scenarios)
        $to = method_exists($notification, 'routeNotificationFor')
            ? $notification->routeNotificationFor($notifiable, 'mail')
            : $notifiable->routeNotificationFor('mail');

        if (!$to) {
            return; // No email address configured
        }

        // Build notification message (MailMessage from notification)
        $notificationMessage = $notification->toChannel($notifiable, 'mail');

        if (!$notificationMessage instanceof MailMessage) {
            throw new \InvalidArgumentException(
                'Mail notification must return MailMessage instance from toMail() method'
            );
        }

        // Get from address from injected config (DIP compliant)
        $from = $this->config['from']['address'] ?? 'noreply@example.com';
        $fromName = $this->config['from']['name'] ?? 'Toporia Framework';

        // Convert to Mail\Message and send
        $mailMessage = (new Message())
            ->from($from, $fromName)
            ->to($to)
            ->subject($notificationMessage->subject)
            ->html($notificationMessage->render());

        // Send email via mailer
        $this->mailer->send($mailMessage);
    }
}
