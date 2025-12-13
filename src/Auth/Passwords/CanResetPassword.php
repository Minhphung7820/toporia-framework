<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Passwords;

/**
 * Trait CanResetPassword
 *
 * Add this trait to your User model to enable password reset.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Passwords
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait CanResetPassword
{
    /**
     * Get the email address where password reset links are sent.
     *
     * @return string
     */
    public function getEmailForPasswordReset(): string
    {
        return $this->{$this->getEmailColumn()};
    }

    /**
     * Send the password reset notification.
     *
     * @param string $token
     * @return void
     */
    public function sendPasswordResetNotification(string $token): void
    {
        // Override this method to send custom notification
        // Default: do nothing, let the broker handle it
    }

    /**
     * Get the email column name.
     *
     * @return string
     */
    protected function getEmailColumn(): string
    {
        return $this->emailColumn ?? 'email';
    }
}
