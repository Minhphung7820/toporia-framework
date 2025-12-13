<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Models;

use Toporia\Framework\Database\ORM\Model;

/**
 * Class OAuth2RefreshToken
 *
 * Represents an OAuth2 refresh token.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\OAuth2\Models
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class OAuth2RefreshToken extends Model
{
    protected static string $table = 'oauth_refresh_tokens';
    protected static bool $timestamps = true;

    protected static array $fillable = [
        'token',
        'client_id',
        'user_id',
        'scopes',
        'expires_at',
        'revoked_at',
    ];

    protected static array $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Check if token is revoked.
     *
     * @return bool True if revoked
     */
    public function isRevoked(): bool
    {
        return $this->getAttribute('revoked_at') !== null;
    }

    /**
     * Check if token is expired.
     *
     * @return bool True if expired
     */
    public function isExpired(): bool
    {
        $expiresAt = $this->getAttribute('expires_at');
        if ($expiresAt === null) {
            return false;
        }

        return is_string($expiresAt)
            ? \Toporia\Framework\DateTime\Chronos::parse($expiresAt)->getTimestamp() < now()->getTimestamp()
            : $expiresAt->getTimestamp() < now()->getTimestamp();
    }

    /**
     * Check if token is valid (not revoked and not expired).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }
}
