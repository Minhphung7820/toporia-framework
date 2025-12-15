<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Channels;

use Toporia\Framework\Notification\Contracts\{ChannelInterface, NotifiableInterface, NotificationInterface};
use Toporia\Framework\Notification\Messages\SlackMessage;
use Toporia\Framework\Http\Contracts\HttpClientInterface;

/**
 * Class SlackChannel
 *
 * Sends notifications to Slack via webhooks.
 *
 * Supports optional HttpClient injection for better testability and connection pooling.
 * Falls back to cURL when HttpClient is not available.
 *
 * Usage:
 * ```php
 * // In notification:
 * public function toSlack(NotifiableInterface $notifiable): SlackMessage
 * {
 *     return (new SlackMessage)
 *         ->text('Order Shipped!')
 *         ->attachment(function ($attachment) {
 *             $attachment
 *                 ->title('Order #123')
 *                 ->text('Your order has been shipped')
 *                 ->color('good');
 *         });
 * }
 * ```
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
final class SlackChannel implements ChannelInterface
{
    private ?string $defaultWebhookUrl;

    /**
     * @param array $config Channel configuration
     * @param HttpClientInterface|null $http HTTP client (optional, falls back to cURL)
     */
    public function __construct(
        array $config = [],
        private readonly ?HttpClientInterface $http = null
    ) {
        $this->defaultWebhookUrl = $config['webhook_url'] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        // Get webhook URL
        $webhookUrl = $notifiable->routeNotificationFor('slack') ?? $this->defaultWebhookUrl;

        if (!$webhookUrl) {
            return; // No webhook configured
        }

        // Validate webhook URL
        if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(
                'Invalid Slack webhook URL: ' . $webhookUrl
            );
        }

        // Build Slack message
        $message = $notification->toChannel($notifiable, 'slack');

        if (!$message instanceof SlackMessage) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Slack notification %s must return SlackMessage instance from toSlack() method, got %s',
                    get_class($notification),
                    is_object($message) ? get_class($message) : gettype($message)
                )
            );
        }

        // Send to Slack
        $this->sendWebhook($webhookUrl, $message->toArray());
    }

    /**
     * Send webhook request to Slack.
     *
     * @param string $url
     * @param array $payload
     * @return void
     * @throws \RuntimeException
     */
    private function sendWebhook(string $url, array $payload): void
    {
        // Use HTTP client if available
        if ($this->http !== null) {
            $response = $this->http
                ->timeout(30)
                ->asJson()
                ->post($url, $payload);

            if ($response->status() !== 200) {
                throw new \RuntimeException(
                    sprintf('Slack webhook failed (HTTP %d): %s', $response->status(), $response->body())
                );
            }

            return;
        }

        // Fallback to cURL
        $this->sendViaCurl($url, $payload);
    }

    /**
     * Send via cURL (fallback).
     *
     * @param string $url
     * @param array $payload
     * @return void
     */
    private function sendViaCurl(string $url, array $payload): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL for Slack webhook');
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            throw new \RuntimeException('Failed to encode Slack payload: ' . json_last_error_msg());
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Slack webhook cURL error: {$curlError}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException(
                sprintf('Slack webhook failed (HTTP %d): %s', $httpCode, $response)
            );
        }
    }
}
