<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Repositories;

use Toporia\Framework\Auth\OAuth2\Contracts\TokenRepositoryInterface;
use Toporia\Framework\Auth\OAuth2\Models\{OAuth2AccessToken, OAuth2AuthorizationCode, OAuth2RefreshToken};
use Toporia\Framework\Hashing\HashManager;

/**
 * Class TokenRepository
 *
 * Manages OAuth2 access and refresh tokens.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\OAuth2\Repositories
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class TokenRepository implements TokenRepositoryInterface
{
    /**
     * Default access token expiration (1 hour).
     */
    private const DEFAULT_ACCESS_TOKEN_EXPIRES_IN = 3600;

    /**
     * Default refresh token expiration (30 days).
     */
    private const DEFAULT_REFRESH_TOKEN_EXPIRES_IN = 2592000;

    /**
     * {@inheritdoc}
     */
    public function createAccessToken(string $clientId, ?string $userId, array $scopes, int $expiresIn): string
    {
        $expiresIn = $expiresIn > 0 ? $expiresIn : self::DEFAULT_ACCESS_TOKEN_EXPIRES_IN;
        $expiresAt = now()->getTimestamp() + $expiresIn;

        // Generate JWT token (simplified - in production use proper JWT library)
        $token = $this->generateJwtToken($clientId, $userId, $scopes, $expiresAt);

        // Store token in database for revocation tracking
        OAuth2AccessToken::create([
            'token' => $this->hashToken($token),
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => $scopes,
            'expires_at' => now()->setTimestamp($expiresAt)->toDateTimeString(),
        ]);

        return $token;
    }

    /**
     * {@inheritdoc}
     */
    public function createRefreshToken(string $clientId, string $userId, array $scopes, int $expiresIn): string
    {
        $expiresIn = $expiresIn > 0 ? $expiresIn : self::DEFAULT_REFRESH_TOKEN_EXPIRES_IN;
        $expiresAt = now()->getTimestamp() + $expiresIn;

        // Generate random token
        $token = bin2hex(random_bytes(32)); // 64 character hex string

        // Store token in database
        OAuth2RefreshToken::create([
            'token' => $this->hashToken($token),
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => $scopes,
            'expires_at' => now()->setTimestamp($expiresAt)->toDateTimeString(),
        ]);

        return $token;
    }

    /**
     * {@inheritdoc}
     */
    public function validateAccessToken(string $token): ?array
    {
        // Decode JWT token
        $payload = $this->decodeJwtToken($token);
        if ($payload === null) {
            return null;
        }

        // Check if token is revoked in database
        $hashedToken = $this->hashToken($token);
        $tokenModel = OAuth2AccessToken::where('token', $hashedToken)->first();

        if ($tokenModel === null || $tokenModel->isRevoked() || $tokenModel->isExpired()) {
            return null;
        }

        return [
            'client_id' => $payload['client_id'],
            'user_id' => $payload['user_id'] ?? null,
            'scopes' => $payload['scopes'] ?? [],
            'expires_at' => $payload['exp'] ?? 0,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function validateRefreshToken(string $token): ?array
    {
        $hashedToken = $this->hashToken($token);
        $tokenModel = OAuth2RefreshToken::where('token', $hashedToken)->first();

        if ($tokenModel === null || $tokenModel->isRevoked() || $tokenModel->isExpired()) {
            return null;
        }

        return [
            'client_id' => $tokenModel->getAttribute('client_id'),
            'user_id' => $tokenModel->getAttribute('user_id'),
            'scopes' => $tokenModel->getAttribute('scopes') ?? [],
            'expires_at' => is_string($tokenModel->getAttribute('expires_at'))
                ? \Toporia\Framework\DateTime\Chronos::parse($tokenModel->getAttribute('expires_at'))->getTimestamp()
                : $tokenModel->getAttribute('expires_at')->getTimestamp(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function revokeAccessToken(string $token): bool
    {
        $hashedToken = $this->hashToken($token);
        $tokenModel = OAuth2AccessToken::where('token', $hashedToken)->first();

        if ($tokenModel === null) {
            return false;
        }

        $tokenModel->update(['revoked_at' => now()->toDateTimeString()]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function revokeRefreshToken(string $token): bool
    {
        $hashedToken = $this->hashToken($token);
        $tokenModel = OAuth2RefreshToken::where('token', $hashedToken)->first();

        if ($tokenModel === null) {
            return false;
        }

        $tokenModel->update(['revoked_at' => now()->toDateTimeString()]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function revokeUserTokens(string $userId): int
    {
        $revoked = OAuth2AccessToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()->toDateTimeString()]);

        OAuth2RefreshToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()->toDateTimeString()]);

        return $revoked;
    }

    /**
     * {@inheritdoc}
     */
    public function revokeClientTokens(string $clientId): int
    {
        $revoked = OAuth2AccessToken::where('client_id', $clientId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()->toDateTimeString()]);

        OAuth2RefreshToken::where('client_id', $clientId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()->toDateTimeString()]);

        return $revoked;
    }

    /**
     * Generate a JWT token (simplified implementation).
     *
     * In production, use a proper JWT library like firebase/php-jwt.
     *
     * @param string $clientId Client ID
     * @param string|null $userId User ID
     * @param array<string> $scopes Token scopes
     * @param int $expiresAt Expiration timestamp
     * @return string JWT token
     */
    private function generateJwtToken(string $clientId, ?string $userId, array $scopes, int $expiresAt): string
    {
        $secret = $this->getJwtSecret();
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode([
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => $scopes,
            'exp' => $expiresAt,
            'iat' => now()->getTimestamp(),
        ]));

        $signature = hash_hmac('sha256', "{$header}.{$payload}", $secret, true);
        $signatureEncoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return "{$header}.{$payload}.{$signatureEncoded}";
    }

    /**
     * Decode and validate a JWT token.
     *
     * @param string $token JWT token
     * @return array<string, mixed>|null Decoded payload or null if invalid
     */
    private function decodeJwtToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Verify signature
        $secret = $this->getJwtSecret();
        $signature = base64_decode(str_replace(['-', '_'], ['+', '/'], $signatureEncoded), true);

        // SECURITY: Validate decoded signature
        if ($signature === false || strlen($signature) !== 32) {
            return null;
        }
        $expectedSignature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $secret, true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $payload = json_decode(base64_decode($payloadEncoded), true);
        if ($payload === null) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < now()->getTimestamp()) {
            return null;
        }

        return $payload;
    }

    /**
     * Hash a token for storage.
     *
     * @param string $token Plain text token
     * @return string Hashed token
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Get the JWT signing secret.
     *
     * SECURITY: Validates that APP_KEY is properly configured.
     * Throws exception if no valid key is found to prevent insecure operation.
     *
     * @return string Secret key
     * @throws \RuntimeException If APP_KEY is not configured properly
     */
    private function getJwtSecret(): string
    {
        $secret = $_ENV['APP_KEY']
            ?? (function_exists('env') ? env('APP_KEY') : null)
            ?? getenv('APP_KEY');

        if (!$secret || strlen($secret) < 32) {
            throw new \RuntimeException(
                'APP_KEY must be at least 32 characters long and set in environment. ' .
                'Run: php console key:generate'
            );
        }

        return $secret;
    }

    /**
     * {@inheritdoc}
     */
    public function createAuthorizationCode(
        string $clientId,
        string $userId,
        string $redirectUri,
        array $scopes,
        ?string $codeChallenge = null,
        ?string $codeChallengeMethod = null,
        int $expiresIn = 600
    ): string {
        // Generate secure random code
        $code = bin2hex(random_bytes(32)); // 64 character hex string
        $expiresAt = now()->getTimestamp() + $expiresIn;

        // Store authorization code
        OAuth2AuthorizationCode::create([
            'code' => $this->hashToken($code),
            'client_id' => $clientId,
            'user_id' => $userId,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'expires_at' => now()->setTimestamp($expiresAt)->toDateTimeString(),
        ]);

        return $code;
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthorizationCode(
        string $code,
        string $clientId,
        string $redirectUri,
        ?string $codeVerifier = null
    ): ?array {
        $hashedCode = $this->hashToken($code);
        $codeModel = OAuth2AuthorizationCode::where('code', $hashedCode)->first();

        // Validate code exists
        if ($codeModel === null) {
            return null;
        }

        // Check if already used (prevents replay attacks)
        if ($codeModel->isUsed()) {
            return null;
        }

        // Check if expired
        if ($codeModel->isExpired()) {
            return null;
        }

        // Validate client ID matches
        if ($codeModel->getAttribute('client_id') !== $clientId) {
            return null;
        }

        // Validate redirect URI matches (critical for security)
        if ($codeModel->getAttribute('redirect_uri') !== $redirectUri) {
            return null;
        }

        // Validate PKCE if required
        if ($codeModel->getAttribute('code_challenge') !== null) {
            if ($codeVerifier === null) {
                return null; // Code verifier required but not provided
            }

            if (!$codeModel->verifyCodeChallenge($codeVerifier)) {
                return null; // Invalid code verifier
            }
        }

        // Mark as used (single-use only)
        $codeModel->markAsUsed();

        return [
            'user_id' => $codeModel->getAttribute('user_id'),
            'scopes' => $codeModel->getAttribute('scopes') ?? [],
        ];
    }
}
