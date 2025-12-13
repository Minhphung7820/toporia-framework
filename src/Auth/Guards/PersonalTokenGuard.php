<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Guards;

use Toporia\Framework\Auth\Authenticatable;
use Toporia\Framework\Auth\Contracts\{GuardInterface, HasApiTokensInterface, TokenRepositoryInterface, UserProviderInterface};
use Toporia\Framework\Http\Request;

/**
 * Class PersonalTokenGuard
 *
 * Token-based authentication guard using personal access tokens.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Guards
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class PersonalTokenGuard implements GuardInterface
{
    /**
     * Authenticated user instance.
     *
     * @var (Authenticatable&HasApiTokensInterface)|null
     */
    private (Authenticatable&HasApiTokensInterface)|null $user = null;

    /**
     * Create Personal Token guard instance.
     *
     * @param Request $request HTTP request
     * @param UserProviderInterface $provider User provider
     * @param TokenRepositoryInterface $tokens Token repository
     */
    public function __construct(
        private readonly Request $request,
        private readonly UserProviderInterface $provider,
        private readonly TokenRepositoryInterface $tokens
    ) {
    }

    /**
     * Determine if the current user is authenticated.
     *
     * @return bool True if authenticated
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Determine if the current user is a guest (not authenticated).
     *
     * @return bool True if guest
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * Get the currently authenticated user.
     *
     * Performance:
     * - Cached after first call
     * - O(1) token lookup with cache
     * - Lazy user loading
     *
     * @return Authenticatable|null Authenticated user or null
     */
    public function user(): ?Authenticatable
    {
        // Return cached user
        if ($this->user !== null) {
            return $this->user;
        }

        // Extract token from request
        $token = $this->getTokenFromRequest();

        if ($token === null) {
            return null;
        }

        // Find token in database
        $accessToken = $this->tokens->findByPlainTextToken($token);

        if ($accessToken === null) {
            return null;
        }

        // Check if token expired
        if ($accessToken->hasExpired()) {
            return null;
        }

        // Get token owner
        $tokenable = $accessToken->getTokenable();

        if (!is_array($tokenable) || !isset($tokenable['type'], $tokenable['id'])) {
            return null;
        }

        // Load user via provider
        $user = $this->provider->retrieveById($tokenable['id']);

        if ($user === null || !$user instanceof HasApiTokensInterface || !$user instanceof Authenticatable) {
            return null;
        }

        // Set current access token on user
        $user->withAccessToken($accessToken);

        // Update last used timestamp (async/background task recommended)
        $this->tokens->touchLastUsedAt($accessToken->getId());

        // Cache user for request
        $this->user = $user;

        return $this->user;
    }

    /**
     * Get the ID of the currently authenticated user.
     *
     * @return int|string|null User ID or null
     */
    public function id(): int|string|null
    {
        $user = $this->user();

        return $user?->getAuthIdentifier();
    }

    /**
     * Attempt to authenticate a user with credentials.
     *
     * Note: Not applicable for token-based guard.
     * Use SessionGuard for credential-based authentication.
     *
     * @param array<string, mixed> $credentials User credentials
     * @return bool False (not supported)
     */
    public function attempt(array $credentials): bool
    {
        return false;
    }

    /**
     * Log in a user instance.
     *
     * Note: Not applicable for token-based guard.
     * Token-based auth doesn't maintain session state.
     *
     * @param Authenticatable $user User to log in
     * @return void
     */
    public function login(Authenticatable $user): void
    {
        // Token-based guard doesn't support login
        // Users authenticate via tokens, not session login
    }

    /**
     * Log out the currently authenticated user.
     *
     * Note: For token-based auth, logout means revoking the token.
     * This implementation clears the cached user for this request.
     *
     * @return void
     */
    public function logout(): void
    {
        $this->user = null;
    }

    /**
     * Validate user credentials.
     *
     * Note: Not applicable for token-based guard.
     * Use SessionGuard for credential validation.
     *
     * @param array $credentials User credentials
     * @return bool False (not supported)
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Determine if the guard has a user instance.
     *
     * @return bool True if user loaded
     */
    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    /**
     * Set the current user.
     *
     * @param Authenticatable&HasApiTokensInterface $user User instance
     * @return self
     */
    public function setUser(Authenticatable&HasApiTokensInterface $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Extract bearer token from request.
     *
     * Checks:
     * 1. Authorization header: "Bearer {token}"
     * 2. X-API-TOKEN header
     * 3. Query parameter: ?api_token={token}
     *
     * Performance: O(1) header lookup
     *
     * @return string|null Token or null
     */
    private function getTokenFromRequest(): ?string
    {
        // Check Authorization header
        $authorization = $this->request->header('Authorization');

        if ($authorization !== null && str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7); // Remove "Bearer " prefix
        }

        // Check X-API-TOKEN header
        $apiToken = $this->request->header('X-API-TOKEN');

        if ($apiToken !== null) {
            return $apiToken;
        }

        // Check query parameter (less secure, use only for specific cases)
        $queryToken = $this->request->query('api_token');

        if ($queryToken !== null) {
            return $queryToken;
        }

        return null;
    }
}

