<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Grants;

use Toporia\Framework\Auth\OAuth2\Contracts\ClientInterface;
use Toporia\Framework\Http\Request;

/**
 * Class RefreshTokenGrant
 *
 * OAuth2 grant type for refreshing access tokens.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\OAuth2\Grants
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RefreshTokenGrant extends AbstractGrant
{
    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'refresh_token';
    }

    /**
     * {@inheritdoc}
     */
    public function issueToken(Request $request, ClientInterface $client): array
    {
        // Validate required parameters
        $this->validateRequiredParameters($request, ['refresh_token']);

        $refreshToken = $request->input('refresh_token');

        // Validate refresh token
        $tokenData = $this->tokenRepository->validateRefreshToken($refreshToken);
        if ($tokenData === null) {
            throw new \RuntimeException('Invalid or expired refresh token');
        }

        // Verify token belongs to this client
        if ($tokenData['client_id'] !== $client->getId()) {
            throw new \RuntimeException('Refresh token does not belong to this client');
        }

        // Revoke old refresh token (one-time use)
        $this->tokenRepository->revokeRefreshToken($refreshToken);

        // Get requested scopes (can request different scopes, but must be subset of original)
        $requestedScopes = $this->getScopes($request, $tokenData['scopes']);
        $originalScopes = $tokenData['scopes'];

        // Validate requested scopes are subset of original scopes
        $scopes = $this->validateScopeSubset($requestedScopes, $originalScopes, $client);

        $expiresIn = $this->getExpiresIn($request, 3600); // 1 hour default

        // Create new access token and refresh token
        $accessToken = $this->tokenRepository->createAccessToken(
            $client->getId(),
            $tokenData['user_id'],
            $scopes,
            $expiresIn
        );

        $newRefreshToken = $this->tokenRepository->createRefreshToken(
            $client->getId(),
            $tokenData['user_id'],
            $scopes,
            2592000 // 30 days default
        );

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'refresh_token' => $newRefreshToken,
            'scope' => implode(' ', $scopes),
        ];
    }

    /**
     * Validate requested scopes are subset of original scopes.
     *
     * @param array<string> $requestedScopes Requested scopes
     * @param array<string> $originalScopes Original token scopes
     * @param ClientInterface $client OAuth2 client
     * @return array<string> Validated scopes
     */
    private function validateScopeSubset(array $requestedScopes, array $originalScopes, ClientInterface $client): array
    {
        // If no scopes requested, use original scopes
        if (empty($requestedScopes)) {
            return $originalScopes;
        }

        // Requested scopes must be subset of original scopes
        $validScopes = array_intersect($requestedScopes, $originalScopes);

        // Also validate against client's allowed scopes
        $allowedScopes = $client->getScopes();
        if (!in_array('*', $allowedScopes, true)) {
            $validScopes = array_intersect($validScopes, $allowedScopes);
        }

        return array_values($validScopes);
    }
}

