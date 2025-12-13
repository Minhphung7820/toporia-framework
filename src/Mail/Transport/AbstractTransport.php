<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Class AbstractTransport
 *
 * Base class for mail transports providing message validation, logging hooks, and rate limiting support.
 *
 * Note: Retry logic is handled at the Job level, not at the Transport level.
 * This ensures clean separation of concerns and prevents redundant retry attempts.
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
abstract class AbstractTransport implements TransportInterface
{
    /**
     * @var callable|null Logger callback.
     */
    protected $logger = null;

    /**
     * Set logger callback.
     *
     * @param callable $logger Logger function (string $level, string $message, array $context).
     * @return $this
     */
    public function setLogger(callable $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function send(MessageInterface $message): TransportResult
    {
        $this->validateMessage($message);

        try {
            $this->log('debug', 'Sending email', [
                'transport' => $this->getName(),
                'to' => $message->getTo(),
                'subject' => $message->getSubject(),
            ]);

            $result = $this->doSend($message);

            if ($result->isSuccess()) {
                $this->log('info', 'Email sent successfully', [
                    'transport' => $this->getName(),
                    'message_id' => $result->messageId,
                ]);
                return $result;
            }

            // Result not successful, throw exception
            throw new TransportException(
                message: $result->error ?? 'Unknown error',
                transport: $this->getName()
            );
        } catch (TransportException $e) {
            $this->log('error', 'Email send failed', [
                'transport' => $this->getName(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->log('error', 'Email send failed', [
                'transport' => $this->getName(),
                'error' => $e->getMessage(),
            ]);

            throw new TransportException(
                message: 'Failed to send email: ' . $e->getMessage(),
                transport: $this->getName(),
                previous: $e
            );
        }
    }

    /**
     * Perform actual send operation.
     *
     * @param MessageInterface $message Message to send.
     * @return TransportResult
     */
    abstract protected function doSend(MessageInterface $message): TransportResult;

    /**
     * Validate message before sending.
     *
     * @param MessageInterface $message Message to validate.
     * @throws TransportException If validation fails.
     */
    protected function validateMessage(MessageInterface $message): void
    {
        if (empty($message->getTo())) {
            throw new TransportException('Email must have at least one recipient', $this->getName());
        }

        if (empty($message->getFrom())) {
            throw new TransportException('Email must have a sender address', $this->getName());
        }

        if (empty($message->getSubject())) {
            throw new TransportException('Email must have a subject', $this->getName());
        }

        if (empty($message->getBody()) && empty($message->getTextBody())) {
            throw new TransportException('Email must have a body', $this->getName());
        }
    }

    /**
     * Log message if logger is set.
     *
     * @param string $level Log level.
     * @param string $message Log message.
     * @param array<string, mixed> $context Additional context.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }

    /**
     * Build RFC 5322 formatted email address.
     *
     * @param string $email Email address.
     * @param string|null $name Display name.
     * @return string
     */
    protected function formatAddress(string $email, ?string $name = null): string
    {
        if ($name === null || $name === '') {
            return $email;
        }

        // Encode name if contains special characters
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            $name = '=?UTF-8?B?' . base64_encode($name) . '?=';
        } elseif (preg_match('/[",\\\\]/', $name)) {
            $name = '"' . addslashes($name) . '"';
        }

        return "{$name} <{$email}>";
    }

    /**
     * Build multipart MIME message.
     *
     * @param MessageInterface $message Message to build.
     * @return array{headers: array<string, string>, body: string}
     */
    protected function buildMimeMessage(MessageInterface $message): array
    {
        $boundary = '----=_Part_' . md5(uniqid((string) mt_rand(), true));
        $headers = $this->buildHeaders($message, $boundary);
        $body = $this->buildBody($message, $boundary);

        return ['headers' => $headers, 'body' => $body];
    }

    /**
     * Build email headers.
     *
     * @param MessageInterface $message Message.
     * @param string $boundary MIME boundary.
     * @return array<string, string>
     */
    protected function buildHeaders(MessageInterface $message, string $boundary): array
    {
        $headers = [
            'MIME-Version' => '1.0',
            'Date' => now()->toRfc2822String(),
            'Message-ID' => '<' . uniqid() . '@' . gethostname() . '>',
            'From' => $this->formatAddress($message->getFrom(), $message->getFromName()),
            'Subject' => $this->encodeHeader($message->getSubject()),
        ];

        // Recipients
        $headers['To'] = implode(', ', $message->getTo());

        if (!empty($message->getCc())) {
            $headers['Cc'] = implode(', ', $message->getCc());
        }

        if ($message->getReplyTo()) {
            $headers['Reply-To'] = $message->getReplyTo();
        }

        // Content type
        $attachments = $message->getAttachments();
        if (!empty($attachments)) {
            $headers['Content-Type'] = "multipart/mixed; boundary=\"{$boundary}\"";
        } elseif ($message->getTextBody() && $message->getBody()) {
            $headers['Content-Type'] = "multipart/alternative; boundary=\"{$boundary}\"";
        } elseif ($message->getBody()) {
            $headers['Content-Type'] = 'text/html; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';
        } else {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';
        }

        // Custom headers
        foreach ($message->getHeaders() as $name => $value) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Build email body.
     *
     * @param MessageInterface $message Message.
     * @param string $boundary MIME boundary.
     * @return string
     */
    protected function buildBody(MessageInterface $message, string $boundary): string
    {
        $attachments = $message->getAttachments();
        $hasAttachments = !empty($attachments);
        $hasHtml = !empty($message->getBody());
        $hasText = !empty($message->getTextBody());

        // Simple single-part message
        if (!$hasAttachments && !($hasHtml && $hasText)) {
            return $hasHtml
                ? quoted_printable_encode($message->getBody())
                : quoted_printable_encode($message->getTextBody() ?? '');
        }

        $body = '';

        // Alternative part (text + html)
        if ($hasHtml && $hasText && !$hasAttachments) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= quoted_printable_encode($message->getTextBody()) . "\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= quoted_printable_encode($message->getBody()) . "\r\n";

            $body .= "--{$boundary}--\r\n";
            return $body;
        }

        // Mixed with attachments
        $altBoundary = '----=_Alt_' . md5(uniqid((string) mt_rand(), true));

        // Text/HTML part
        if ($hasHtml && $hasText) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";

            $body .= "--{$altBoundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= quoted_printable_encode($message->getTextBody()) . "\r\n";

            $body .= "--{$altBoundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= quoted_printable_encode($message->getBody()) . "\r\n";

            $body .= "--{$altBoundary}--\r\n";
        } elseif ($hasHtml) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= quoted_printable_encode($message->getBody()) . "\r\n";
        } else {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= quoted_printable_encode($message->getTextBody() ?? '') . "\r\n";
        }

        // Attachments
        foreach ($attachments as $attachment) {
            $body .= $this->buildAttachmentPart($attachment, $boundary);
        }

        $body .= "--{$boundary}--\r\n";

        return $body;
    }

    /**
     * Build attachment MIME part.
     *
     * @param array{path: string, name?: string|null, mime?: string|null} $attachment Attachment data.
     * @param string $boundary MIME boundary.
     * @return string
     */
    protected function buildAttachmentPart(array $attachment, string $boundary): string
    {
        $path = $attachment['path'];
        $name = $attachment['name'] ?? basename($path);
        $mime = $attachment['mime'] ?? $this->detectMimeType($path);

        if (!file_exists($path)) {
            return '';
        }

        $content = file_get_contents($path);
        $encoded = chunk_split(base64_encode($content));

        $part = "--{$boundary}\r\n";
        $part .= "Content-Type: {$mime}; name=\"{$name}\"\r\n";
        $part .= "Content-Disposition: attachment; filename=\"{$name}\"\r\n";
        $part .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $part .= $encoded . "\r\n";

        return $part;
    }

    /**
     * Detect MIME type from file.
     *
     * @param string $path File path.
     * @return string
     */
    protected function detectMimeType(string $path): string
    {
        if (!file_exists($path)) {
            return 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime ?: 'application/octet-stream';
    }

    /**
     * Encode header value for non-ASCII characters.
     *
     * @param string $value Header value.
     * @return string
     */
    protected function encodeHeader(string $value): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
