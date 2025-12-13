<?php

declare(strict_types=1);

namespace Toporia\Framework\Webhook;

use Toporia\Framework\Webhook\Contracts\{WebhookDispatcherInterface, SignatureGeneratorInterface};
use Toporia\Framework\Webhook\Jobs\DispatchWebhookJob;
use Toporia\Framework\Http\Contracts\{HttpClientInterface, HttpResponseInterface};
use Toporia\Framework\Queue\Contracts\QueueManagerInterface;
use Toporia\Framework\Log\Contracts\LoggerInterface;

/**
 * Class WebhookDispatcher
 *
 * Dispatches webhooks to external endpoints with retry, signature, and async support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Webhook
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class WebhookDispatcher implements WebhookDispatcherInterface
{
    /**
     * @param HttpClientInterface $httpClient HTTP client for sending requests
     * @param SignatureGeneratorInterface $signatureGenerator Signature generator
     * @param QueueManagerInterface|null $queue Queue manager for async dispatch
     * @param LoggerInterface|null $logger Logger for webhook events
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private SignatureGeneratorInterface $signatureGenerator,
        private ?QueueManagerInterface $queue = null,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * {@inheritdoc}
     */
    public function dispatch(string $event, mixed $payload, array $endpoints, array $options = []): array
    {
        $results = [];

        foreach ($endpoints as $endpoint) {
            $results[$endpoint] = $this->dispatchTo($event, $payload, $endpoint, $options);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchTo(string $event, mixed $payload, string $endpoint, array $options = []): bool
    {
        $secret = $options['secret'] ?? '';
        $timeout = $options['timeout'] ?? 30;
        $retry = $options['retry'] ?? 0;
        $retryDelay = $options['retry_delay'] ?? 1000; // milliseconds
        $headers = $options['headers'] ?? [];
        $method = $options['method'] ?? 'POST';

        // Prepare payload
        $data = [
            'event' => $event,
            'timestamp' => now()->getTimestamp(),
            'data' => $payload,
        ];

        // Generate signature if secret provided
        if (!empty($secret)) {
            $signature = $this->signatureGenerator->generate($data, $secret);
            $headers['X-Webhook-Signature'] = $signature;
            $headers['X-Webhook-Signature-Algorithm'] = $this->signatureGenerator->getAlgorithm();
        }

        // Add event header
        $headers['X-Webhook-Event'] = $event;
        $headers['Content-Type'] = 'application/json';

        // Prepare HTTP client
        $client = $this->httpClient
            ->withHeaders($headers)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson();

        // Retry logic
        $attempt = 0;
        $maxAttempts = $retry + 1;

        while ($attempt < $maxAttempts) {
            try {
                $response = $this->sendRequest($client, $method, $endpoint, $data);

                if ($response->successful()) {
                    $this->log('info', "Webhook dispatched successfully", [
                        'event' => $event,
                        'endpoint' => $endpoint,
                        'status' => $response->status(),
                    ]);

                    return true;
                }

                // Non-2xx response
                $this->log('warning', "Webhook returned non-success status", [
                    'event' => $event,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                if ($attempt < $maxAttempts - 1) {
                    usleep($retryDelay * 1000); // Convert to microseconds
                    $attempt++;
                    continue;
                }

                return false;
            } catch (\Throwable $e) {
                $this->log('error', "Webhook dispatch failed", [
                    'event' => $event,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);

                if ($attempt < $maxAttempts - 1) {
                    usleep($retryDelay * 1000);
                    $attempt++;
                    continue;
                }

                return false;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function queue(string $event, mixed $payload, string $endpoint, array $options = []): void
    {
        if ($this->queue === null) {
            throw new \RuntimeException('Queue manager not available. Install queue service or dispatch synchronously.');
        }

        // Create job instance with proper constructor arguments
        $job = new DispatchWebhookJob(
            event: $event,
            payload: $payload,
            endpoint: $endpoint,
            options: $options
        );

        $queueName = $options['queue'] ?? 'webhooks';
        $this->queue->push($job, $queueName);
    }

    /**
     * Send HTTP request.
     *
     * @param HttpClientInterface $client HTTP client
     * @param string $method HTTP method
     * @param string $endpoint Target URL
     * @param array<string, mixed> $data Request data
     * @return HttpResponseInterface
     */
    private function sendRequest(HttpClientInterface $client, string $method, string $endpoint, array $data): HttpResponseInterface
    {
        return match (strtoupper($method)) {
            'GET' => $client->get($endpoint, $data),
            'POST' => $client->post($endpoint, $data),
            'PUT' => $client->put($endpoint, $data),
            'PATCH' => $client->patch($endpoint, $data),
            'DELETE' => $client->delete($endpoint),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }

    /**
     * Log webhook event.
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }
}
