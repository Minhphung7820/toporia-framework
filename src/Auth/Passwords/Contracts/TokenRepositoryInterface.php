<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Passwords\Contracts;

/**
 * Interface TokenRepositoryInterface
 *
 * Contract for password reset token repository implementations.
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
interface TokenRepositoryInterface
{
    /**
     * Create a new token record.
     *
     * @param CanResetPasswordInterface $user
     * @return string
     */
    public function create(CanResetPasswordInterface $user): string;

    /**
     * Determine if a token record exists and is valid.
     *
     * @param CanResetPasswordInterface $user
     * @param string $token
     * @return bool
     */
    public function exists(CanResetPasswordInterface $user, string $token): bool;

    /**
     * Determine if a token was recently created.
     *
     * @param CanResetPasswordInterface $user
     * @return bool
     */
    public function recentlyCreatedToken(CanResetPasswordInterface $user): bool;

    /**
     * Delete a token record.
     *
     * @param CanResetPasswordInterface $user
     * @return void
     */
    public function delete(CanResetPasswordInterface $user): void;

    /**
     * Delete expired tokens.
     *
     * @return void
     */
    public function deleteExpired(): void;
}
