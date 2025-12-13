<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail;

use Toporia\Framework\Mail\Contracts\MailerInterface;
use Toporia\Framework\Mail\Jobs\SendMailJob;
use PHPMailer\PHPMailer\{Exception, PHPMailer};
use Toporia\Framework\Queue\Contracts\QueueInterface;

/**
 * Class SmtpMailer
 *
 * Production-ready SMTP mailer with performance optimizations including lazy PHPMailer instantiation,
 * configuration caching, connection reuse for batch sending, and minimal object creation overhead.
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
final class SmtpMailer implements MailerInterface
{
    /**
     * @var PHPMailer|null Cached PHPMailer instance for connection reuse
     */
    private ?PHPMailer $mailer = null;

    /**
     * @var array Cached configuration for performance
     */
    private array $cachedConfig;

    /**
     * @param array $config SMTP configuration
     * @param QueueInterface|null $queue Queue for async sending
     */
    public function __construct(
        array $config,
        private ?QueueInterface $queue = null
    ) {
        // Cache config to avoid repeated array access (performance)
        $this->cachedConfig = [
            'host' => $config['host'] ?? 'smtp.gmail.com',
            'port' => (int) ($config['port'] ?? 587),
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
            'encryption' => $config['encryption'] ?? 'tls',
            'timeout' => (int) ($config['timeout'] ?? 30),
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Performance: O(1) for configuration, O(N) for recipients
     */
    public function send(MessageInterface $message): bool
    {
        $mail = $this->getMailer();

        try {
            // Clear previous recipients (for connection reuse)
            $mail->clearAddresses();
            $mail->clearCCs();
            $mail->clearBCCs();
            $mail->clearReplyTos();
            $mail->clearAttachments();
            $mail->clearCustomHeaders();

            // Set sender (cached from message)
            $mail->setFrom(
                $message->getFrom(),
                $message->getFromName() ?? ''
            );

            // Add recipients (O(N) where N = number of recipients)
            foreach ($message->getTo() as $to) {
                $mail->addAddress($to);
            }

            // Add CC recipients
            foreach ($message->getCc() as $cc) {
                $mail->addCC($cc);
            }

            // Add BCC recipients
            foreach ($message->getBcc() as $bcc) {
                $mail->addBCC($bcc);
            }

            // Reply-To
            if ($replyTo = $message->getReplyTo()) {
                $mail->addReplyTo($replyTo);
            }

            // Content configuration
            $mail->isHTML(true);
            $mail->Subject = $message->getSubject();
            $mail->Body = $message->getBody();
            $mail->AltBody = $message->getTextBody() ?? strip_tags($message->getBody());

            // Attachments (O(A) where A = number of attachments)
            foreach ($message->getAttachments() as $attachment) {
                $mail->addAttachment(
                    $attachment['path'],
                    $attachment['name'] ?? ''
                );
            }

            // Custom headers
            foreach ($message->getHeaders() as $name => $value) {
                $mail->addCustomHeader($name, $value);
            }

            // Send email (network I/O)
            $mail->send();

            return true;
        } catch (Exception $e) {
            error_log("SMTP send failed: {$mail->ErrorInfo}");
            throw new \RuntimeException(
                "Failed to send email: {$mail->ErrorInfo}",
                0,
                $e
            );
        }
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
        if (!$this->queue) {
            throw new \RuntimeException('Queue not configured for mailer');
        }

        $job = new SendMailJob($message);

        if ($delay > 0) {
            $this->queue->later($job, $delay);
        } else {
            $this->queue->push($job, 'emails');
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function queueMailable(Mailable $mailable, int $delay = 0): bool
    {
        $message = $mailable->build();
        return $this->queue($message, $delay);
    }

    /**
     * Get or create PHPMailer instance (lazy loading).
     *
     * Performance: O(1) after first call (cached instance)
     * Connection reuse: Same SMTP connection for multiple emails
     *
     * @return PHPMailer
     */
    private function getMailer(): PHPMailer
    {
        if ($this->mailer !== null) {
            return $this->mailer; // Reuse existing instance (performance)
        }

        $mail = new PHPMailer(true);

        // SMTP configuration (using cached config for performance)
        $mail->isSMTP();
        $mail->Host = $this->cachedConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->cachedConfig['username'];
        $mail->Password = $this->cachedConfig['password'];
        $mail->SMTPSecure = $this->cachedConfig['encryption'] === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $this->cachedConfig['port'];
        $mail->Timeout = $this->cachedConfig['timeout'];

        // Performance: Keep connection alive for batch sending
        $mail->SMTPKeepAlive = true;

        // Cache instance for reuse
        $this->mailer = $mail;

        return $this->mailer;
    }

    /**
     * Close SMTP connection.
     *
     * Call this after batch sending to close connection.
     *
     * @return void
     */
    public function closeConnection(): void
    {
        if ($this->mailer !== null) {
            $this->mailer->smtpClose();
            $this->mailer = null;
        }
    }

    /**
     * Destructor - ensure connection is closed.
     */
    public function __destruct()
    {
        $this->closeConnection();
    }
}
