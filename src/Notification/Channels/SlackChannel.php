<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Channels;

use Toporia\Framework\Notification\Contracts\{ChannelInterface, NotifiableInterface, NotificationInterface};
use Toporia\Framework\Notification\Messages\SlackMessage;

/**
 * Class SlackChannel
 *
 * Sends notifications to Slack via webhooks.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification\Channels
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SlackChannel implements ChannelInterface
{
    private ?string $defaultWebhookUrl;

    public function __construct(array $config = [])
    {
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

        // Build Slack message
        $message = $notification->toChannel($notifiable, 'slack');

        if (!$message instanceof SlackMessage) {
            throw new \InvalidArgumentException(
                'Slack notification must return SlackMessage instance from toSlack() method'
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
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL for Slack webhook');
        }

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            throw new \RuntimeException('Failed to encode Slack payload: ' . json_last_error_msg());
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Slack webhook cURL error: {$curlError}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Slack webhook failed (HTTP {$httpCode}): {$response}");
        }
    }
}
