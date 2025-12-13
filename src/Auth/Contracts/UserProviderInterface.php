<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Contracts;

use Toporia\Framework\Auth\Authenticatable;


/**
 * Interface UserProviderInterface
 *
 * Contract defining the interface for UserProviderInterface
 * implementations in the Authentication and authorization layer of the
 * Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface UserProviderInterface
{
    /**
     * Retrieve a user by their unique identifier.
     *
     * @param int|string $identifier User ID.
     * @return Authenticatable|null User or null if not found.
     */
    public function retrieveById(int|string $identifier): ?Authenticatable;

    /**
     * Retrieve a user by their credentials (e.g., email/username).
     *
     * @param array<string, mixed> $credentials Credentials array.
     * @return Authenticatable|null User or null if not found.
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable;

    /**
     * Validate a user against credentials.
     *
     * @param Authenticatable $user User to validate.
     * @param array<string, mixed> $credentials Credentials to check.
     * @return bool True if credentials match.
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool;

    /**
     * Retrieve a user by their remember token.
     *
     * @param int|string $identifier User ID.
     * @param string $token Remember token.
     * @return Authenticatable|null User or null if not found.
     */
    public function retrieveByToken(int|string $identifier, string $token): ?Authenticatable;

    /**
     * Update the remember token for a user.
     *
     * @param Authenticatable $user User to update.
     * @param string $token New remember token.
     * @return void
     */
    public function updateRememberToken(Authenticatable $user, string $token): void;
}
