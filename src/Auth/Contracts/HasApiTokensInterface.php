<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Contracts;

use Toporia\Framework\Support\Collection\Collection;


/**
 * Interface HasApiTokensInterface
 *
 * Contract defining the interface for HasApiTokensInterface
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
interface HasApiTokensInterface
{
    /**
     * Create a new personal access token for the user.
     *
     * @param string $name Token name/identifier
     * @param array<string> $abilities Token abilities/scopes
     * @param \DateTimeInterface|null $expiresAt Token expiration
     * @return NewAccessTokenInterface Newly created token
     */
    public function createToken(
        string $name,
        array $abilities = ['*'],
        ?\DateTimeInterface $expiresAt = null
    ): NewAccessTokenInterface;

    /**
     * Get all tokens for the user.
     *
     * @return Collection<PersonalAccessTokenInterface> User's tokens
     */
    public function tokens(): Collection;

    /**
     * Get the current access token being used.
     *
     * @return PersonalAccessTokenInterface|null Current token or null
     */
    public function currentAccessToken(): ?PersonalAccessTokenInterface;

    /**
     * Set the current access token for the user.
     *
     * @param PersonalAccessTokenInterface $token Token to set as current
     * @return self
     */
    public function withAccessToken(PersonalAccessTokenInterface $token): self;

    /**
     * Determine if the current API token has a given ability/scope.
     *
     * @param string $ability Ability to check
     * @return bool True if token has ability
     */
    public function tokenCan(string $ability): bool;

    /**
     * Determine if the current API token is missing a given ability.
     *
     * @param string $ability Ability to check
     * @return bool True if token lacks ability
     */
    public function tokenCant(string $ability): bool;
}
