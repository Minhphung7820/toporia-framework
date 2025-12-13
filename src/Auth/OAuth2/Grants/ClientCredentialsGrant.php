<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Grants;

use Toporia\Framework\Auth\OAuth2\Contracts\ClientInterface;
use Toporia\Framework\Http\Request;

/**
 * Class ClientCredentialsGrant
 *
 * OAuth2 grant type for machine-to-machine authentication.
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
final class ClientCredentialsGrant extends AbstractGrant
{
    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'client_credentials';
    }

    /**
     * {@inheritdoc}
     */
    public function issueToken(Request $request, ClientInterface $client): array
    {
        // Client credentials grant doesn't require additional parameters
        $scopes = $this->getScopes($request, $client->getScopes());
        $expiresIn = $this->getExpiresIn($request, 3600); // 1 hour default

        // Validate requested scopes against client's allowed scopes
        $scopes = $this->validateScopes($scopes, $client);

        // Create access token (no refresh token for client credentials)
        $accessToken = $this->tokenRepository->createAccessToken(
            $client->getId(),
            null, // No user for client credentials
            $scopes,
            $expiresIn
        );

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'scope' => implode(' ', $scopes),
        ];
    }

    /**
     * Validate requested scopes against client's allowed scopes.
     *
     * @param array<string> $requestedScopes Requested scopes
     * @param ClientInterface $client OAuth2 client
     * @return array<string> Validated scopes
     */
    private function validateScopes(array $requestedScopes, ClientInterface $client): array
    {
        $allowedScopes = $client->getScopes();

        // If client allows all scopes (*), return requested scopes
        if (in_array('*', $allowedScopes, true)) {
            return $requestedScopes;
        }

        // Filter to only allowed scopes
        return array_filter($requestedScopes, function ($scope) use ($allowedScopes) {
            return in_array($scope, $allowedScopes, true);
        });
    }
}

