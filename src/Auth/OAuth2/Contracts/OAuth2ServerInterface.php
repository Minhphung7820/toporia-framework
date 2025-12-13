<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Contracts;

use Toporia\Framework\Http\Request;


/**
 * Interface OAuth2ServerInterface
 *
 * Contract defining the interface for OAuth2ServerInterface
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
interface OAuth2ServerInterface
{
    /**
     * Issue an access token.
     *
     * @param Request $request HTTP request
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token?: string, scope?: string}
     * @throws \RuntimeException If token issuance fails
     */
    public function issueAccessToken(Request $request): array;

    /**
     * Validate an access token.
     *
     * @param string $token Access token
     * @return array{client_id: string, user_id?: string, scopes: array<string>}|null Token data or null if invalid
     */
    public function validateAccessToken(string $token): ?array;

    /**
     * Revoke an access token.
     *
     * @param string $token Access token
     * @return bool True if revoked successfully
     */
    public function revokeAccessToken(string $token): bool;

    /**
     * Revoke a refresh token.
     *
     * @param string $token Refresh token
     * @return bool True if revoked successfully
     */
    public function revokeRefreshToken(string $token): bool;
}
