<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Passwords\Contracts;

/**
 * Interface CanResetPasswordInterface
 *
 * Contract for models that can reset their password.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Passwords\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface CanResetPasswordInterface
{
    /**
     * Get the email address where password reset links are sent.
     *
     * @return string
     */
    public function getEmailForPasswordReset(): string;

    /**
     * Send the password reset notification.
     *
     * @param string $token
     * @return void
     */
    public function sendPasswordResetNotification(string $token): void;
}
