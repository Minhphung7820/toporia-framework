<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

/**
 * Class TransportException
 *
 * Thrown when mail transport operations fail.
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
class TransportException extends \RuntimeException
{
    /**
     * @var bool Whether this error is retryable (transient failure)
     */
    private bool $retryable = true;

    /**
     * @param string $message Error message.
     * @param string $transport Transport name.
     * @param array<string, mixed> $context Additional context.
     * @param \Throwable|null $previous Previous exception.
     */
    public function __construct(
        string $message,
        private readonly string $transport = '',
        private readonly array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);

        // Auto-detect if error is retryable based on message
        $this->detectRetryability($message);
    }

    /**
     * Get transport name.
     *
     * @return string
     */
    public function getTransport(): string
    {
        return $this->transport;
    }

    /**
     * Get additional context.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Create from API error.
     *
     * @param string $transport Transport name.
     * @param int $statusCode HTTP status code.
     * @param string $response API response.
     * @return self
     */
    public static function fromApiError(string $transport, int $statusCode, string $response): self
    {
        return new self(
            message: "API request failed with status {$statusCode}",
            transport: $transport,
            context: [
                'status_code' => $statusCode,
                'response' => $response,
            ]
        );
    }

    /**
     * Create connection error.
     *
     * @param string $transport Transport name.
     * @param string $host Host that failed to connect.
     * @param \Throwable|null $previous Previous exception.
     * @return self
     */
    public static function connectionFailed(string $transport, string $host, ?\Throwable $previous = null): self
    {
        return new self(
            message: "Failed to connect to {$host}",
            transport: $transport,
            context: ['host' => $host],
            previous: $previous
        );
    }

    /**
     * Create authentication error.
     *
     * @param string $transport Transport name.
     * @return self
     */
    public static function authenticationFailed(string $transport): self
    {
        $exception = new self(
            message: 'Authentication failed',
            transport: $transport
        );
        $exception->retryable = false;  // Auth errors usually not retryable

        return $exception;
    }

    /**
     * Check if this exception is retryable.
     *
     * Transient errors (network, timeout, rate limits) are retryable.
     * Permanent errors (auth failed, invalid recipient) are not.
     *
     * @return bool
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Mark exception as not retryable.
     *
     * @return self
     */
    public function setNotRetryable(): self
    {
        $this->retryable = false;
        return $this;
    }

    /**
     * Mark exception as retryable.
     *
     * @return self
     */
    public function setRetryable(): self
    {
        $this->retryable = true;
        return $this;
    }

    /**
     * Auto-detect if error is retryable based on message/code.
     *
     * @param string $message Error message
     */
    private function detectRetryability(string $message): void
    {
        $message = strtolower($message);

        // Permanent failures (NOT retryable)
        $permanentPatterns = [
            'authentication failed',
            'invalid recipient',
            'recipient rejected',
            'sender rejected',
            'mailbox unavailable',
            '550',  // Mailbox unavailable
            '551',  // User not local
            '553',  // Mailbox name not allowed
            '5.1.1', // Bad destination mailbox
            '5.7.1', // Relay access denied
        ];

        foreach ($permanentPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                $this->retryable = false;
                return;
            }
        }

        // Transient failures (retryable)
        $transientPatterns = [
            'timeout',
            'connection',
            'network',
            'rate limit',
            'too many',
            '421',  // Service not available
            '450',  // Mailbox busy
            '451',  // Local error
            '452',  // Insufficient storage
            '4.2.1', // Mailbox busy
            '4.2.2', // Mailbox full
        ];

        foreach ($transientPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                $this->retryable = true;
                return;
            }
        }

        // Default: retryable
        $this->retryable = true;
    }
}
