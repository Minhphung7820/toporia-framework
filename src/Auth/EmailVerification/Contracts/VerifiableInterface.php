<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\EmailVerification\Contracts;

/**
 * Interface VerifiableInterface
 *
 * Contract for models that can verify their email.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\EmailVerification\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface VerifiableInterface
{
    /**
     * Determine if the user has verified their email address.
     *
     * @return bool
     */
    public function hasVerifiedEmail(): bool;

    /**
     * Mark the given user's email as verified.
     *
     * @return bool
     */
    public function markEmailAsVerified(): bool;

    /**
     * Get the email address that should be used for verification.
     *
     * @return string
     */
    public function getEmailForVerification(): string;

    /**
     * Get the primary key for the model.
     *
     * @return int|string
     */
    public function getKey(): int|string;
}
