<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\EmailVerification\Contracts;

/**
 * Interface EmailVerificationBrokerInterface
 *
 * Contract for email verification broker implementations.
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
interface EmailVerificationBrokerInterface
{
    /**
     * Send verification link to user.
     *
     * @param VerifiableInterface $user
     * @return string Status constant
     */
    public function sendVerificationLink(VerifiableInterface $user): string;

    /**
     * Verify user's email.
     *
     * @param VerifiableInterface $user
     * @param string $hash
     * @return string Status constant
     */
    public function verify(VerifiableInterface $user, string $hash): string;

    /**
     * Create verification URL for user.
     *
     * @param VerifiableInterface $user
     * @return string
     */
    public function createVerificationUrl(VerifiableInterface $user): string;
}
