<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Class SendGridTransport
 *
 * Send emails via SendGrid API v3 with support for dynamic templates, categories, custom arguments,
 * scheduled sending, unsubscribe groups, and click/open tracking.
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
final class SendGridTransport extends AbstractTransport
{
    private const API_URL = 'https://api.sendgrid.com/v3/mail/send';

    /**
     * @param string $apiKey SendGrid API key.
     * @param array<string, mixed> $options Additional options.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly array $options = []
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
            apiKey: $config['key'] ?? $config['api_key'] ?? '',
            options: $config['options'] ?? []
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'sendgrid';
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        try {
            $ch = curl_init('https://api.sendgrid.com/v3/user/profile');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $statusCode === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doSend(MessageInterface $message): TransportResult
    {
        $payload = $this->buildPayload($message);

        $ch = curl_init(self::API_URL);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw TransportException::connectionFailed('sendgrid', 'api.sendgrid.com');
        }

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Extract message ID from headers
        $messageId = null;
        if (preg_match('/X-Message-Id:\s*(.+)/i', $headers, $matches)) {
            $messageId = trim($matches[1]);
        }

        if ($statusCode >= 400) {
            $decoded = json_decode($body, true);
            $errorMessage = $decoded['errors'][0]['message'] ?? 'Unknown error';
            throw new TransportException($errorMessage, 'sendgrid', [
                'status_code' => $statusCode,
                'errors' => $decoded['errors'] ?? [],
            ]);
        }

        // 202 Accepted is success for SendGrid
        if ($statusCode === 202 || $statusCode === 200) {
            return TransportResult::success($messageId ?? uniqid('sg_'));
        }

        return TransportResult::failure('Unexpected status code: ' . $statusCode);
    }

    /**
     * Build API payload from message.
     *
     * @param MessageInterface $message Message.
     * @return array<string, mixed>
     */
    private function buildPayload(MessageInterface $message): array
    {
        // Personalizations (recipients)
        $personalizations = [
            'to' => array_map(fn($email) => ['email' => $email], $message->getTo()),
        ];

        if (!empty($message->getCc())) {
            $personalizations['cc'] = array_map(fn($email) => ['email' => $email], $message->getCc());
        }
        if (!empty($message->getBcc())) {
            $personalizations['bcc'] = array_map(fn($email) => ['email' => $email], $message->getBcc());
        }

        $payload = [
            'personalizations' => [$personalizations],
            'from' => [
                'email' => $message->getFrom(),
            ],
            'subject' => $message->getSubject(),
        ];

        // From name
        if ($message->getFromName()) {
            $payload['from']['name'] = $message->getFromName();
        }

        // Reply-To
        if ($message->getReplyTo()) {
            $payload['reply_to'] = ['email' => $message->getReplyTo()];
        }

        // Content
        $content = [];
        if ($message->getTextBody()) {
            $content[] = ['type' => 'text/plain', 'value' => $message->getTextBody()];
        }
        if ($message->getBody()) {
            $content[] = ['type' => 'text/html', 'value' => $message->getBody()];
        }
        $payload['content'] = $content;

        // Attachments
        $attachments = $message->getAttachments();
        if (!empty($attachments)) {
            $payload['attachments'] = array_map(function ($attachment) {
                $path = $attachment['path'];
                $name = $attachment['name'] ?? basename($path);
                $mime = $attachment['mime'] ?? $this->detectMimeType($path);

                return [
                    'content' => base64_encode(file_get_contents($path)),
                    'filename' => $name,
                    'type' => $mime,
                    'disposition' => 'attachment',
                ];
            }, $attachments);
        }

        // Custom headers
        $headers = $message->getHeaders();
        if (!empty($headers)) {
            $payload['headers'] = $headers;
        }

        // Options
        if (isset($this->options['categories'])) {
            $payload['categories'] = (array) $this->options['categories'];
        }
        if (isset($this->options['send_at'])) {
            $payload['send_at'] = $this->options['send_at'];
        }
        if (isset($this->options['tracking_settings'])) {
            $payload['tracking_settings'] = $this->options['tracking_settings'];
        }
        if (isset($this->options['asm'])) {
            $payload['asm'] = $this->options['asm'];
        }

        return $payload;
    }
}
