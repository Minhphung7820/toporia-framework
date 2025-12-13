<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Class PostmarkTransport
 *
 * Send emails via Postmark API with fast delivery, template support, message streams, link tracking, and detailed analytics.
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
final class PostmarkTransport extends AbstractTransport
{
    private const API_URL = 'https://api.postmarkapp.com/email';

    /**
     * @param string $token Postmark server token.
     * @param array<string, mixed> $options Additional options.
     */
    public function __construct(
        private readonly string $token,
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
            token: $config['token'] ?? '',
            options: $config['options'] ?? []
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'postmark';
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        try {
            $ch = curl_init('https://api.postmarkapp.com/server');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'X-Postmark-Server-Token: ' . $this->token,
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
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Postmark-Server-Token: ' . $this->token,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw TransportException::connectionFailed('postmark', 'api.postmarkapp.com');
        }

        $decoded = json_decode($response, true);

        if ($statusCode >= 400) {
            $errorMessage = $decoded['Message'] ?? 'Unknown error';
            throw TransportException::fromApiError('postmark', $statusCode, $response);
        }

        if (isset($decoded['MessageID'])) {
            return TransportResult::success($decoded['MessageID'], [
                'to' => $decoded['To'] ?? null,
                'submitted_at' => $decoded['SubmittedAt'] ?? null,
            ]);
        }

        return TransportResult::failure($decoded['Message'] ?? 'Unknown error');
    }

    /**
     * Build API payload from message.
     *
     * @param MessageInterface $message Message.
     * @return array<string, mixed>
     */
    private function buildPayload(MessageInterface $message): array
    {
        $payload = [
            'From' => $this->formatAddress($message->getFrom(), $message->getFromName()),
            'To' => implode(',', $message->getTo()),
            'Subject' => $message->getSubject(),
        ];

        // CC/BCC
        if (!empty($message->getCc())) {
            $payload['Cc'] = implode(',', $message->getCc());
        }
        if (!empty($message->getBcc())) {
            $payload['Bcc'] = implode(',', $message->getBcc());
        }

        // Reply-To
        if ($message->getReplyTo()) {
            $payload['ReplyTo'] = $message->getReplyTo();
        }

        // Body
        if ($message->getBody()) {
            $payload['HtmlBody'] = $message->getBody();
        }
        if ($message->getTextBody()) {
            $payload['TextBody'] = $message->getTextBody();
        }

        // Attachments
        $attachments = $message->getAttachments();
        if (!empty($attachments)) {
            $payload['Attachments'] = array_map(function ($attachment) {
                $path = $attachment['path'];
                $name = $attachment['name'] ?? basename($path);
                $mime = $attachment['mime'] ?? $this->detectMimeType($path);

                return [
                    'Name' => $name,
                    'Content' => base64_encode(file_get_contents($path)),
                    'ContentType' => $mime,
                ];
            }, $attachments);
        }

        // Custom headers
        $headers = $message->getHeaders();
        if (!empty($headers)) {
            $payload['Headers'] = array_map(function ($name, $value) {
                return ['Name' => $name, 'Value' => $value];
            }, array_keys($headers), array_values($headers));
        }

        // Options
        if (isset($this->options['message_stream'])) {
            $payload['MessageStream'] = $this->options['message_stream'];
        }
        if (isset($this->options['track_opens'])) {
            $payload['TrackOpens'] = $this->options['track_opens'];
        }
        if (isset($this->options['track_links'])) {
            $payload['TrackLinks'] = $this->options['track_links'];
        }
        if (isset($this->options['tag'])) {
            $payload['Tag'] = $this->options['tag'];
        }
        if (isset($this->options['metadata'])) {
            $payload['Metadata'] = $this->options['metadata'];
        }

        return $payload;
    }
}
