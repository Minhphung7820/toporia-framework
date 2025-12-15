<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Contracts;


/**
 * Interface TokenRepositoryInterface
 *
 * Contract defining the interface for TokenRepositoryInterface
 * implementations in the OAuth2 layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  OAuth2\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface TokenRepositoryInterface
{
    /**
     * Create a new access token.
     *
     * @param string $clientId Client ID
     * @param string|null $userId User ID (null for client credentials grant)
     * @param array<string> $scopes Token scopes
     * @param int $expiresIn Expiration time in seconds
     * @return string Access token (JWT or opaque)
     */
    public function createAccessToken(string $clientId, ?string $userId, array $scopes, int $expiresIn): string;

    /**
     * Create a new refresh token.
     *
     * @param string $clientId Client ID
     * @param string $userId User ID
     * @param array<string> $scopes Token scopes
     * @param int $expiresIn Expiration time in seconds
     * @return string Refresh token
     */
    public function createRefreshToken(string $clientId, string $userId, array $scopes, int $expiresIn): string;

    /**
     * Validate and get token data.
     *
     * @param string $token Access token
     * @return array{client_id: string, user_id?: string, scopes: array<string>, expires_at: int}|null Token data or null
     */
    public function validateAccessToken(string $token): ?array;

    /**
     * Validate and get refresh token data.
     *
     * @param string $token Refresh token
     * @return array{client_id: string, user_id: string, scopes: array<string>, expires_at: int}|null Token data or null
     */
    public function validateRefreshToken(string $token): ?array;

    /**
     * Revoke an access token.
     *
     * @param string $token Access token
     * @return bool True if revoked
     */
    public function revokeAccessToken(string $token): bool;

    /**
     * Revoke a refresh token.
     *
     * @param string $token Refresh token
     * @return bool True if revoked
     */
    public function revokeRefreshToken(string $token): bool;

    /**
     * Revoke all tokens for a user.
     *
     * @param string $userId User ID
     * @return int Number of tokens revoked
     */
    public function revokeUserTokens(string $userId): int;

    /**
     * Revoke all tokens for a client.
     *
     * @param string $clientId Client ID
     * @return int Number of tokens revoked
     */
    public function revokeClientTokens(string $clientId): int;

    /**
     * Create a new authorization code.
     *
     * @param string $clientId Client ID
     * @param string $userId User ID
     * @param string $redirectUri Redirect URI
     * @param array<string> $scopes Requested scopes
     * @param string|null $codeChallenge PKCE code challenge (optional)
     * @param string|null $codeChallengeMethod PKCE method ('plain' or 'S256')
     * @param int $expiresIn Expiration time in seconds (default: 600 = 10 minutes)
     * @return string Authorization code
     */
    public function createAuthorizationCode(
        string $clientId,
        string $userId,
        string $redirectUri,
        array $scopes,
        ?string $codeChallenge = null,
        ?string $codeChallengeMethod = null,
        int $expiresIn = 600
    ): string;

    /**
     * Validate and consume an authorization code.
     *
     * @param string $code Authorization code
     * @param string $clientId Client ID
     * @param string $redirectUri Redirect URI (must match original)
     * @param string|null $codeVerifier PKCE code verifier (required if PKCE was used)
     * @return array{user_id: string, scopes: array<string>}|null Code data or null if invalid
     */
    public function validateAuthorizationCode(
        string $code,
        string $clientId,
        string $redirectUri,
        ?string $codeVerifier = null
    ): ?array;
}
