<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Tokens;

use Toporia\Framework\Auth\Contracts\PersonalAccessTokenInterface;
use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Database\ORM\Relations\MorphTo;


/**
 * Class PersonalAccessToken
 *
 * Core class for the Tokens layer providing essential functionality for
 * the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Tokens
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class PersonalAccessToken extends Model implements PersonalAccessTokenInterface
{
    protected static string $table = 'personal_access_tokens';
    protected static string $primaryKey = 'id';
    protected static bool $timestamps = true;

    protected static array $fillable = [
        'tokenable_type',
        'tokenable_id',
        'name',
        'token',
        'abilities',
        'last_used_at',
        'expires_at',
    ];

    protected static array $casts = [
        'tokenable_id' => 'int',
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Find a token by the given plain text token.
     *
     * Performance: O(1) with indexed token column
     *
     * @param string $token Plain text token (format: "ID|plain-text")
     * @return self|null Token instance or null
     */
    public static function findToken(string $token): ?self
    {
        // Extract ID from token format: "ID|plain-text"
        if (!str_contains($token, '|')) {
            return null;
        }

        [$id, $plainToken] = explode('|', $token, 2);

        // Hash the plain text token
        $hashedToken = hash('sha256', $plainToken);

        // Find by ID and verify hash matches
        $accessToken = static::find($id);

        if (!$accessToken || !hash_equals($accessToken->token, $hashedToken)) {
            return null;
        }

        return $accessToken;
    }

    /**
     * Determine if the token has a given ability/scope.
     *
     * Performance: O(N) where N = number of abilities (typically 1-10)
     *
     * @param string $ability Ability to check
     * @return bool True if token has ability
     */
    public function can(string $ability): bool
    {
        $abilities = $this->getAbilities();

        // Wildcard (*) grants all abilities
        if (in_array('*', $abilities, true)) {
            return true;
        }

        return in_array($ability, $abilities, true);
    }

    /**
     * Determine if the token is missing a given ability/scope.
     *
     * @param string $ability Ability to check
     * @return bool True if token lacks ability
     */
    public function cant(string $ability): bool
    {
        return !$this->can($ability);
    }

    /**
     * Get the token's abilities/scopes.
     *
     * @return array<string> Token abilities
     */
    public function getAbilities(): array
    {
        $abilities = $this->abilities;

        if (is_string($abilities)) {
            $abilities = json_decode($abilities, true);
        }

        return $abilities ?? ['*'];
    }

    /**
     * Determine if the token has expired.
     *
     * @return bool True if expired
     */
    public function hasExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        if (is_string($this->expires_at)) {
            return \Toporia\Framework\DateTime\Chronos::parse($this->expires_at)->getTimestamp() < now()->getTimestamp();
        }

        if ($this->expires_at instanceof \DateTimeInterface) {
            return $this->expires_at->getTimestamp() < now()->getTimestamp();
        }

        return false;
    }

    /**
     * Revoke the token (mark as revoked but don't delete).
     *
     * Note: We achieve revocation by setting expires_at to past
     * to avoid adding a new 'revoked' column.
     *
     * @return bool True if revoked successfully
     */
    public function revoke(): bool
    {
        $this->expires_at = now()->subSecond()->toDateTimeString();
        return $this->save();
    }

    /**
     * Update the token's last used timestamp.
     *
     * Performance: Single UPDATE query
     *
     * @return bool True if updated successfully
     */
    public function touchLastUsedAt(): bool
    {
        $this->last_used_at = now()->toDateTimeString();
        return $this->save();
    }

    /**
     * Get the token's owner (user/model) using polymorphic relationship.
     *
     * This method returns the actual model instance (User, Admin, etc.)
     * that owns this token, using the morphTo relationship.
     *
     * Performance: Lazy loaded when accessed, use with() for eager loading
     *
     * @return Model|null Token owner model instance
     */
    public function getTokenable(): ?Model
    {
        return $this->tokenable()->getResults()->first();
    }

    /**
     * Define the polymorphic relationship to the token's owner.
     *
     * This allows tokens to belong to any model (User, Admin, ApiClient, etc.)
     * The tokenable_type column stores the model class name.
     * The tokenable_id column stores the model's primary key.
     *
     * Usage:
     * ```php
     * // Get the user who owns this token
     * $user = $token->tokenable;
     *
     * // Eager load tokenable with tokens
     * $tokens = PersonalAccessToken::with('tokenable')->get();
     * ```
     *
     * @return MorphTo
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable', 'tokenable_type', 'tokenable_id');
    }

    /**
     * Get the token's name/identifier.
     *
     * @return string Token name
     */
    public function getName(): string
    {
        return $this->name ?? '';
    }

    /**
     * Get the token's ID.
     *
     * @return int|string Token ID
     */
    public function getId(): int|string
    {
        return $this->id;
    }

    /**
     * Create a hashed version of the plain text token.
     *
     * Security: SHA-256 hash prevents token recovery from database breach
     *
     * @param string $plainTextToken Plain text token
     * @return string Hashed token
     */
    public static function hashToken(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }

    /**
     * Generate a random plain text token.
     *
     * Security: 40 bytes = 320 bits of entropy
     *
     * @return string Random token
     */
    public static function generatePlainTextToken(): string
    {
        return bin2hex(random_bytes(40));
    }
}
