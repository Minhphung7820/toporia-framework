<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Class MailgunTransport
 *
 * Send emails via Mailgun API with HTTP API support, batch sending, templates, tracking, tags, and metadata.
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
final class MailgunTransport extends AbstractTransport
{
    private const API_BASE_US = 'https://api.mailgun.net/v3';
    private const API_BASE_EU = 'https://api.eu.mailgun.net/v3';

    /**
     * @param string $domain Mailgun domain.
     * @param string $apiKey Mailgun API key.
     * @param string $region Region (us or eu).
     * @param array<string, mixed> $options Additional options.
     */
    public function __construct(
        private readonly string $domain,
        private readonly string $apiKey,
        private readonly string $region = 'us',
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
            domain: $config['domain'] ?? '',
            apiKey: $config['secret'] ?? $config['api_key'] ?? '',
            region: $config['region'] ?? 'us',
            options: $config['options'] ?? []
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'mailgun';
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        try {
            $response = $this->request('GET', "/domains/{$this->domain}");
            return isset($response['domain']);
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

        try {
            $response = $this->request('POST', "/{$this->domain}/messages", $payload);

            if (isset($response['id'])) {
                return TransportResult::success($response['id'], [
                    'message' => $response['message'] ?? null,
                ]);
            }

            return TransportResult::failure($response['message'] ?? 'Unknown error');
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 'mailgun', [], $e);
        }
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
            'to' => implode(',', $message->getTo()),
            'subject' => $message->getSubject(),
        ];

        // CC/BCC
        if (!empty($message->getCc())) {
            $payload['cc'] = implode(',', $message->getCc());
        }
        if (!empty($message->getBcc())) {
            $payload['bcc'] = implode(',', $message->getBcc());
        }

        // Reply-To
        if ($message->getReplyTo()) {
            $payload['h:Reply-To'] = $message->getReplyTo();
        }

        // Body
        if ($message->getBody()) {
            $payload['html'] = $message->getBody();
        }
        if ($message->getTextBody()) {
            $payload['text'] = $message->getTextBody();
        }

        // Custom headers
        foreach ($message->getHeaders() as $name => $value) {
            $payload["h:{$name}"] = $value;
        }

        // Options from config
        if (isset($this->options['tracking'])) {
            $payload['o:tracking'] = $this->options['tracking'] ? 'yes' : 'no';
        }
        if (isset($this->options['tracking_clicks'])) {
            $payload['o:tracking-clicks'] = $this->options['tracking_clicks'];
        }
        if (isset($this->options['tracking_opens'])) {
            $payload['o:tracking-opens'] = $this->options['tracking_opens'] ? 'yes' : 'no';
        }
        if (isset($this->options['tags'])) {
            $payload['o:tag'] = (array) $this->options['tags'];
        }

        return $payload;
    }

    /**
     * Make API request.
     *
     * @param string $method HTTP method.
     * @param string $endpoint API endpoint.
     * @param array<string, mixed> $data Request data.
     * @return array<string, mixed>
     * @throws TransportException
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $baseUrl = $this->region === 'eu' ? self::API_BASE_EU : self::API_BASE_US;
        $url = $baseUrl . $endpoint;

        $ch = curl_init();

        $headers = [
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "api:{$this->apiKey}",
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw TransportException::connectionFailed('mailgun', 'api.mailgun.net');
        }

        $decoded = json_decode($response, true);

        if ($statusCode >= 400) {
            throw TransportException::fromApiError('mailgun', $statusCode, $response);
        }

        return $decoded ?? [];
    }
}
