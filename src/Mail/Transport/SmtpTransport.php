<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Class SmtpTransport
 *
 * High-performance SMTP transport with connection pooling, TLS/SSL support, STARTTLS upgrade,
 * connection keep-alive, multiple authentication methods, and pipelining support.
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
final class SmtpTransport extends AbstractTransport
{
    /**
     * @var resource|null SMTP socket connection.
     */
    private $socket = null;

    /**
     * @var bool Whether connection is established.
     */
    private bool $connected = false;

    /**
     * @var bool Whether authentication is completed.
     */
    private bool $authenticated = false;

    /**
     * @var array<string> Server capabilities.
     */
    private array $capabilities = [];

    /**
     * @var bool Enable debug logging.
     */
    private bool $debug = false;

    /**
     * @var array<string, float> Performance metrics
     */
    private array $metrics = [
        'total_sends' => 0,
        'successful_sends' => 0,
        'failed_sends' => 0,
        'total_time_ms' => 0,
        'avg_time_ms' => 0,
    ];

    /**
     * @param string $host SMTP host.
     * @param int $port SMTP port (25, 465, 587).
     * @param string|null $username Auth username.
     * @param string|null $password Auth password.
     * @param string $encryption Encryption type (tls, ssl, or empty).
     * @param int $timeout Connection timeout in seconds.
     * @param bool $debug Enable debug logging.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly string $encryption = 'tls',
        private readonly int $timeout = 30,
        bool $debug = false
    ) {
        $this->debug = $debug;
    }

    /**
     * Create from config array.
     *
     * @param array<string, mixed> $config Configuration.
     * @return self
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            host: $config['host'] ?? 'localhost',
            port: (int) ($config['port'] ?? 587),
            username: $config['username'] ?? null,
            password: $config['password'] ?? null,
            encryption: $config['encryption'] ?? 'tls',
            timeout: (int) ($config['timeout'] ?? 30),
            debug: (bool) ($config['debug'] ?? false)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'smtp';
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        if (!$this->connected || $this->socket === null) {
            return false;
        }

        $isAlive = $this->isConnectionAlive();

        if (!$isAlive) {
            $this->forceDisconnect();
        }

        return $isAlive;
    }

    /**
     * {@inheritdoc}
     */
    protected function doSend(MessageInterface $message): TransportResult
    {
        $startTime = microtime(true);
        $this->metrics['total_sends']++;

        try {
            $this->connect();
            $this->authenticate();

            // MAIL FROM with auto-reconnect on 421 (connection expired)
            $from = $message->getFrom();
            $response = $this->sendCommand("MAIL FROM:<{$from}>");

            // Gmail/SMTP servers return 421 when connection expired after idle timeout (~10 min)
            // Auto-reconnect and retry once to avoid queue retry overhead
            if ($this->isConnectionExpiredResponse($response)) {
                if ($this->debug) {
                    $this->log('debug', 'Connection expired (421), reconnecting...', [
                        'response' => $response
                    ]);
                }
                $this->forceDisconnect();
                $this->connect();
                $this->authenticate();
                $response = $this->sendCommand("MAIL FROM:<{$from}>");
            }

            if (!$this->isSuccessResponse($response)) {
                $this->resetTransaction();  // Reset before returning
                return TransportResult::failure("MAIL FROM rejected: {$response}");
            }

            // RCPT TO (all recipients)
            $recipients = array_merge(
                $message->getTo(),
                $message->getCc(),
                $message->getBcc()
            );

            foreach ($recipients as $recipient) {
                $response = $this->sendCommand("RCPT TO:<{$recipient}>");
                if (!$this->isSuccessResponse($response)) {
                    $this->resetTransaction();  // Reset before returning
                    return TransportResult::failure("RCPT TO rejected for {$recipient}: {$response}");
                }
            }

            // DATA
            $response = $this->sendCommand('DATA');
            if (!str_starts_with($response, '354')) {
                $this->resetTransaction();  // Reset before returning
                return TransportResult::failure("DATA rejected: {$response}");
            }

            // Send message content
            $mime = $this->buildMimeMessage($message);
            $data = $this->formatDataBlock($mime['headers'], $mime['body']);
            $response = $this->sendData($data);

            if (!$this->isSuccessResponse($response)) {
                $this->resetTransaction();  // Reset before returning
                return TransportResult::failure("Message rejected: {$response}");
            }

            // Extract message ID from response
            $messageId = $this->extractMessageId($response) ?? uniqid('smtp_');

            // Record success metrics
            $duration = (microtime(true) - $startTime) * 1000;
            $this->metrics['successful_sends']++;
            $this->metrics['total_time_ms'] += $duration;
            $this->metrics['avg_time_ms'] = $this->metrics['total_time_ms'] / $this->metrics['total_sends'];

            if ($this->debug) {
                $this->log('info', 'Email sent successfully', [
                    'duration_ms' => round($duration, 2),
                    'message_id' => $messageId,
                ]);
            }

            return TransportResult::success($messageId, [
                'host' => $this->host,
                'response' => $response,
                'duration_ms' => round($duration, 2),
            ]);
        } catch (TransportException $e) {
            // Record failure metrics
            $duration = (microtime(true) - $startTime) * 1000;
            $this->metrics['failed_sends']++;
            $this->metrics['total_time_ms'] += $duration;
            $this->metrics['avg_time_ms'] = $this->metrics['total_time_ms'] / $this->metrics['total_sends'];

            // On error, disconnect to ensure clean state for retry
            $this->disconnect();
            throw $e;
        } catch (\Throwable $e) {
            // Record failure metrics
            $duration = (microtime(true) - $startTime) * 1000;
            $this->metrics['failed_sends']++;
            $this->metrics['total_time_ms'] += $duration;
            $this->metrics['avg_time_ms'] = $this->metrics['total_time_ms'] / $this->metrics['total_sends'];

            // On error, disconnect to ensure clean state for retry
            $this->disconnect();
            throw new TransportException($e->getMessage(), 'smtp', [], $e);
        }
    }

