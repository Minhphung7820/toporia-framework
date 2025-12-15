<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Grants;

use Toporia\Framework\Auth\OAuth2\Contracts\ClientInterface;
use Toporia\Framework\Http\Request;

/**
 * Class AuthorizationCodeGrant
 *
 * OAuth2 Authorization Code Grant - The most secure grant type for web applications.
 * Supports PKCE (Proof Key for Code Exchange) for enhanced security.
 *
 * Flow:
 * 1. Client redirects user to authorization endpoint with client_id, redirect_uri, scope, state
 * 2. User authenticates and approves scopes
 * 3. Server redirects back with authorization code
 * 4. Client exchanges code for access token (this grant handles step 4)
 *
 * PKCE Flow (RFC 7636):
 * - Client generates code_verifier (random 43-128 char string)
 * - Client generates code_challenge = BASE64URL(SHA256(code_verifier))
 * - Authorization request includes code_challenge and code_challenge_method=S256
 * - Token request includes code_verifier
 * - Server validates: BASE64URL(SHA256(code_verifier)) == code_challenge
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\OAuth2\Grants
 * @since       2025-01-15
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class AuthorizationCodeGrant extends AbstractGrant
{
    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'authorization_code';
    }

    /**
     * Issue access token from authorization code.
     *
     * {@inheritdoc}
     */
    public function issueToken(Request $request, ClientInterface $client): array
    {
        // Validate required parameters
        $this->validateRequiredParameters($request, ['code', 'redirect_uri']);

        $code = $request->input('code');
        $redirectUri = $request->input('redirect_uri');
        $codeVerifier = $request->input('code_verifier'); // PKCE

        // Validate authorization code
        $codeData = $this->tokenRepository->validateAuthorizationCode(
            $code,
            $client->getId(),
            $redirectUri,
            $codeVerifier
        );

        if ($codeData === null) {
            throw new \RuntimeException('Invalid authorization code');
        }

        // Extract user ID and scopes from code
        $userId = $codeData['user_id'];
        $scopes = $codeData['scopes'];

        // Get token expiration
        $expiresIn = $this->getExpiresIn($request, 3600); // 1 hour default

        // Create access token
        $accessToken = $this->tokenRepository->createAccessToken(
            $client->getId(),
            $userId,
            $scopes,
            $expiresIn
        );

        // Create refresh token
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
     * Generate authorization code (called during authorization step).
     *
     * This method should be called by the authorization endpoint, not during token exchange.
     *
     * @param ClientInterface $client OAuth2 client
     * @param string $userId User ID
     * @param string $redirectUri Redirect URI
     * @param array<string> $scopes Requested scopes
     * @param string|null $codeChallenge PKCE code challenge
     * @param string|null $codeChallengeMethod PKCE method ('plain' or 'S256')
     * @return string Authorization code
     */
    public function generateAuthorizationCode(
        ClientInterface $client,
        string $userId,
        string $redirectUri,
        array $scopes,
        ?string $codeChallenge = null,
        ?string $codeChallengeMethod = null
    ): string {
        // Validate redirect URI
        if (!$this->isValidRedirectUri($client, $redirectUri)) {
            throw new \RuntimeException('Invalid redirect URI');
        }

        // Validate PKCE parameters
        if ($codeChallenge !== null) {
            $this->validatePkceChallenge($codeChallenge, $codeChallengeMethod);
        }

        // Validate scopes
        $scopes = $this->validateScopes($scopes, $client);

        // Create authorization code (expires in 10 minutes)
        return $this->tokenRepository->createAuthorizationCode(
            $client->getId(),
            $userId,
            $redirectUri,
            $scopes,
            $codeChallenge,
            $codeChallengeMethod,
            600 // 10 minutes
        );
    }

    /**
     * Validate redirect URI against client's registered URIs.
     *
     * @param ClientInterface $client OAuth2 client
     * @param string $redirectUri Redirect URI to validate
     * @return bool True if valid
     */
    private function isValidRedirectUri(ClientInterface $client, string $redirectUri): bool
    {
        $registeredUri = $client->getRedirectUri();

        if (empty($registeredUri)) {
            return false;
        }

        // Exact match required for security
        return $registeredUri === $redirectUri;
    }

    /**
     * Validate PKCE code challenge.
     *
     * @param string $codeChallenge Code challenge
     * @param string|null $codeChallengeMethod Challenge method
     * @return void
     * @throws \InvalidArgumentException If validation fails
     */
    private function validatePkceChallenge(string $codeChallenge, ?string $codeChallengeMethod): void
    {
        // Validate challenge method
        if ($codeChallengeMethod !== null && !in_array($codeChallengeMethod, ['plain', 'S256'], true)) {
            throw new \InvalidArgumentException('Invalid code_challenge_method. Must be "plain" or "S256"');
        }

        // Validate challenge length (43-128 characters per RFC 7636)
        $challengeLength = strlen($codeChallenge);
        if ($challengeLength < 43 || $challengeLength > 128) {
            throw new \InvalidArgumentException('Invalid code_challenge length. Must be 43-128 characters');
        }

        // Validate challenge format (base64url characters only)
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $codeChallenge)) {
            throw new \InvalidArgumentException('Invalid code_challenge format. Must be base64url encoded');
        }
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
