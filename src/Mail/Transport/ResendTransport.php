<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Class ResendTransport
 *
 * Send emails via Resend API with modern API design, React email support, webhooks, domain verification, and analytics.
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
final class ResendTransport extends AbstractTransport
{
    private const API_URL = 'https://api.resend.com/emails';

    /**
     * @param string $apiKey Resend API key.
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
        return 'resend';
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        try {
            $ch = curl_init('https://api.resend.com/domains');
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
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw TransportException::connectionFailed('resend', 'api.resend.com');
        }

        $decoded = json_decode($response, true);

        if ($statusCode >= 400) {
            $errorMessage = $decoded['message'] ?? 'Unknown error';
            throw new TransportException($errorMessage, 'resend', [
                'status_code' => $statusCode,
                'response' => $decoded,
            ]);
        }

        if (isset($decoded['id'])) {
            return TransportResult::success($decoded['id']);
        }

        return TransportResult::failure($decoded['message'] ?? 'Unknown error');
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
            'from' => $this->formatAddress($message->getFrom(), $message->getFromName()),
            'to' => $message->getTo(),
            'subject' => $message->getSubject(),
        ];

        // CC/BCC
        if (!empty($message->getCc())) {
            $payload['cc'] = $message->getCc();
        }
        if (!empty($message->getBcc())) {
            $payload['bcc'] = $message->getBcc();
        }

        // Reply-To
        if ($message->getReplyTo()) {
            $payload['reply_to'] = $message->getReplyTo();
        }

        // Body
        if ($message->getBody()) {
            $payload['html'] = $message->getBody();
        }
        if ($message->getTextBody()) {
            $payload['text'] = $message->getTextBody();
        }

        // Attachments
        $attachments = $message->getAttachments();
        if (!empty($attachments)) {
            $payload['attachments'] = array_map(function ($attachment) {
                $path = $attachment['path'];
                $name = $attachment['name'] ?? basename($path);

                return [
                    'filename' => $name,
                    'content' => base64_encode(file_get_contents($path)),
                ];
            }, $attachments);
        }

        // Custom headers
        $headers = $message->getHeaders();
        if (!empty($headers)) {
            $payload['headers'] = $headers;
        }

        // Options
        if (isset($this->options['tags'])) {
            $payload['tags'] = array_map(function ($name, $value) {
                return ['name' => $name, 'value' => $value];
            }, array_keys($this->options['tags']), array_values($this->options['tags']));
        }

        return $payload;
    }
}