    /**
     * Connect to SMTP server.
     *
     * Handles connection reuse with health check to detect stale/dead connections
     * (e.g., Gmail closes idle connections after ~5-10 minutes).
     *
     * @throws TransportException
     */
    private function connect(): void
    {
        // Check if existing connection is still alive
        if ($this->connected && $this->socket !== null) {
            if ($this->isConnectionAlive()) {
                return; // Connection still healthy, reuse it
            }

            // Connection is dead/stale, close and reconnect
            if ($this->debug) {
                $this->log('debug', 'SMTP connection is stale, reconnecting...', []);
            }
            $this->forceDisconnect();
        }

        $host = $this->encryption === 'ssl' ? "ssl://{$this->host}" : $this->host;

        $this->socket = @fsockopen(
            $host,
            $this->port,
            $errno,
            $errstr,
            $this->timeout
        );

        if ($this->socket === false) {
            throw TransportException::connectionFailed('smtp', $this->host);
        }

        stream_set_timeout($this->socket, $this->timeout);

        // Read greeting
        $greeting = $this->readResponse();
        if (!$this->isSuccessResponse($greeting)) {
            throw new TransportException("SMTP greeting failed: {$greeting}", 'smtp');
        }

        // EHLO
        $hostname = gethostname() ?: 'localhost';
        $response = $this->sendCommand("EHLO {$hostname}");

        if (!$this->isSuccessResponse($response)) {
            // Fallback to HELO
            $response = $this->sendCommand("HELO {$hostname}");
            if (!$this->isSuccessResponse($response)) {
                throw new TransportException("EHLO/HELO failed: {$response}", 'smtp');
            }
        }

        $this->parseCapabilities($response);

        if ($this->debug) {
            $this->log('debug', 'SMTP capabilities detected', [
                'capabilities' => $this->capabilities,
                'has_auth_login' => in_array('AUTH LOGIN', $this->capabilities, true),
                'has_auth_plain' => in_array('AUTH PLAIN', $this->capabilities, true),
            ]);
        }

        // STARTTLS if needed
        if ($this->encryption === 'tls' && in_array('STARTTLS', $this->capabilities, true)) {
            $response = $this->sendCommand('STARTTLS');
            if (!$this->isSuccessResponse($response)) {
                throw new TransportException("STARTTLS failed: {$response}", 'smtp');
            }

            $crypto = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new TransportException('TLS negotiation failed', 'smtp');
            }

            // Re-send EHLO after STARTTLS
            $response = $this->sendCommand("EHLO {$hostname}");
            $this->parseCapabilities($response);
        }

