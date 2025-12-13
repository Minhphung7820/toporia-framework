<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Models;

use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Auth\OAuth2\Contracts\ClientInterface;
use Toporia\Framework\Support\Accessors\Hash;

/**
 * Class OAuth2Client
 *
 * Represents an OAuth2 client (application).
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
final class OAuth2Client extends Model implements ClientInterface
{
    protected static string $table = 'oauth_clients';
    protected static bool $timestamps = true;

    protected static array $fillable = [
        'name',
        'client_id',
        'client_secret',
        'redirect_uri',
        'is_confidential',
        'scopes',
    ];

    protected static array $casts = [
        'is_confidential' => 'bool',
        'scopes' => 'array',
    ];

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->getAttribute('client_id');
    }

    /**
     * {@inheritdoc}
     */
    public function getSecret(): ?string
    {
        return $this->getAttribute('client_secret');
    }

    /**
     * {@inheritdoc}
     */
    public function verifySecret(string $secret): bool
    {
        $hashedSecret = $this->getSecret();
        if ($hashedSecret === null) {
            return false; // Public client has no secret
        }

        return Hash::check($secret, $hashedSecret);
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectUri(): string
    {
        return $this->getAttribute('redirect_uri') ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function isConfidential(): bool
    {
        return $this->getAttribute('is_confidential') ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function getScopes(): array
    {
        return $this->getAttribute('scopes') ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function hasScope(string $scope): bool
    {
        $scopes = $this->getScopes();
        return in_array($scope, $scopes, true) || in_array('*', $scopes, true);
    }

    /**
     * Generate a new client ID.
     *
     * @return string Client ID
     */
    public static function generateClientId(): string
    {
        return bin2hex(random_bytes(32)); // 64 character hex string
    }

    /**
     * Generate a new client secret.
     *
     * @return string Plain text client secret
     */
    public static function generateClientSecret(): string
    {
        return bin2hex(random_bytes(32)); // 64 character hex string
    }

    /**
     * Hash a client secret.
     *
     * @param string $secret Plain text secret
     * @return string Hashed secret
     */
    public static function hashSecret(string $secret): string
    {
        return Hash::make($secret);
    }
}
