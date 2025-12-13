<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Grants;

use Toporia\Framework\Auth\OAuth2\Contracts\ClientInterface;
use Toporia\Framework\Http\Request;

/**
 * Class PasswordGrant
 *
 * OAuth2 grant type for trusted applications using username and password.
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
final class PasswordGrant extends AbstractGrant
{
    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'password';
    }

    /**
     * {@inheritdoc}
     */
    public function issueToken(Request $request, ClientInterface $client): array
    {
        // Validate required parameters
        $this->validateRequiredParameters($request, ['username', 'password']);

        $username = $request->input('username');
        $password = $request->input('password');

        // Authenticate user
        if ($this->userProvider === null) {
            throw new \RuntimeException('User provider is required for password grant');
        }

        $user = $this->userProvider->retrieveByCredentials([
            'email' => $username, // Support email as username
            'username' => $username,
        ]);

        if ($user === null || !$this->userProvider->validateCredentials($user, ['password' => $password])) {
            throw new \RuntimeException('Invalid user credentials');
        }

        $scopes = $this->getScopes($request, $client->getScopes());
        $expiresIn = $this->getExpiresIn($request, 3600); // 1 hour default

        // Validate requested scopes
        $scopes = $this->validateScopes($scopes, $client);

        // Create access token and refresh token
        $userId = (string) $user->getAuthIdentifier();
        $accessToken = $this->tokenRepository->createAccessToken(
            $client->getId(),
            $userId,
            $scopes,
            $expiresIn
        );

        $refreshToken = $this->tokenRepository->createRefreshToken(
            $client->getId(),
            $userId,
            $scopes,
            2592000 // 30 days default
        );

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'refresh_token' => $refreshToken,
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

        if (in_array('*', $allowedScopes, true)) {
            return $requestedScopes;
        }

        return array_filter($requestedScopes, function ($scope) use ($allowedScopes) {
            return in_array($scope, $allowedScopes, true);
        });
    }
}

