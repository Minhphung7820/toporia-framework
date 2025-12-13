<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\EmailVerification;

use Toporia\Framework\Auth\EmailVerification\Contracts\EmailVerificationBrokerInterface;
use Toporia\Framework\Auth\EmailVerification\Contracts\VerifiableInterface;
use Toporia\Framework\Mail\Contracts\MailerInterface;
use Toporia\Framework\Mail\Message;

/**
 * Class EmailVerificationBroker
 *
 * Handles email verification similar to Toporia's built-in verification.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\EmailVerification
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class EmailVerificationBroker implements EmailVerificationBrokerInterface
{
    /**
     * Verification successful.
     */
    public const VERIFIED = 'verified';

    /**
     * Invalid hash.
     */
    public const INVALID_HASH = 'invalid_hash';

    /**
     * Link expired.
     */
    public const EXPIRED = 'expired';

    /**
     * Already verified.
     */
    public const ALREADY_VERIFIED = 'already_verified';

    /**
     * Link sent.
     */
    public const LINK_SENT = 'link_sent';

    /**
     * Throttled.
     */
    public const THROTTLED = 'throttled';

    /**
     * The application key for HMAC.
     *
     * @var string
     */
    protected string $key;

    /**
     * Token expiration in minutes.
     *
     * @var int
     */
    protected int $expiration = 60;

    /**
     * Throttle limit in seconds.
     *
     * @var int
     */
    protected int $throttle = 60;

    /**
     * Last sent timestamps for throttling.
     *
     * @var array<string, int>
     */
    protected array $lastSent = [];

    /**
     * The mailer instance.
     *
     * @var MailerInterface|null
     */
    protected ?MailerInterface $mailer = null;

    /**
     * Callback for creating verification URL.
     *
     * @var callable|null
     */
    protected $urlCallback = null;

    /**
     * Create a new email verification broker.
     *
     * @param string $key Application key for HMAC
     * @param int $expiration Token expiration in minutes
     */
    public function __construct(string $key, int $expiration = 60)
    {
        $this->key = $key;
        $this->expiration = $expiration;
    }

    /**
     * Set the mailer instance.
     *
     * @param MailerInterface $mailer
     * @return static
     */
    public function setMailer(MailerInterface $mailer): static
    {
        $this->mailer = $mailer;

        return $this;
    }

    /**
     * Set the URL callback.
     *
     * @param callable $callback
     * @return static
     */
    public function setUrlCallback(callable $callback): static
    {
        $this->urlCallback = $callback;

        return $this;
    }

    /**
     * Send verification link to user.
     *
     * @param VerifiableInterface $user
     * @return string Status constant
     */
    public function sendVerificationLink(VerifiableInterface $user): string
    {
        if ($user->hasVerifiedEmail()) {
            return self::ALREADY_VERIFIED;
        }

        // Check throttle
        $email = $user->getEmailForVerification();
        if (isset($this->lastSent[$email])) {
            $elapsed = now()->getTimestamp() - $this->lastSent[$email];
            if ($elapsed < $this->throttle) {
                return self::THROTTLED;
            }
        }

        $url = $this->createVerificationUrl($user);

        $this->sendEmail($user, $url);

        $this->lastSent[$email] = now()->getTimestamp();

        return self::LINK_SENT;
    }

    /**
     * Verify user's email.
     *
     * @param VerifiableInterface $user
     * @param string $hash The hash from verification URL
     * @return string Status constant
     */
    public function verify(VerifiableInterface $user, string $hash): string
    {
        if ($user->hasVerifiedEmail()) {
            return self::ALREADY_VERIFIED;
        }

        if (!$this->verifyHash($user, $hash)) {
            return self::INVALID_HASH;
        }

        $user->markEmailAsVerified();

        return self::VERIFIED;
    }

    /**
     * Verify hash with expiration check.
     *
     * @param VerifiableInterface $user
     * @param string $hash
     * @return bool
     */
    public function verifyHash(VerifiableInterface $user, string $hash): bool
    {
        // Hash format: base64(timestamp:signature)
        $decoded = base64_decode($hash, true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return false;
        }

        [$timestamp, $signature] = explode(':', $decoded, 2);

        // Check expiration
        $timestamp = (int) $timestamp;
        $expirationSeconds = $this->expiration * 60;

        if (now()->getTimestamp() - $timestamp > $expirationSeconds) {
            return false;
        }

        // Verify signature
        $expectedSignature = $this->createSignature($user, $timestamp);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Create verification URL for user.
     *
     * @param VerifiableInterface $user
     * @return string
     */
    public function createVerificationUrl(VerifiableInterface $user): string
    {
        $timestamp = now()->getTimestamp();
        $signature = $this->createSignature($user, $timestamp);
        $hash = base64_encode($timestamp . ':' . $signature);

        if ($this->urlCallback !== null) {
            return ($this->urlCallback)($user, $hash);
        }

        // Default URL format
        $id = $user->getKey();
        $email = urlencode($user->getEmailForVerification());

        return "/email/verify/{$id}/{$hash}?email={$email}";
    }

    /**
     * Create signature for user.
     *
     * @param VerifiableInterface $user
     * @param int $timestamp
     * @return string
     */
    protected function createSignature(VerifiableInterface $user, int $timestamp): string
    {
        $data = implode('|', [
            $user->getKey(),
            $user->getEmailForVerification(),
            $timestamp,
        ]);

        return hash_hmac('sha256', $data, $this->key);
    }

    /**
     * Send verification email.
     *
     * @param VerifiableInterface $user
     * @param string $url
     * @return void
     */
    protected function sendEmail(VerifiableInterface $user, string $url): void
    {
        if ($this->mailer === null) {
            return;
        }

        $message = (new Message())
            ->to($user->getEmailForVerification())
            ->subject('Verify Email Address')
            ->html($this->buildEmailContent($user, $url));

        $this->mailer->send($message);
    }

    /**
     * Build email content.
     *
     * @param VerifiableInterface $user
     * @param string $url
     * @return string
     */
    protected function buildEmailContent(VerifiableInterface $user, string $url): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #2563eb;">Verify Email Address</h1>
        <p>Please click the button below to verify your email address.</p>
        <p style="margin: 30px 0;">
            <a href="{$url}"
               style="background-color: #2563eb; color: white; padding: 12px 24px;
                      text-decoration: none; border-radius: 5px; display: inline-block;">
                Verify Email Address
            </a>
        </p>
        <p>If you did not create an account, no further action is required.</p>
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;">
        <p style="color: #6b7280; font-size: 14px;">
            If you're having trouble clicking the button, copy and paste the URL below into your web browser:
            <br>
            <a href="{$url}" style="color: #2563eb;">{$url}</a>
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Set token expiration.
     *
     * @param int $minutes
     * @return static
     */
    public function setExpiration(int $minutes): static
    {
        $this->expiration = $minutes;

        return $this;
    }

    /**
     * Set throttle limit.
     *
     * @param int $seconds
     * @return static
     */
    public function setThrottle(int $seconds): static
    {
        $this->throttle = $seconds;

        return $this;
    }

    /**
     * Get expiration time in minutes.
     *
     * @return int
     */
    public function getExpiration(): int
    {
        return $this->expiration;
    }
}
