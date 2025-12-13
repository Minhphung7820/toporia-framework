<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Mail\Contracts\{MailManagerInterface, MailerInterface, MessageInterface};
use Toporia\Framework\Mail\{Mailable, PendingMail};

/**
 * Class Mail
 *
 * Mail Accessor - Provides static-like access to the mail system.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * Supported drivers:
 * - smtp: SMTP server (with TLS/STARTTLS)
 * - mailgun: Mailgun API
 * - ses: Amazon SES
 * - postmark: Postmark API
 * - resend: Resend API
 * - sendgrid: SendGrid API
 * - log: Log to file (development)
 * - array: In-memory (testing)
 *
 * @method static MailerInterface driver(?string $driver = null) Get mail driver
 * @method static bool send(MessageInterface $message) Send email
 * @method static bool sendMailable(Mailable $mailable) Send mailable
 * @method static bool queue(MessageInterface $message, int $delay = 0) Queue email
 * @method static bool queueMailable(Mailable $mailable, int $delay = 0) Queue mailable
 * @method static PendingMail to(string|array $recipients) Begin composing mail to recipient(s)
 * @method static PendingMail cc(string|array $recipients) Begin composing mail with CC
 * @method static PendingMail bcc(string|array $recipients) Begin composing mail with BCC
 * @method static self extend(string $transport, callable $callback) Register custom transport
 * @method static array getAvailableMailers() Get all configured mailer names
 * @method static array getFromAddress() Get global from address
 * @method static self purge(?string $driver = null) Clear cached driver(s)
 *
 * @example
 * // Send email to recipients
 * Mail::to('user@example.com')->send(new WelcomeMail($user));
 *
 * // Send with CC and BCC
 * Mail::to('user@example.com')
 *     ->cc('manager@example.com')
 *     ->bcc('admin@example.com')
 *     ->send(new OrderConfirmation($order));
 *
 * // Queue email for later sending
 * Mail::to('user@example.com')->queue(new Newsletter($content));
 *
 * // Use specific driver
 * Mail::driver('mailgun')->send($message);
 */
final class Mail extends ServiceAccessor
{
    /**
     * Get the service name for this accessor.
     *
     * This is the only method needed - all other methods are automatically
     * delegated to the underlying service via __callStatic().
     *
     * @return string Service name in container
     */
    protected static function getServiceName(): string
    {
        return MailManagerInterface::class;
    }
}
