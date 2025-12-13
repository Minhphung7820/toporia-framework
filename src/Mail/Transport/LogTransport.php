<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Class LogTransport
 *
 * Log emails to file instead of sending, useful for development and testing.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Mail\Transport
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class LogTransport extends AbstractTransport
{
    /**
     * @param string $logPath Path to log file or directory.
     * @param bool $asEml Save as .eml files.
     */
    public function __construct(
        private readonly string $logPath,
        private readonly bool $asEml = false
    ) {}

    /**
     * Create from config array.
     *
     * @param array<string, mixed> $config Configuration.
     * @return self
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            logPath: $config['path'] ?? storage_path('logs/mail.log'),
            asEml: $config['as_eml'] ?? false
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'log';
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        $dir = $this->asEml ? $this->logPath : dirname($this->logPath);

        if (!is_dir($dir)) {
            return mkdir($dir, 0755, true);
        }

        return is_writable($dir);
    }

    /**
     * {@inheritdoc}
     */
    protected function doSend(MessageInterface $message): TransportResult
    {
        $messageId = uniqid('log_');

        if ($this->asEml) {
            return $this->saveAsEml($message, $messageId);
        }

        return $this->logToFile($message, $messageId);
    }

    /**
     * Save email as .eml file.
     *
     * @param MessageInterface $message Message.
     * @param string $messageId Message ID.
     * @return TransportResult
     */
    private function saveAsEml(MessageInterface $message, string $messageId): TransportResult
    {
        $dir = $this->logPath;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = now()->format('Y-m-d_H-i-s') . "_{$messageId}.eml";
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        $mime = $this->buildMimeMessage($message);
        $content = '';

        foreach ($mime['headers'] as $name => $value) {
            $content .= "{$name}: {$value}\r\n";
        }
        $content .= "\r\n" . $mime['body'];

        $result = file_put_contents($path, $content);

        if ($result === false) {
            return TransportResult::failure('Failed to write .eml file');
        }

        return TransportResult::success($messageId, ['path' => $path]);
    }

    /**
     * Log email to log file.
     *
     * @param MessageInterface $message Message.
     * @param string $messageId Message ID.
     * @return TransportResult
     */
    private function logToFile(MessageInterface $message, string $messageId): TransportResult
    {
        $dir = dirname($this->logPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $entry = $this->formatLogEntry($message, $messageId);
        $result = file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            return TransportResult::failure('Failed to write to log file');
        }

        return TransportResult::success($messageId, ['path' => $this->logPath]);
    }

    /**
     * Format log entry.
     *
     * @param MessageInterface $message Message.
     * @param string $messageId Message ID.
     * @return string
     */
    private function formatLogEntry(MessageInterface $message, string $messageId): string
    {
        $separator = str_repeat('=', 80);
        $timestamp = now()->toDateTimeString();

        $entry = "\n{$separator}\n";
        $entry .= "[{$timestamp}] Mail Message: {$messageId}\n";
        $entry .= $separator . "\n\n";

        $entry .= "From: " . $this->formatAddress($message->getFrom(), $message->getFromName()) . "\n";
        $entry .= "To: " . implode(', ', $message->getTo()) . "\n";

        if (!empty($message->getCc())) {
            $entry .= "Cc: " . implode(', ', $message->getCc()) . "\n";
        }
        if (!empty($message->getBcc())) {
            $entry .= "Bcc: " . implode(', ', $message->getBcc()) . "\n";
        }
        if ($message->getReplyTo()) {
            $entry .= "Reply-To: " . $message->getReplyTo() . "\n";
        }

        $entry .= "Subject: " . $message->getSubject() . "\n";
        $entry .= "\n";

        // Headers
        $headers = $message->getHeaders();
        if (!empty($headers)) {
            $entry .= "Headers:\n";
            foreach ($headers as $name => $value) {
                $entry .= "  {$name}: {$value}\n";
            }
            $entry .= "\n";
        }

        // Attachments
        $attachments = $message->getAttachments();
        if (!empty($attachments)) {
            $entry .= "Attachments:\n";
            foreach ($attachments as $attachment) {
                $name = $attachment['name'] ?? basename($attachment['path']);
                $entry .= "  - {$name} ({$attachment['path']})\n";
            }
            $entry .= "\n";
        }

        // Body
        if ($message->getTextBody()) {
            $entry .= "--- Text Body ---\n";
            $entry .= $message->getTextBody() . "\n\n";
        }

        if ($message->getBody()) {
            $entry .= "--- HTML Body ---\n";
            $entry .= $message->getBody() . "\n\n";
        }

        return $entry;
    }
}

/**
 * Helper function to get storage path.
 * Fallback if not defined.
 */
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $base = defined('STORAGE_PATH') ? STORAGE_PATH : getcwd() . '/storage';
        return $base . ($path ? '/' . ltrim($path, '/') : '');
    }
}
