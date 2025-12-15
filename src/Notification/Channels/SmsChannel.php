<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Channels;

use Toporia\Framework\Notification\Contracts\{ChannelInterface, NotifiableInterface, NotificationInterface};
use Toporia\Framework\Notification\Messages\SmsMessage;
use Toporia\Framework\Http\Contracts\HttpClientInterface;

/**
 * Class SmsChannel
 *
 * Sends SMS notifications via third-party API (Twilio, Nexmo, AWS SNS, etc.)
 *
 * Supports optional HttpClient injection for better testability and connection pooling.
 * Falls back to cURL when HttpClient is not available.
 *
 * Usage:
 * ```php
 * // In notification:
 * public function toSms(NotifiableInterface $notifiable): SmsMessage
 * {
 *     return (new SmsMessage)
 *         ->content("Your order #{$this->order->id} has been shipped!");
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
final class SmsChannel implements ChannelInterface
{
    private string $provider;
    private array $credentials;

    /**
     * @param array $config Channel configuration
     * @param HttpClientInterface|null $http HTTP client (optional, falls back to cURL)
     */
    public function __construct(
        array $config = [],
        private readonly ?HttpClientInterface $http = null
    ) {
        $this->provider = $config['provider'] ?? 'twilio';
        $this->credentials = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        // Get phone number
        $to = $notifiable->routeNotificationFor('sms');

        if (!$to) {
            return; // No phone number configured
        }

        // Build SMS message
        $message = $notification->toChannel($notifiable, 'sms');

        if (!$message instanceof SmsMessage) {
            throw new \InvalidArgumentException(
                sprintf(
                    'SMS notification %s must return SmsMessage instance from toSms() method, got %s',
                    get_class($notification),
                    is_object($message) ? get_class($message) : gettype($message)
                )
            );
        }

        // Validate message
        $errors = $message->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException(
                'SMS message validation failed: ' . implode(', ', $errors)
            );
        }

        // Send via provider
        match ($this->provider) {
            'twilio' => $this->sendViaTwilio($to, $message),
            'nexmo', 'vonage' => $this->sendViaNexmo($to, $message),
            'aws_sns' => $this->sendViaAwsSns($to, $message),
            default => throw new \InvalidArgumentException(
                "Unsupported SMS provider: {$this->provider}. Supported: twilio, nexmo, vonage, aws_sns"
            )
        };
    }

    /**
     * Send SMS via Twilio.
     *
     * @param string $to
     * @param SmsMessage $message
     * @return void
     */
    private function sendViaTwilio(string $to, SmsMessage $message): void
    {
        $accountSid = $this->credentials['account_sid'] ?? '';
        $authToken = $this->credentials['auth_token'] ?? '';
        $from = $message->from ?? $this->credentials['from'] ?? '';

        if (!$accountSid || !$authToken || !$from) {
            throw new \RuntimeException(
                'Twilio credentials not configured. Required: account_sid, auth_token, from'
            );
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

        $data = [
            'From' => $from,
            'To' => $to,
            'Body' => $message->content
        ];

        // Use HTTP client if available, otherwise cURL
        if ($this->http !== null) {
            $response = $this->http
                ->withBasicAuth($accountSid, $authToken)
                ->timeout(30)
                ->asForm()
                ->post($url, $data);

            if ($response->status() !== 201) {
                throw new \RuntimeException(
                    sprintf('Twilio SMS failed (HTTP %d): %s', $response->status(), $response->body())
                );
            }
        } else {
            $this->sendViaCurl($url, $data, "{$accountSid}:{$authToken}", 201);
        }
    }

    /**
     * Send SMS via Nexmo/Vonage.
     *
     * @param string $to
     * @param SmsMessage $message
     * @return void
     */
    private function sendViaNexmo(string $to, SmsMessage $message): void
    {
        $apiKey = $this->credentials['api_key'] ?? '';
        $apiSecret = $this->credentials['api_secret'] ?? '';
        $from = $message->from ?? $this->credentials['from'] ?? '';

        if (!$apiKey || !$apiSecret || !$from) {
            throw new \RuntimeException(
                'Nexmo credentials not configured. Required: api_key, api_secret, from'
            );
        }

        $url = 'https://rest.nexmo.com/sms/json';

        $data = [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'from' => $from,
            'to' => $to,
            'text' => $message->content
        ];

        // Use HTTP client if available
        if ($this->http !== null) {
            $response = $this->http
                ->timeout(30)
                ->asForm()
                ->post($url, $data);

            $result = $response->json();

            if (!isset($result['messages'][0]['status']) || $result['messages'][0]['status'] !== '0') {
                throw new \RuntimeException(
                    sprintf('Nexmo SMS failed: %s', $response->body())
                );
            }
        } else {
            $this->sendViaNexmoCurl($url, $data);
        }
    }

    /**
     * Send SMS via AWS SNS.
     *
     * @param string $to
     * @param SmsMessage $message
     * @return void
     */
    private function sendViaAwsSns(string $to, SmsMessage $message): void
    {
        // Check if AWS SDK is available
        if (!class_exists('Aws\Sns\SnsClient')) {
            throw new \RuntimeException(
                'AWS SNS SMS requires aws/aws-sdk-php package. Run: composer require aws/aws-sdk-php'
            );
        }

        $region = $this->credentials['region'] ?? 'us-east-1';
        $key = $this->credentials['key'] ?? '';
        $secret = $this->credentials['secret'] ?? '';

        if (!$key || !$secret) {
            throw new \RuntimeException(
                'AWS SNS credentials not configured. Required: key, secret, region'
            );
        }

        $client = new \Aws\Sns\SnsClient([
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $key,
                'secret' => $secret
            ]
        ]);

        $result = $client->publish([
            'PhoneNumber' => $to,
            'Message' => $message->content,
            'MessageAttributes' => [
                'AWS.SNS.SMS.SenderID' => [
                    'DataType' => 'String',
                    'StringValue' => $message->from ?? $this->credentials['from'] ?? 'NOTICE'
                ],
                'AWS.SNS.SMS.SMSType' => [
                    'DataType' => 'String',
                    'StringValue' => 'Transactional'
                ]
            ]
        ]);

        if (empty($result['MessageId'])) {
            throw new \RuntimeException('AWS SNS SMS failed: No MessageId returned');
        }
    }

    /**
     * Send request via cURL (fallback).
     *
     * @param string $url
     * @param array $data
     * @param string|null $auth Basic auth credentials
     * @param int $expectedCode
     * @return void
     */
    private function sendViaCurl(string $url, array $data, ?string $auth = null, int $expectedCode = 200): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL for SMS');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($auth !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $auth);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("SMS cURL error: {$curlError}");
        }

        if ($httpCode !== $expectedCode) {
            throw new \RuntimeException(
                sprintf('SMS failed (HTTP %d, expected %d): %s', $httpCode, $expectedCode, $response)
            );
        }
    }

    /**
     * Send Nexmo request via cURL (fallback).
     *
     * @param string $url
     * @param array $data
     * @return void
     */
    private function sendViaNexmoCurl(string $url, array $data): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL for Nexmo SMS');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Nexmo SMS cURL error: {$curlError}");
        }

        $result = json_decode($response, true);

        if (!isset($result['messages'][0]['status']) || $result['messages'][0]['status'] !== '0') {
            $errorText = $result['messages'][0]['error-text'] ?? 'Unknown error';
            throw new \RuntimeException("Nexmo SMS failed: {$errorText}");
        }
    }
}
