<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail;

use Toporia\Framework\Mail\Contracts\MailerInterface;

/**
 * Class PendingMail
 *
 * Fluent builder for composing and sending emails with support for recipients, CC, BCC, and queue operations.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Mail
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class PendingMail
{
    /**
     * @var array<string> Recipients.
     */
    private array $to = [];

    /**
     * @var array<string> CC recipients.
     */
    private array $cc = [];

    /**
     * @var array<string> BCC recipients.
     */
    private array $bcc = [];

    /**
     * @param MailerInterface $mailer Mailer instance.
     */
    public function __construct(
        private readonly MailerInterface $mailer
    ) {}

    /**
     * Set recipients.
     *
     * @param string|array<string> $recipients Email address(es).
     * @return $this
     */
    public function to(string|array $recipients): self
    {
        $this->to = is_array($recipients) ? $recipients : [$recipients];
        return $this;
    }

    /**
     * Add CC recipients.
     *
     * @param string|array<string> $recipients Email address(es).
     * @return $this
     */
    public function cc(string|array $recipients): self
    {
        $recipients = is_array($recipients) ? $recipients : [$recipients];
        $this->cc = array_merge($this->cc, $recipients);
        return $this;
    }

    /**
     * Add BCC recipients.
     *
     * @param string|array<string> $recipients Email address(es).
     * @return $this
     */
    public function bcc(string|array $recipients): self
    {
        $recipients = is_array($recipients) ? $recipients : [$recipients];
        $this->bcc = array_merge($this->bcc, $recipients);
        return $this;
    }

    /**
     * Send a mailable.
     *
     * @param Mailable $mailable Mailable instance.
     * @return bool
     */
    public function send(Mailable $mailable): bool
    {
        $message = $this->prepareMessage($mailable->build());
        return $this->mailer->send($message);
    }

    /**
     * Send a raw message.
     *
     * @param Message $message Message instance.
     * @return bool
     */
    public function sendMessage(Message $message): bool
    {
        $message = $this->prepareMessage($message);
        return $this->mailer->send($message);
    }

    /**
     * Queue a mailable for later sending.
     *
     * @param Mailable $mailable Mailable instance.
     * @param int $delay Delay in seconds.
     * @return bool
     */
    public function queue(Mailable $mailable, int $delay = 0): bool
    {
        $message = $this->prepareMessage($mailable->build());
        return $this->mailer->queue($message, $delay);
    }

    /**
     * Queue with specific delay.
     *
     * @param int $delay Delay in seconds.
     * @param Mailable $mailable Mailable instance.
     * @return bool
     */
    public function later(int $delay, Mailable $mailable): bool
    {
        return $this->queue($mailable, $delay);
    }

    /**
     * Prepare message with pending recipients.
     *
     * @param Message $message Message to prepare.
     * @return Message
     */
    private function prepareMessage(Message $message): Message
    {
        // Set recipients
        foreach ($this->to as $recipient) {
            $message->to($recipient);
        }

        foreach ($this->cc as $recipient) {
            $message->cc($recipient);
        }

        foreach ($this->bcc as $recipient) {
            $message->bcc($recipient);
        }

        return $message;
    }
}
