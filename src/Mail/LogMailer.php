<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail;

use Toporia\Framework\Mail\Contracts\MailerInterface;

/**
 * Class LogMailer
 *
 * Logs emails instead of sending them, useful for development and testing.
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
final class LogMailer implements MailerInterface
{
    /**
     * @param string $logPath Path to log file.
     */
    public function __construct(
        private string $logPath = ''
    ) {
        if (empty($this->logPath)) {
            $this->logPath = dirname(__DIR__, 3) . '/storage/logs/mail.log';
        }
    }

    /**
     * {@inheritdoc}
     */
    public function send(MessageInterface $message): bool
    {
        $logEntry = $this->formatLogEntry($message);

        // Ensure directory exists
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Append to log file
        file_put_contents($this->logPath, $logEntry . PHP_EOL, FILE_APPEND);

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
        // For log mailer, just send immediately
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
     * Format log entry.
     *
     * @param MessageInterface $message
     * @return string
     */
    private function formatLogEntry(MessageInterface $message): string
    {
        $timestamp = now()->toDateTimeString();
        $to = implode(', ', $message->getTo());

        $entry = <<<LOG
[{$timestamp}] Email Logged
From: {$message->getFrom()}
To: {$to}
Subject: {$message->getSubject()}
---
{$message->getBody()}
---

LOG;

        return $entry;
    }
}
