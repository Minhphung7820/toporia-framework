<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail;

use Toporia\Framework\Mail\Contracts\{MailerInterface, MessageInterface};
use Toporia\Framework\Mail\Transport\{TransportInterface, TransportResult};
use Toporia\Framework\Mail\Jobs\SendMailJob;
use Toporia\Framework\Queue\Contracts\QueueInterface;

/**
 * Class Mailer
 *
 * High-level mailer using transport abstraction with features including transport abstraction,
 * queue integration for async sending, global from/reply-to configuration, mailable support, and event hooks.
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
final class Mailer implements MailerInterface
{
    /**
     * @var array<callable> Before send callbacks.
     */
    private array $beforeSendCallbacks = [];

    /**
     * @var array<callable> After send callbacks.
     */
    private array $afterSendCallbacks = [];

    /**
     * @var array{address: string, name: string}|null Global from address.
     */
    private ?array $alwaysFromAddress = null;

    /**
     * @var array{address: string, name: string}|null Global reply-to address.
     */
    private ?array $alwaysReplyToAddress = null;

    /**
     * @param TransportInterface $transport Mail transport.
     * @param QueueInterface|null $queue Queue for async sending.
     * @param array<string, mixed> $config Mailer configuration.
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly ?QueueInterface $queue = null,
        private array $config = []
    ) {}

    /**
     * {@inheritdoc}
     */
    public function send(MessageInterface $message): bool
    {
        $message = $this->prepareMessage($message);

        $this->fireBeforeSend($message);

        $result = $this->transport->send($message);

        $this->fireAfterSend($message, $result);

        return $result->isSuccess();
    }

    /**
     * {@inheritdoc}
     */
    public function sendMailable(Mailable $mailable): bool
    {
        $message = $mailable->build();
        return $this->send($message);
    }

    /**
     * {@inheritdoc}
     */
    public function queue(MessageInterface $message, int $delay = 0): bool
    {
        if ($this->queue === null) {
            // No queue available, send synchronously
            return $this->send($message);
        }

        $job = new SendMailJob($message);

        if ($delay > 0) {
            $this->queue->later($job, $delay);
        } else {
            $this->queue->push($job);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function queueMailable(Mailable $mailable, int $delay = 0): bool
    {
        return $this->queue($mailable->build(), $delay);
    }

    /**
     * Send and get detailed result.
     *
     * @param MessageInterface $message Message to send.
     * @return TransportResult
     */
    public function sendWithResult(MessageInterface $message): TransportResult
    {
        $message = $this->prepareMessage($message);

        $this->fireBeforeSend($message);

        $result = $this->transport->send($message);

        $this->fireAfterSend($message, $result);

        return $result;
    }

    /**
     * Create a new message builder.
     *
     * @return PendingMail
     */
    public function to(string|array $recipients): PendingMail
    {
        return (new PendingMail($this))->to($recipients);
    }

    /**
     * Register before send callback.
     *
     * @param callable(MessageInterface): void $callback Callback.
     * @return $this
     */
    public function beforeSending(callable $callback): self
    {
        $this->beforeSendCallbacks[] = $callback;
        return $this;
    }

    /**
     * Register after send callback.
     *
     * @param callable(MessageInterface, TransportResult): void $callback Callback.
     * @return $this
     */
    public function afterSending(callable $callback): self
    {
        $this->afterSendCallbacks[] = $callback;
        return $this;
    }

    /**
     * Get the transport.
     *
     * @return TransportInterface
     */
    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * Check if transport is healthy.
     *
     * @return bool
     */
    public function isHealthy(): bool
    {
        return $this->transport->isHealthy();
    }

    /**
     * Set global from address for all messages.
     *
     * @param string $address Email address.
     * @param string $name Sender name.
     * @return $this
     */
    public function alwaysFrom(string $address, string $name = ''): self
    {
        $this->alwaysFromAddress = ['address' => $address, 'name' => $name];
        return $this;
    }

    /**
     * Set global reply-to address for all messages.
     *
     * @param string $address Email address.
     * @param string $name Reply-to name.
     * @return $this
     */
    public function alwaysReplyTo(string $address, string $name = ''): self
    {
        $this->alwaysReplyToAddress = ['address' => $address, 'name' => $name];
        return $this;
    }

    /**
     * Get global from address.
     *
     * @return array{address: string, name: string}|null
     */
    public function getAlwaysFrom(): ?array
    {
        return $this->alwaysFromAddress;
    }

    /**
     * Get global reply-to address.
     *
     * @return array{address: string, name: string}|null
     */
    public function getAlwaysReplyTo(): ?array
    {
        return $this->alwaysReplyToAddress;
    }

    /**
     * Prepare message with global configuration.
     *
     * @param MessageInterface $message Original message.
     * @return MessageInterface
     */
    private function prepareMessage(MessageInterface $message): MessageInterface
    {
        if (!($message instanceof Message)) {
            return $message;
        }

        // If message doesn't have from, use global from
        if (empty($message->getFrom())) {
            if ($this->alwaysFromAddress !== null) {
                $message->from(
                    $this->alwaysFromAddress['address'],
                    $this->alwaysFromAddress['name'] ?: null
                );
            } elseif (isset($this->config['from'])) {
                $message->from(
                    $this->config['from']['address'] ?? $this->config['from'],
                    $this->config['from']['name'] ?? null
                );
            }
        }

        // Add global reply-to if not set
        if (empty($message->getReplyTo())) {
            if ($this->alwaysReplyToAddress !== null) {
                $message->replyTo($this->alwaysReplyToAddress['address']);
            } elseif (isset($this->config['reply_to'])) {
                $message->replyTo($this->config['reply_to']);
            }
        }

        return $message;
    }

    /**
     * Fire before send callbacks.
     *
     * @param MessageInterface $message Message.
     */
    private function fireBeforeSend(MessageInterface $message): void
    {
        foreach ($this->beforeSendCallbacks as $callback) {
            $callback($message);
        }
    }

    /**
     * Fire after send callbacks.
     *
     * @param MessageInterface $message Message.
     * @param TransportResult $result Send result.
     */
    private function fireAfterSend(MessageInterface $message, TransportResult $result): void
    {
        foreach ($this->afterSendCallbacks as $callback) {
            $callback($message, $result);
        }
    }
}
