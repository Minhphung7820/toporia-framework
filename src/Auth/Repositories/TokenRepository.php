<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Repositories;

use Toporia\Framework\Auth\Contracts\{NewAccessTokenInterface, PersonalAccessTokenInterface, TokenRepositoryInterface};
use Toporia\Framework\Auth\Tokens\{NewAccessToken, PersonalAccessToken};
use Toporia\Framework\Cache\Contracts\CacheInterface;
use Toporia\Framework\Database\Connection;
use Toporia\Framework\Support\Collection\Collection;

/**
 * Class TokenRepository
 *
 * Database-backed token repository with caching layer.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Repositories
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class TokenRepository implements TokenRepositoryInterface
{
    private const CACHE_TTL = 300; // 5 minutes
    private const CACHE_PREFIX = 'auth:token:';

    /**
     * Create token repository instance.
     *
     * @param Connection $connection Database connection
     * @param CacheInterface|null $cache Optional cache layer
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?CacheInterface $cache = null
    ) {
    }

    /**
     * Create a new personal access token.
     *
     * Security:
     * - Generates 40-byte random token (320 bits entropy)
     * - Hashes with SHA-256 before storage
     * - Plain text returned ONCE in NewAccessToken
     *
     * Performance: Single INSERT query + cache write
     *
     * @param int|string $tokenableId Owner ID
     * @param string $tokenableType Owner type (e.g., 'App\\Domain\\User\\User')
     * @param string $name Token name
     * @param array<string> $abilities Token abilities/scopes
     * @param \DateTimeInterface|null $expiresAt Expiration time
     * @return NewAccessTokenInterface Newly created token
     */
    public function create(
        int|string $tokenableId,
        string $tokenableType,
        string $name,
        array $abilities = ['*'],
        ?\DateTimeInterface $expiresAt = null
    ): NewAccessTokenInterface {
        // Generate random plain text token
        $plainTextToken = PersonalAccessToken::generatePlainTextToken();

        // Hash for database storage
        $hashedToken = PersonalAccessToken::hashToken($plainTextToken);

        // Create token record
        $token = new PersonalAccessToken([
            'tokenable_type' => $tokenableType,
            'tokenable_id' => $tokenableId,
            'name' => $name,
            'token' => $hashedToken,
            'abilities' => json_encode($abilities),
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
        ]);

        $token->save();

        // Cache the token for fast lookup
        $this->cacheToken($token);

        // Return with plain text token (ONLY available once!)
        return new NewAccessToken(
            accessToken: $token,
            plainTextToken: $token->getId() . '|' . $plainTextToken
        );
    }

    /**
     * Find a token by its hashed value.
     *
     * Performance:
     * - O(1) with cache hit
     * - O(1) with indexed token column on cache miss
     *
     * @param string $hashedToken Hashed token
     * @return PersonalAccessTokenInterface|null Token or null
     */
    public function findByHashedToken(string $hashedToken): ?PersonalAccessTokenInterface
    {
        // Try cache first
        $cacheKey = self::CACHE_PREFIX . $hashedToken;

        if ($this->cache !== null && $this->cache->has($cacheKey)) {
            $data = $this->cache->get($cacheKey);
            return $this->hydrateToken($data);
        }

        // Query database
        $data = $this->connection->table('personal_access_tokens')
            ->where('token', $hashedToken)
            ->first();

        if ($data === null) {
            return null;
        }

        $token = $this->hydrateToken($data);

        // Cache for future lookups
        if ($this->cache !== null && $token !== null) {
            $this->cache->set($cacheKey, $data, self::CACHE_TTL);
        }

        return $token;
    }

    /**
     * Find a token by its plain text value.
     *
     * Format: "ID|plain-text-token"
     *
     * Performance: O(1) lookup by ID + hash comparison
     *
     * @param string $plainTextToken Plain text token
     * @return PersonalAccessTokenInterface|null Token or null
     */
    public function findByPlainTextToken(string $plainTextToken): ?PersonalAccessTokenInterface
    {
        return PersonalAccessToken::findToken($plainTextToken);
    }

    /**
     * Get all tokens for a given tokenable.
     *
     * Performance: Single SELECT with WHERE clause
     *
     * @param int|string $tokenableId Owner ID
     * @param string $tokenableType Owner type
     * @return Collection<PersonalAccessTokenInterface> Tokens
     */
    public function getTokensFor(int|string $tokenableId, string $tokenableType): Collection
    {
        $results = $this->connection->table('personal_access_tokens')
            ->where('tokenable_type', $tokenableType)
            ->where('tokenable_id', $tokenableId)
            ->orderBy('created_at', 'DESC')
            ->get();

        $tokens = array_map([$this, 'hydrateToken'], $results);

        return Collection::make(array_filter($tokens));
    }

    /**
     * Revoke a token.
     *
     * Performance: Single UPDATE + cache invalidation
     *
     * @param int|string $tokenId Token ID
     * @return bool True if revoked
     */
    public function revoke(int|string $tokenId): bool
    {
        $token = PersonalAccessToken::find($tokenId);

        if ($token === null) {
            return false;
        }

        $success = $token->revoke();

        // Invalidate cache
        if ($success) {
            $this->invalidateTokenCache($token->token);
        }

        return $success;
    }

    /**
     * Revoke all tokens for a given tokenable.
     *
     * Performance: Single UPDATE with WHERE clause
     *
     * @param int|string $tokenableId Owner ID
     * @param string $tokenableType Owner type
     * @return int Number of tokens revoked
     */
    public function revokeAllFor(int|string $tokenableId, string $tokenableType): int
    {
        $affected = $this->connection->table('personal_access_tokens')
            ->where('tokenable_type', $tokenableType)
            ->where('tokenable_id', $tokenableId)
            ->update([
                'expires_at' => now()->subSecond()->toDateTimeString(),
            ]);

        // Clear cache for user (tag-based)
        if ($this->cache !== null) {
            $this->cache->tags(["user:{$tokenableId}"])->flush();
        }

        return $affected;
    }

    /**
     * Delete expired tokens.
     *
     * Performance: Single DELETE with WHERE clause
     *
     * @return int Number of tokens deleted
     */
    public function deleteExpired(): int
    {
        return $this->connection->table('personal_access_tokens')
            ->where('expires_at', '<', now()->toDateTimeString())
            ->delete();
    }

    /**
     * Update token's last used timestamp.
     *
     * Performance: Single UPDATE
     *
     * @param int|string $tokenId Token ID
     * @return bool True if updated
     */
    public function touchLastUsedAt(int|string $tokenId): bool
    {
        $affected = $this->connection->table('personal_access_tokens')
            ->where('id', $tokenId)
            ->update([
                'last_used_at' => now()->toDateTimeString(),
            ]);

        return $affected > 0;
    }

    /**
     * Cache a token for fast lookup.
     *
     * @param PersonalAccessToken $token Token to cache
     * @return void
     */
    private function cacheToken(PersonalAccessToken $token): void
    {
        if ($this->cache === null) {
            return;
        }

        $cacheKey = self::CACHE_PREFIX . $token->token;

        $this->cache->set($cacheKey, $token->toArray(), self::CACHE_TTL);
    }

    /**
     * Invalidate token cache.
     *
     * @param string $hashedToken Hashed token
     * @return void
     */
    private function invalidateTokenCache(string $hashedToken): void
    {
        if ($this->cache === null) {
            return;
        }

        $cacheKey = self::CACHE_PREFIX . $hashedToken;
        $this->cache->delete($cacheKey);
    }

    /**
     * Hydrate token from database row.
     *
     * @param array $data Database row
     * @return PersonalAccessToken|null Token instance
     */
    private function hydrateToken(array $data): ?PersonalAccessToken
    {
        if (empty($data)) {
            return null;
        }

        $token = new PersonalAccessToken($data);
        $token->exists = true;

        return $token;
    }
}
