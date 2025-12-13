<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Class Message
 *
 * Fluent builder for constructing email messages following the Builder pattern.
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
final class Message implements MessageInterface
{
    private string $from = '';
    private ?string $fromName = null;
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];
    private ?string $replyTo = null;
    private string $subject = '';
    private string $body = '';
    private ?string $textBody = null;
    private array $attachments = [];
    private array $headers = [];

    /**
     * Set sender.
     *
     * @param string $email Sender email address.
     * @param string|null $name Sender name.
     * @return self
     */
    public function from(string $email, ?string $name = null): self
    {
        $this->from = $email;
        $this->fromName = $name;
        return $this;
    }

    /**
     * Add recipient.
     *
     * @param string $email Recipient email address.
     * @return self
     */
    public function to(string $email): self
    {
        $this->to[] = $email;
        return $this;
    }

    /**
     * Add CC recipient.
     *
     * @param string $email CC email address.
     * @return self
     */
    public function cc(string $email): self
    {
        $this->cc[] = $email;
        return $this;
    }

    /**
     * Add BCC recipient.
     *
     * @param string $email BCC email address.
     * @return self
     */
    public function bcc(string $email): self
    {
        $this->bcc[] = $email;
        return $this;
    }

    /**
     * Set reply-to address.
     *
     * @param string $email Reply-to email address.
     * @return self
     */
    public function replyTo(string $email): self
    {
        $this->replyTo = $email;
        return $this;
    }

    /**
     * Set email subject.
     *
     * @param string $subject Email subject.
     * @return self
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set HTML body.
     *
     * @param string $body HTML content.
     * @return self
     */
    public function html(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Set plain text body.
     *
     * @param string $text Plain text content.
     * @return self
     */
    public function text(string $text): self
    {
        $this->textBody = $text;
        return $this;
    }

    /**
     * Add attachment.
     *
     * @param string $path File path.
     * @param string|null $name Custom filename.
     * @param string|null $mime MIME type.
     * @return self
     */
    public function attach(string $path, ?string $name = null, ?string $mime = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $name,
            'mime' => $mime,
        ];
        return $this;
    }

    /**
     * Add custom header.
     *
     * @param string $name Header name.
     * @param string $value Header value.
     * @return self
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    // Getters (MessageInterface implementation)

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function getTo(): array
    {
        return $this->to;
    }

    public function getCc(): array
    {
        return $this->cc;
    }

    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getTextBody(): ?string
    {
        return $this->textBody;
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
