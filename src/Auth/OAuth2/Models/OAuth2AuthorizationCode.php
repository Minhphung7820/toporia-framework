<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Models;

use Toporia\Framework\Database\ORM\Model;

/**
 * Class OAuth2AuthorizationCode
 *
 * Represents an OAuth2 authorization code (temporary, single-use).
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\OAuth2\Models
 * @since       2025-01-15
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class OAuth2AuthorizationCode extends Model
{
    protected static string $table = 'oauth_authorization_codes';
    protected static bool $timestamps = true;

    protected static array $fillable = [
        'code',
        'client_id',
        'user_id',
        'redirect_uri',
        'scopes',
        'code_challenge',
        'code_challenge_method',
        'expires_at',
        'used_at',
    ];

    protected static array $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * Check if the authorization code has expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        $expiresAt = $this->getAttribute('expires_at');

        if ($expiresAt === null) {
            return true;
        }

        // Handle both DateTime and string
        if (is_string($expiresAt)) {
            $expiresAt = \Toporia\Framework\DateTime\Chronos::parse($expiresAt);
        }

        return $expiresAt->getTimestamp() < now()->getTimestamp();
    }

    /**
     * Check if the authorization code has been used.
     *
     * @return bool
     */
    public function isUsed(): bool
    {
        return $this->getAttribute('used_at') !== null;
    }

    /**
     * Mark the authorization code as used.
     *
     * @return void
     */
    public function markAsUsed(): void
    {
        $this->update(['used_at' => now()->toDateTimeString()]);
    }

    /**
     * Verify the PKCE code verifier against the stored challenge.
     *
     * @param string $codeVerifier Plain text code verifier
     * @return bool True if valid
     */
    public function verifyCodeChallenge(string $codeVerifier): bool
    {
        $challenge = $this->getAttribute('code_challenge');
        $method = $this->getAttribute('code_challenge_method');

        if ($challenge === null) {
            // No PKCE required for this code
            return true;
        }

        if ($method === 'plain') {
            return hash_equals($challenge, $codeVerifier);
        }

        if ($method === 'S256') {
            $hashedVerifier = $this->hashCodeVerifier($codeVerifier);
            return hash_equals($challenge, $hashedVerifier);
        }

        return false;
    }

    /**
     * Hash code verifier using S256 method.
     *
     * @param string $codeVerifier Plain text verifier
     * @return string Base64 URL encoded hash
     */
    private function hashCodeVerifier(string $codeVerifier): string
    {
        $hash = hash('sha256', $codeVerifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }
}
