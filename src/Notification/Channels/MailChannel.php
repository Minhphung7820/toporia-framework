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
 * Performance:
 * - O(1) for single notification
 * - Delegates actual sending to MailManager (async if configured)
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
final class MailChannel implements ChannelInterface
{
    public function __construct(
        private readonly MailManagerInterface $mailer,
        private readonly array $config = []
    ) {}

    /**
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException If toMail() doesn't return MailMessage
     */
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        // Get recipient email address from notifiable
        $to = $notifiable->routeNotificationFor('mail');

        if (!$to) {
            return; // No email address configured, skip silently
        }

        // Build notification message (MailMessage from notification)
        $notificationMessage = $notification->toChannel($notifiable, 'mail');

        if (!$notificationMessage instanceof MailMessage) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Mail notification %s must return MailMessage instance from toMail() method, got %s',
                    get_class($notification),
                    is_object($notificationMessage) ? get_class($notificationMessage) : gettype($notificationMessage)
                )
            );
        }

        // Get from address from injected config (DIP compliant)
        $from = $this->config['from']['address'] ?? 'noreply@example.com';
        $fromName = $this->config['from']['name'] ?? 'Toporia Framework';

        // Build Mail\Message
        $mailMessage = (new Message())
            ->from($from, $fromName)
            ->to($to)
            ->subject($notificationMessage->subject ?: $this->getDefaultSubject($notification))
            ->html($notificationMessage->render());

        // Add CC if specified in MailMessage
        if (!empty($notificationMessage->cc)) {
            foreach ($notificationMessage->cc as $ccAddress) {
                $mailMessage->cc($ccAddress);
            }
        }

        // Add BCC if specified in MailMessage
        if (!empty($notificationMessage->bcc)) {
            foreach ($notificationMessage->bcc as $bccAddress) {
                $mailMessage->bcc($bccAddress);
            }
        }

        // Add Reply-To if specified
        if ($notificationMessage->replyTo) {
            $mailMessage->replyTo($notificationMessage->replyTo);
        }

        // Send email via mailer
        $this->mailer->send($mailMessage);
    }

    /**
     * Generate default subject from notification class name.
     *
     * @param NotificationInterface $notification
     * @return string
     */
    private function getDefaultSubject(NotificationInterface $notification): string
    {
        $className = (new \ReflectionClass($notification))->getShortName();

        // Convert CamelCase to words: OrderShipped -> Order Shipped
        return trim(preg_replace('/([A-Z])/', ' $1', $className));
    }
}
