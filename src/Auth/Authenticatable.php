<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth;


/**
 * Interface Authenticatable
 *
 * Contract defining the interface for Authenticatable implementations in
 * the Authentication and authorization layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface Authenticatable
{
    /**
     * Get the unique identifier for the user.
     *
     * @return int|string User ID.
     */
    public function getAuthIdentifier(): int|string;

    /**
     * Get the name of the unique identifier (e.g., 'id', 'user_id').
     *
     * @return string Identifier name.
     */
    public function getAuthIdentifierName(): string;

    /**
     * Get the password for the user (hashed).
     *
     * @return string Hashed password.
     */
    public function getAuthPassword(): string;

    /**
     * Get the remember token (for "remember me" functionality).
     *
     * @return string|null Remember token.
     */
    public function getRememberToken(): ?string;

    /**
     * Set the remember token.
     *
     * @param string $token Remember token.
     * @return void
     */
    public function setRememberToken(string $token): void;
}
