<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail;

use Toporia\Framework\Mail\Contracts\{MailerInterface, MessageInterface};

/**
 * Class ArrayMailer
 *
 * Stores emails in memory for testing. Does not actually send emails.
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
final class ArrayMailer implements MailerInterface
{
    /**
     * @var array<MessageInterface> Sent messages.
     */
    private array $messages = [];

    /**
     * {@inheritdoc}
     */
    public function send(MessageInterface $message): bool
    {
        $this->messages[] = $message;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function sendMailable(Mailable $mailable): bool
    {
        $message = $mailable->build();
        return $this->send($message);
    }

    /**
     * {@inheritdoc}
     */
    public function queue(MessageInterface $message, int $delay = 0): bool
    {
        // For array mailer, just store immediately
        return $this->send($message);
    }

    /**
     * {@inheritdoc}
     */
    public function queueMailable(Mailable $mailable, int $delay = 0): bool
    {
        return $this->sendMailable($mailable);
    }

    /**
     * Get all sent messages.
     *
     * @return array<MessageInterface>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Get count of sent messages.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Clear all stored messages.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->messages = [];
    }

    /**
     * Check if any email was sent to the given address.
     *
     * @param string $email
     * @return bool
     */
    public function hasSentTo(string $email): bool
    {
        foreach ($this->messages as $message) {
            if (in_array($email, $message->getTo())) {
                return true;
            }
        }
        return false;
    }
}
