<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Contracts;


/**
 * Interface PersonalAccessTokenInterface
 *
 * Contract defining the interface for PersonalAccessTokenInterface
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
interface PersonalAccessTokenInterface
{
    /**
     * Find a token by the given plain text token.
     *
     * @param string $token Plain text token
     * @return self|null Token instance or null
     */
    public static function findToken(string $token): ?self;

    /**
     * Determine if the token has a given ability/scope.
     *
     * @param string $ability Ability to check
     * @return bool True if token has ability
     */
    public function can(string $ability): bool;

    /**
     * Determine if the token is missing a given ability/scope.
     *
     * @param string $ability Ability to check
     * @return bool True if token lacks ability
     */
    public function cant(string $ability): bool;

    /**
     * Get the token's abilities/scopes.
     *
     * @return array<string> Token abilities
     */
    public function getAbilities(): array;

    /**
     * Determine if the token has expired.
     *
     * @return bool True if expired
     */
    public function hasExpired(): bool;

    /**
     * Revoke the token (mark as revoked but don't delete).
     *
     * @return bool True if revoked successfully
     */
    public function revoke(): bool;

    /**
     * Update the token's last used timestamp.
     *
     * @return bool True if updated successfully
     */
    public function touchLastUsedAt(): bool;

    /**
     * Get the token's owner (user/model).
     *
     * @return mixed Token owner
     */
    public function getTokenable(): mixed;

    /**
     * Get the token's name/identifier.
     *
     * @return string Token name
     */
    public function getName(): string;

    /**
     * Get the token's ID.
     *
     * @return int|string Token ID
     */
    public function getId(): int|string;
}
