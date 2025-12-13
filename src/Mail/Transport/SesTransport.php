<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Class SesTransport
 *
 * Send emails via AWS Simple Email Service with support for raw email sending, SendEmail API,
 * AWS Signature V4 authentication, configuration sets, and bounce/complaint handling.
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
final class SesTransport extends AbstractTransport
{
    private const SERVICE = 'ses';
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * @param string $key AWS Access Key ID.
     * @param string $secret AWS Secret Access Key.
     * @param string $region AWS region.
     * @param array<string, mixed> $options Additional options.
     */
    public function __construct(
        private readonly string $key,
        private readonly string $secret,
        private readonly string $region = 'us-east-1',
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
            key: $config['key'] ?? '',
            secret: $config['secret'] ?? '',
            region: $config['region'] ?? 'us-east-1',
            options: $config['options'] ?? []
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'ses';
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        try {
            // Try to get send quota
            $response = $this->callApi('GetSendQuota', []);
            return isset($response['GetSendQuotaResult']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doSend(MessageInterface $message): TransportResult
    {
        // Build raw MIME message
        $mime = $this->buildMimeMessage($message);
        $rawMessage = $this->formatRawMessage($mime['headers'], $mime['body']);

        $params = [
            'RawMessage.Data' => base64_encode($rawMessage),
        ];

        // Add configuration set if specified
        if (isset($this->options['configuration_set'])) {
            $params['ConfigurationSetName'] = $this->options['configuration_set'];
        }

        try {
            $response = $this->callApi('SendRawEmail', $params);

            if (isset($response['SendRawEmailResult']['MessageId'])) {
                return TransportResult::success(
                    $response['SendRawEmailResult']['MessageId'],
                    ['request_id' => $response['ResponseMetadata']['RequestId'] ?? null]
                );
            }

            return TransportResult::failure($response['Error']['Message'] ?? 'Unknown error');
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 'ses', [], $e);
        }
    }

    /**
     * Format raw email message.
     *
     * @param array<string, string> $headers Headers.
     * @param string $body Body content.
     * @return string
     */
    private function formatRawMessage(array $headers, string $body): string
    {
        $message = '';

        foreach ($headers as $name => $value) {
            $message .= "{$name}: {$value}\r\n";
        }

        $message .= "\r\n{$body}";

        return $message;
    }

    /**
     * Call SES API.
     *
     * @param string $action API action.
     * @param array<string, string> $params Request parameters.
     * @return array<string, mixed>
     * @throws TransportException
     */
    private function callApi(string $action, array $params): array
    {
        $host = "email.{$this->region}.amazonaws.com";
        $endpoint = "https://{$host}/";

        $params['Action'] = $action;
        $params['Version'] = '2010-12-01';

        $body = http_build_query($params);
        $datetime = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        // Create canonical request
        $headers = [
            'host' => $host,
            'x-amz-date' => $datetime,
            'content-type' => 'application/x-www-form-urlencoded',
        ];

        $canonicalHeaders = '';
        $signedHeaders = [];
        ksort($headers);
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            $signedHeaders[] = strtolower($key);
        }
        $signedHeadersStr = implode(';', $signedHeaders);

        $payloadHash = hash('sha256', $body);

        $canonicalRequest = implode("\n", [
            'POST',
            '/',
            '',
            $canonicalHeaders,
            $signedHeadersStr,
            $payloadHash,
        ]);

        // Create string to sign
        $credentialScope = "{$date}/{$this->region}/" . self::SERVICE . '/aws4_request';
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $datetime,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        // Calculate signature
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        // Build authorization header
        $authorization = self::ALGORITHM . ' ' .
            "Credential={$this->key}/{$credentialScope}, " .
            "SignedHeaders={$signedHeadersStr}, " .
            "Signature={$signature}";

        // Make request
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Host: ' . $host,
                'X-Amz-Date: ' . $datetime,
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: ' . $authorization,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw TransportException::connectionFailed('ses', $host);
        }

        // SECURITY: Disable external entity loading to prevent XXE attacks
        // Note: LIBXML_NONET blocks network access, we removed LIBXML_NOENT which enables entity expansion
        $previousValue = libxml_disable_entity_loader(true);
        $xml = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NONET);
        libxml_disable_entity_loader($previousValue);

        if ($xml === false) {
            throw new TransportException('Invalid XML response', 'ses', ['response' => $response]);
        }

        $result = $this->xmlToArray($xml);

        if ($statusCode >= 400) {
            throw TransportException::fromApiError('ses', $statusCode, $response);
        }

        return $result;
    }

    /**
     * Convert SimpleXMLElement to array.
     *
     * @param \SimpleXMLElement $xml XML element.
     * @return array<string, mixed>
     */
    private function xmlToArray(\SimpleXMLElement $xml): array
    {
        $result = [];

        foreach ($xml as $key => $value) {
            if ($value->count() > 0) {
                $result[$key] = $this->xmlToArray($value);
            } else {
                $result[$key] = (string) $value;
            }
        }

        return $result;
    }
}
