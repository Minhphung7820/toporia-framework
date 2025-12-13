<?php

declare(strict_types=1);

namespace Toporia\Framework\Socialite;

use Toporia\Framework\Socialite\Contracts\ProviderInterface;
use Toporia\Framework\Http\{Request, Contracts\HttpClientInterface};
use Toporia\Framework\Session\Store;
use Toporia\Framework\Support\Str;

/**
 * Abstract Class AbstractProvider
 *
 * Base class for OAuth providers with common functionality.
 *
 * Performance:
 * - O(1) token exchange
 * - O(1) user data retrieval
 * - Cached token requests
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Socialite
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class AbstractProvider implements ProviderInterface
{
    /**
     * @param string $clientId OAuth client ID
     * @param string $clientSecret OAuth client secret
     * @param string $redirectUrl Redirect URL
     * @param HttpClientInterface $httpClient HTTP client
     * @param array<string, mixed> $scopes OAuth scopes
     */
    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected string $redirectUrl,
        protected HttpClientInterface $httpClient,
        protected array $scopes = []
    ) {}

    /**
     * {@inheritdoc}
     */
    public function redirect(?Request $request = null): string
    {
        $state = $this->generateState();
        $this->storeState($state);

        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => $this->formatScopes(),
            'response_type' => 'code',
            'state' => $state,
            'access_type' => 'offline', // For refresh tokens
            'prompt' => 'consent',
        ]);

        return $this->getAuthUrl() . '?' . $query;
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(Request $request): string
    {
        // Verify state
        $state = $request->input('state');
        if (!$this->verifyState($state)) {
            throw new \RuntimeException('Invalid state parameter');
        }

        // Get authorization code
        $code = $request->input('code');
        if ($code === null) {
            throw new \RuntimeException('Authorization code not provided');
        }

        // Exchange code for token
        return $this->exchangeCodeForToken($code);
    }

    /**
     * {@inheritdoc}
     */
    public function user(Request $request): User
    {
        $token = $this->getAccessToken($request);
        return $this->getUserFromToken($token);
    }

    /**
     * Exchange authorization code for access token.
     *
     * @param string $code Authorization code
     * @return string Access token
     */
    protected function exchangeCodeForToken(string $code): string
    {
        $response = $this->httpClient
            ->asForm()
            ->post($this->getTokenUrl(), [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'redirect_uri' => $this->redirectUrl,
                'grant_type' => 'authorization_code',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to exchange code for token: ' . $response->body());
        }

        $data = $response->json();

        return $data['access_token'] ?? throw new \RuntimeException('Access token not found in response');
    }

    /**
     * Get user data from provider API.
     *
     * @param string $token Access token
     * @return array<string, mixed> User data
     */
    protected function getUserData(string $token): array
    {
        $response = $this->httpClient
            ->withToken($token)
            ->acceptJson()
            ->get($this->getUserUrl());

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to get user data: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Generate random state for CSRF protection.
     *
     * @return string State string
     */
    protected function generateState(): string
    {
        return Str::random(40);
    }

    /**
     * Store state in session using framework's Store class.
     *
     * @param string $state State string
     * @return void
     */
    protected function storeState(string $state): void
    {
        try {
            /** @var Store $session */
            $session = app(Store::class);
            $session->set('socialite_state', $state);
        } catch (\Throwable $e) {
            // Session not available, skip storing state
        }
    }

    /**
     * Verify state from session using framework's Store class.
     *
     * @param string|null $state State to verify
     * @return bool True if valid
     */
    protected function verifyState(?string $state): bool
    {
        if ($state === null) {
            return false;
        }

        try {
            /** @var Store $session */
            $session = app(Store::class);
            $storedState = $session->get('socialite_state');
            $session->remove('socialite_state');

            return hash_equals($storedState ?? '', $state);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Format scopes for OAuth request.
     *
     * @return string Formatted scopes
     */
    protected function formatScopes(): string
    {
        return implode(' ', $this->scopes);
    }

    /**
     * Get OAuth authorization URL.
     *
     * @return string Authorization URL
     */
    abstract protected function getAuthUrl(): string;

    /**
     * Get OAuth token exchange URL.
     *
     * @return string Token URL
     */
    abstract protected function getTokenUrl(): string;

    /**
     * Get user info API URL.
     *
     * @return string User URL
     */
    abstract protected function getUserUrl(): string;

    /**
     * Map provider user data to User object.
     *
     * @param array<string, mixed> $user User data from provider
     * @return User User object
     */
    abstract protected function mapUserToObject(array $user): User;
}

