<?php

declare(strict_types=1);

namespace Toporia\Framework\Webhook;

use Toporia\Framework\Webhook\Contracts\{WebhookReceiverInterface, SignatureGeneratorInterface};
use Toporia\Framework\Http\Request;
use Toporia\Framework\Log\Contracts\LoggerInterface;

/**
 * Class WebhookReceiver
 *
 * Receives and processes incoming webhooks with signature verification.
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
final class WebhookReceiver implements WebhookReceiverInterface
{
    /**
     * @param SignatureGeneratorInterface $signatureGenerator Signature generator for verification
     * @param LoggerInterface|null $logger Logger for webhook events
     */
    public function __construct(
        private SignatureGeneratorInterface $signatureGenerator,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * {@inheritdoc}
     */
    public function verifySignature(Request $request, string $secret, string $algorithm = 'sha256'): bool
    {
        // Get signature from headers (common header names)
        $signature = $this->extractSignature($request);

        if ($signature === null) {
            $this->log('warning', 'Webhook signature missing', [
                'headers' => $request->headers(),
            ]);
            return false;
        }

        // Extract payload
        $payload = $this->extractPayload($request);

        // Verify signature
        $isValid = $this->signatureGenerator->verify($signature, $payload, $secret);

        if (!$isValid) {
            $this->log('warning', 'Webhook signature verification failed', [
                'endpoint' => $request->path(),
                'algorithm' => $algorithm,
            ]);
        }

        return $isValid;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, string $secret, ?callable $handler = null): array
    {
        // Verify signature
        if (!$this->verifySignature($request, $secret)) {
            throw new \RuntimeException('Invalid webhook signature');
        }

        // Extract event and payload
        $event = $this->extractEvent($request);
        $payload = $this->extractPayload($request);

        $data = [
            'event' => $event,
            'payload' => $payload,
            'headers' => $request->headers(),
            'timestamp' => now()->getTimestamp(),
        ];

        // Call handler if provided
        if ($handler !== null) {
            $handler($event, $payload, $request);
        }

        $this->log('info', 'Webhook processed successfully', [
            'event' => $event,
            'endpoint' => $request->path(),
        ]);

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function extractEvent(Request $request): string
    {
        // Try common header names
        $event = $request->header('X-Webhook-Event')
            ?? $request->header('X-GitHub-Event')
            ?? $request->header('X-Event-Type')
            ?? $request->header('X-Event-Name')
            ?? $request->input('event')
            ?? 'unknown';

        return (string) $event;
    }

    /**
     * {@inheritdoc}
     */
    public function extractPayload(Request $request): array
    {
        // Try JSON body first
        if ($request->isJson()) {
            $payload = $request->json();
            if (is_array($payload)) {
                return $payload;
            }
        }

        // Fallback to all input
        return $request->all();
    }

    /**
     * Extract signature from request headers.
     *
     * @param Request $request HTTP request
     * @return string|null Signature or null if not found
     */
    private function extractSignature(Request $request): ?string
    {
        // Try common header names
        $signature = $request->header('X-Webhook-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? $request->header('X-Hub-Signature')
            ?? $request->header('X-Signature')
            ?? $request->header('Signature');

        if ($signature === null) {
            return null;
        }

        // Handle "sha256=..." format
        if (str_contains($signature, '=')) {
            [, $signature] = explode('=', $signature, 2);
        }

        return $signature;
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

