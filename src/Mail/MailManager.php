<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail;

use Toporia\Framework\Mail\Contracts\{MailManagerInterface, MailerInterface, MessageInterface};
use Toporia\Framework\Mail\Transport\{
    TransportInterface,
    SmtpTransport,
    SmtpConnectionPool,
    MailgunTransport,
    SesTransport,
    PostmarkTransport,
    ResendTransport,
    SendGridTransport,
    LogTransport,
    ArrayTransport
};
use Toporia\Framework\Queue\Contracts\QueueInterface;

/**
 * Class MailManager
 *
 * Manages multiple mail drivers with lazy loading following the Strategy pattern for driver selection.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Mail
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MailManager implements MailManagerInterface
{
    /**
     * @var array<string, MailerInterface> Resolved driver instances.
     */
    private array $drivers = [];

    /**
     * @var array<string, callable> Custom transport creators.
     */
    private array $customTransports = [];

    /**
     * @param array $config Mail configuration.
     * @param QueueInterface|null $queue Queue instance for async sending.
     */
    public function __construct(
        private array $config,
        private ?QueueInterface $queue = null
    ) {}

    /**
     * {@inheritdoc}
     */
    public function driver(?string $driver = null): MailerInterface
    {
        $driver = $driver ?? $this->getDefaultDriver();

        // Return cached driver if exists
        if (isset($this->drivers[$driver])) {
            return $this->drivers[$driver];
        }

        // Create and cache driver
        $this->drivers[$driver] = $this->createDriver($driver);

        return $this->drivers[$driver];
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultDriver(): string
    {
        return $this->config['default'] ?? 'smtp';
    }

    /**
     * {@inheritdoc}
     */
    public function send(MessageInterface $message): bool
    {
        return $this->driver()->send($message);
    }

    /**
     * {@inheritdoc}
     */
    public function sendMailable(Mailable $mailable): bool
    {
        return $this->driver()->sendMailable($mailable);
    }

    /**
     * {@inheritdoc}
     */
    public function queue(MessageInterface $message, int $delay = 0): bool
    {
        return $this->driver()->queue($message, $delay);
    }

    /**
     * {@inheritdoc}
     */
    public function queueMailable(Mailable $mailable, int $delay = 0): bool
    {
        return $this->driver()->queueMailable($mailable, $delay);
    }

    /**
     * Create a driver instance.
     *
     * @param string $driver Driver name.
     * @return MailerInterface
     * @throws \InvalidArgumentException
     */
    private function createDriver(string $driver): MailerInterface
    {
        $mailers = $this->config['mailers'] ?? [];

        if (!isset($mailers[$driver])) {
            throw new \InvalidArgumentException("Mail driver [{$driver}] is not configured.");
        }

        $config = $mailers[$driver];
        $transportType = $config['transport'] ?? $driver;

        // Check for custom transport
        if (isset($this->customTransports[$transportType])) {
            $transport = ($this->customTransports[$transportType])($config);
            return $this->createMailerFromTransport($transport, $config);
        }

        // Create transport based on type
        $transport = $this->createTransport($transportType, $config);

        return $this->createMailerFromTransport($transport, $config);
    }

    /**
     * Create transport from type and config.
     *
     * @param string $type Transport type.
     * @param array<string, mixed> $config Configuration.
     * @return TransportInterface
     */
    private function createTransport(string $type, array $config): TransportInterface
    {
        return match ($type) {
            'smtp', 'mail' => SmtpConnectionPool::get(
                host: $config['host'] ?? 'localhost',
                port: (int) ($config['port'] ?? 587),
                username: $config['username'] ?? null,
                password: $config['password'] ?? null,
                encryption: $config['encryption'] ?? 'tls',
                timeout: (int) ($config['timeout'] ?? 30),
                debug: (bool) ($config['debug'] ?? false)
            ),
            'mailgun' => new MailgunTransport(
                apiKey: $config['secret'] ?? $config['api_key'] ?? '',
                domain: $config['domain'] ?? '',
                region: $config['region'] ?? 'us'
            ),
            'ses' => new SesTransport(
                key: $config['key'] ?? '',
                secret: $config['secret'] ?? '',
                region: $config['region'] ?? 'us-east-1'
            ),
            'postmark' => new PostmarkTransport(
                token: $config['token'] ?? $config['secret'] ?? ''
            ),
            'resend' => new ResendTransport(
                apiKey: $config['key'] ?? $config['secret'] ?? ''
            ),
            'sendgrid' => new SendGridTransport(
                apiKey: $config['key'] ?? $config['secret'] ?? ''
            ),
            'log' => new LogTransport(
                logPath: $config['path'] ?? storage_path('logs/mail.log')
            ),
            'array' => new ArrayTransport(),
            default => throw new \InvalidArgumentException(
                "Unsupported mail transport [{$type}]. Supported: smtp, mailgun, ses, postmark, resend, sendgrid, log, array"
            ),
        };
    }

    /**
     * Create mailer from transport.
     *
     * @param TransportInterface $transport Transport instance.
     * @param array<string, mixed> $config Configuration.
     * @return Mailer
     */
    private function createMailerFromTransport(TransportInterface $transport, array $config): Mailer
    {
        $mailer = new Mailer($transport, $this->queue);

        // Set global from address
        $from = $this->config['from'] ?? [];
        if (!empty($from['address'])) {
            $mailer->alwaysFrom($from['address'], $from['name'] ?? '');
        }

        // Set global reply-to
        $replyTo = $this->config['reply_to'] ?? [];
        if (!empty($replyTo['address'])) {
            $mailer->alwaysReplyTo($replyTo['address'], $replyTo['name'] ?? '');
        }

        return $mailer;
    }

    /**
     * Register a custom transport creator.
     *
     * Example:
     * ```php
     * $manager->extend('custom', function (array $config) {
     *     return new CustomTransport($config);
     * });
     * ```
     *
     * @param string $transport Transport name.
     * @param callable $callback Creator callback.
     * @return $this
     */
    public function extend(string $transport, callable $callback): self
    {
        $this->customTransports[$transport] = $callback;
        return $this;
    }

    /**
     * Get all configured mailer names.
     *
     * @return array<string>
     */
    public function getAvailableMailers(): array
    {
        return array_keys($this->config['mailers'] ?? []);
    }

    /**
     * Get global from address.
     *
     * @return array{address: string, name: string}
     */
    public function getFromAddress(): array
    {
        return [
            'address' => $this->config['from']['address'] ?? '',
            'name' => $this->config['from']['name'] ?? '',
        ];
    }

    /**
     * Purge cached driver(s).
     *
     * @param string|null $driver Driver name (null for all).
     * @return $this
     */
    public function purge(?string $driver = null): self
    {
        if ($driver === null) {
            $this->drivers = [];
        } else {
            unset($this->drivers[$driver]);
        }

        return $this;
    }

    /**
     * Begin composing mail to recipient(s).
     *
     * @param string|array<string> $recipients Recipient(s).
     * @return PendingMail
     */
    public function to(string|array $recipients): PendingMail
    {
        return (new PendingMail($this->driver()))->to($recipients);
    }

    /**
     * Begin composing mail with CC.
     *
     * @param string|array<string> $recipients CC recipient(s).
     * @return PendingMail
     */
    public function cc(string|array $recipients): PendingMail
    {
        return (new PendingMail($this->driver()))->cc($recipients);
    }

    /**
     * Begin composing mail with BCC.
     *
     * @param string|array<string> $recipients BCC recipient(s).
     * @return PendingMail
     */
    public function bcc(string|array $recipients): PendingMail
    {
        return (new PendingMail($this->driver()))->bcc($recipients);
    }
}
