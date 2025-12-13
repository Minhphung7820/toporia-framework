<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Contracts;

use Toporia\Framework\Support\Collection\Collection;


/**
 * Interface TokenRepositoryInterface
 *
 * Contract defining the interface for TokenRepositoryInterface
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
interface TokenRepositoryInterface
{
    /**
     * Create a new personal access token.
     *
     * @param int|string $tokenableId Owner ID
     * @param string $tokenableType Owner type (e.g., 'App\Domain\User\User')
     * @param string $name Token name
     * @param array<string> $abilities Token abilities/scopes
     * @param \DateTimeInterface|null $expiresAt Expiration time
     * @return NewAccessTokenInterface Newly created token
     */
    public function create(
        int|string $tokenableId,
        string $tokenableType,
        string $name,
        array $abilities = ['*'],
        ?\DateTimeInterface $expiresAt = null
    ): NewAccessTokenInterface;

    /**
     * Find a token by its hashed value.
     *
     * @param string $hashedToken Hashed token
     * @return PersonalAccessTokenInterface|null Token or null
     */
    public function findByHashedToken(string $hashedToken): ?PersonalAccessTokenInterface;

    /**
     * Find a token by its plain text value.
     *
     * @param string $plainTextToken Plain text token
     * @return PersonalAccessTokenInterface|null Token or null
     */
    public function findByPlainTextToken(string $plainTextToken): ?PersonalAccessTokenInterface;

    /**
     * Get all tokens for a given tokenable.
     *
     * @param int|string $tokenableId Owner ID
     * @param string $tokenableType Owner type
     * @return Collection<PersonalAccessTokenInterface> Tokens
     */
    public function getTokensFor(int|string $tokenableId, string $tokenableType): Collection;

    /**
     * Revoke a token.
     *
     * @param int|string $tokenId Token ID
     * @return bool True if revoked
     */
    public function revoke(int|string $tokenId): bool;

    /**
     * Revoke all tokens for a given tokenable.
     *
     * @param int|string $tokenableId Owner ID
     * @param string $tokenableType Owner type
     * @return int Number of tokens revoked
     */
    public function revokeAllFor(int|string $tokenableId, string $tokenableType): int;

    /**
     * Delete expired tokens.
     *
     * @return int Number of tokens deleted
     */
    public function deleteExpired(): int;

    /**
     * Update token's last used timestamp.
     *
     * @param int|string $tokenId Token ID
     * @return bool True if updated
     */
    public function touchLastUsedAt(int|string $tokenId): bool;
}