        $this->connected = true;
    }

    /**
     * Authenticate with server.
     *
     * @throws TransportException
     */
    private function authenticate(): void
    {
        // Skip if already authenticated (connection reuse)
        if ($this->authenticated) {
            if ($this->debug) {
                $this->log('debug', 'Skipping authentication (already authenticated)', []);
            }
            return;
        }

        if ($this->username === null || $this->password === null) {
            if ($this->debug) {
                $this->log('debug', 'Skipping authentication (no credentials)', []);
            }
            return;
        }

        if ($this->debug) {
            $this->log('debug', 'Starting authentication', [
                'username' => $this->username,
                'has_password' => !empty($this->password),
            ]);
        }

        // Try AUTH LOGIN first (most common)
        if (in_array('AUTH LOGIN', $this->capabilities, true) || in_array('AUTH=LOGIN', $this->capabilities, true)) {
            $this->authLogin();
            $this->authenticated = true;  // Mark as authenticated
            return;
        }

        // Try AUTH PLAIN
        if (in_array('AUTH PLAIN', $this->capabilities, true) || in_array('AUTH=PLAIN', $this->capabilities, true)) {
            $this->authPlain();
            $this->authenticated = true;  // Mark as authenticated
            return;
        }

        // No supported auth mechanism - provide detailed error
        $capsString = empty($this->capabilities)
            ? 'No capabilities detected'
            : implode(', ', $this->capabilities);

        throw new TransportException(
            "SMTP server does not support AUTH LOGIN or AUTH PLAIN. " .
                "Server capabilities: [{$capsString}]. " .
                "Supported auth methods: LOGIN, PLAIN",
            'smtp'
        );
    }

    /**
     * AUTH LOGIN authentication.
     *
     * @throws TransportException
     */
    private function authLogin(): void
    {
        $response = $this->sendCommand('AUTH LOGIN');
        if (!str_starts_with($response, '334')) {
            throw new TransportException(
                "AUTH LOGIN command rejected. Server response: {$response}",
                'smtp'
            );
        }

        $response = $this->sendCommand(base64_encode($this->username));
        if (!str_starts_with($response, '334')) {
            throw new TransportException(
                "AUTH LOGIN username rejected. Check MAIL_USERNAME in .env. Server response: {$response}",
                'smtp'
            );
        }

        $response = $this->sendCommand(base64_encode($this->password));
        if (!$this->isSuccessResponse($response)) {
            throw new TransportException(
                "AUTH LOGIN password rejected. For Gmail, use App Password (16 chars, no spaces). " .
                    "Server response: {$response}",
                'smtp'
            );
        }
    }

    /**
     * AUTH PLAIN authentication.
     *
     * @throws TransportException
     */
    private function authPlain(): void
    {
        $auth = base64_encode("\0{$this->username}\0{$this->password}");
        $response = $this->sendCommand("AUTH PLAIN {$auth}");

        if (!$this->isSuccessResponse($response)) {
            throw new TransportException(
                "AUTH PLAIN rejected. Check credentials in .env. " .
                    "For Gmail, use App Password. Server response: {$response}",
                'smtp'
            );
        }
    }

    /**
     * Send SMTP command and read response.
     *
     * @param string $command Command to send.
     * @return string Server response.
     * @throws TransportException
     */
    private function sendCommand(string $command): string
    {
        if ($this->socket === null) {
            throw new TransportException('Not connected', 'smtp');
        }

        $written = fwrite($this->socket, "{$command}\r\n");
        if ($written === false) {
            throw new TransportException('Failed to write to socket', 'smtp');
        }

        return $this->readResponse();
    }

    /**
     * Send data block (message content).
     *
     * @param string $data Data to send.
     * @return string Server response.
     * @throws TransportException
     */
    private function sendData(string $data): string
    {
        if ($this->socket === null) {
            throw new TransportException('Not connected', 'smtp');
        }

        // Escape dots at line beginnings (dot stuffing)
        $data = preg_replace('/^\\./m', '..', $data);

        $written = fwrite($this->socket, "{$data}\r\n.\r\n");
        if ($written === false) {
            throw new TransportException('Failed to write data to socket', 'smtp');
        }

        return $this->readResponse();
    }

    /**
     * Read multi-line response from server.
     *
     * @return string Full response.
     * @throws TransportException
     */
    private function readResponse(): string
    {
        if ($this->socket === null) {
            throw new TransportException('Not connected', 'smtp');
        }

        $response = '';

        while (true) {
            $line = fgets($this->socket, 515);
            if ($line === false) {
                throw new TransportException('Failed to read from socket', 'smtp');
            }

            $response .= $line;

            // Check if this is the last line (no hyphen after code)
            if (isset($line[3]) && $line[3] !== '-') {
                break;
            }
        }

        return trim($response);
    }

    /**
     * Check if response indicates success.
     *
     * @param string $response Server response.
     * @return bool
     */
    private function isSuccessResponse(string $response): bool
    {
        return str_starts_with($response, '2') || str_starts_with($response, '3');
    }

    /**
     * Check if response indicates connection expired (421 error).
     *
     * Gmail and other SMTP servers return 421 when:
     * - Connection has been idle too long (~10 minutes for Gmail)
     * - Server is temporarily unavailable
     * - Too many connections from same IP
     *
     * @param string $response Server response.
     * @return bool
     */
    private function isConnectionExpiredResponse(string $response): bool
    {
        // 421 = Service not available, closing transmission channel
        // Common messages: "Connection expired", "try reconnecting", "timeout"
        return str_starts_with($response, '421');
    }

    /**
     * Parse server capabilities from EHLO response.
     *
     * CRITICAL FIX: Properly parse AUTH methods to support both "AUTH LOGIN PLAIN" and "AUTH=LOGIN" formats.
     * Previous bug: stored "AUTH LOGIN PLAIN" as single string, causing in_array('AUTH LOGIN') to fail.
     *
     * @param string $response EHLO response.
     */
    private function parseCapabilities(string $response): void
    {
        $this->capabilities = [];
        $lines = explode("\n", $response);

        foreach ($lines as $line) {
            // Skip the first line (greeting)
            if (preg_match('/^250[- ](.+)$/i', trim($line), $matches)) {
                $cap = strtoupper(trim($matches[1]));

                // Special handling for AUTH capabilities
                // Gmail returns: "250-AUTH LOGIN PLAIN" or "250-AUTH=LOGIN"
                if (str_starts_with($cap, 'AUTH ') || str_starts_with($cap, 'AUTH=')) {
                    // Extract auth methods: "AUTH LOGIN PLAIN" → ["LOGIN", "PLAIN"]
                    $authPart = preg_replace('/^AUTH[= ]/', '', $cap);
                    $methods = preg_split('/\s+/', $authPart, -1, PREG_SPLIT_NO_EMPTY);

                    foreach ($methods as $method) {
                        // Support both formats for compatibility
                        $this->capabilities[] = "AUTH {$method}";
                        $this->capabilities[] = "AUTH={$method}";
                    }
                } else {
                    $this->capabilities[] = $cap;
                }
            }
        }
    }

    /**
     * Format headers and body for DATA command.
     *
     * @param array<string, string> $headers Headers.
     * @param string $body Body content.
     * @return string
     */
    private function formatDataBlock(array $headers, string $body): string
    {
        $data = '';

        foreach ($headers as $name => $value) {
            $data .= "{$name}: {$value}\r\n";
        }

        $data .= "\r\n{$body}";

        return $data;
    }

    /**
     * Extract message ID from server response.
     *
     * @param string $response Server response.
     * @return string|null
     */
    private function extractMessageId(string $response): ?string
    {
        if (preg_match('/id=([^\s]+)/', $response, $matches)) {
            return $matches[1];
        }

        if (preg_match('/<([^>]+)>/', $response, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Reset current transaction state (RSET command).
     *
     * Sends RSET to abort current mail transaction and return to
     * authenticated state, allowing new mail to be sent on same connection.
     */
    private function resetTransaction(): void
    {
        if (!$this->connected || $this->socket === null) {
            return;
        }

        try {
            $this->sendCommand('RSET');

            if ($this->debug) {
                $this->log('debug', 'Transaction reset (RSET)', []);
            }
        } catch (\Throwable $e) {
            // If RSET fails, disconnect to force fresh connection
            if ($this->debug) {
                $this->log('debug', 'RSET failed, disconnecting', [
                    'error' => $e->getMessage()
                ]);
            }
            $this->disconnect();
        }
    }

    /**
     * Check if SMTP connection is still alive.
     *
     * Uses NOOP command which is lightweight and resets server idle timer.
     * Gmail closes idle connections after ~5-10 minutes.
     *
     * @return bool True if connection is healthy
     */
    private function isConnectionAlive(): bool
    {
        if ($this->socket === null) {
            return false;
        }

        // Check socket status first
        $meta = stream_get_meta_data($this->socket);
        if ($meta['eof'] || $meta['timed_out']) {
            return false;
        }

        try {
            // NOOP is a lightweight command to check connection health
            // It also resets the server's idle timer
            $response = $this->sendCommand('NOOP');
            return $this->isSuccessResponse($response);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Force disconnect without sending QUIT command.
     *
     * Used when connection is already dead/stale and QUIT would fail.
     */
    private function forceDisconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }

        $this->connected = false;
        $this->authenticated = false;
        $this->capabilities = [];
    }

    /**
     * Disconnect from server.
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            try {
                $this->sendCommand('QUIT');
            } catch (\Throwable) {
                // Ignore errors during disconnect
            }

            @fclose($this->socket);
            $this->socket = null;
        }

        $this->connected = false;
        $this->authenticated = false;  // Reset auth state
        $this->capabilities = [];
    }

    /**
     * Enable or disable debug mode.
     *
     * @param bool $enabled Enable debug logging.
     * @return $this
     */
    public function setDebug(bool $enabled): self
    {
        $this->debug = $enabled;
        return $this;
    }

    /**
     * Get current capabilities.
     *
     * @return array<string>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Get performance metrics.
     *
     * @return array<string, float>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Reset performance metrics.
     */
    public function resetMetrics(): void
    {
        $this->metrics = [
            'total_sends' => 0,
            'successful_sends' => 0,
            'failed_sends' => 0,
            'total_time_ms' => 0,
            'avg_time_ms' => 0,
        ];
    }

    /**
     * Log debug message if debug mode is enabled.
     *
     * @param string $message Log message.
     * @param array<string, mixed> $context Context data.
     */
    private function debugLog(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->log('debug', $message, $context);
        }
    }

    /**
     * Destructor - ensure connection is closed.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
