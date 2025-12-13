<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Guards;

use Toporia\Framework\Auth\Authenticatable;
use Toporia\Framework\Auth\Contracts\{GuardInterface, UserProviderInterface};

/**
 * Class SessionGuard
 *
 * Session-based authentication guard storing user ID in PHP session
 * for stateful authentication. Follows Single Responsibility Principle.
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
final class SessionGuard implements GuardInterface
{
    private ?Authenticatable $user = null;
    private bool $userResolved = false;

    /**
     * @param UserProviderInterface $provider User provider for retrieving users.
     * @param string $name Guard name (for session key).
     */
    public function __construct(
        private UserProviderInterface $provider,
        private string $name = 'default'
    ) {}

    /**
     * {@inheritdoc}
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * {@inheritdoc}
     */
    public function user(): ?Authenticatable
    {
        if ($this->userResolved) {
            return $this->user;
        }

        $this->userResolved = true;

        // Try to get user from session
        $id = $this->getSessionId();
        if ($id !== null) {
            $this->user = $this->provider->retrieveById($id);
        }

        // Try remember token if no session
        if ($this->user === null) {
            $this->user = $this->getUserByRememberToken();
        }

        return $this->user;
    }

    /**
     * {@inheritdoc}
     */
    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * {@inheritdoc}
     */
    public function attempt(array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null) {
            return false;
        }

        if (!$this->provider->validateCredentials($user, $credentials)) {
            return false;
        }

        $this->login($user);

        // Handle "remember me"
        if ($credentials['remember'] ?? false) {
            $this->setRememberToken($user);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function login(Authenticatable $user): void
    {
        $this->updateSession($user->getAuthIdentifier());
        $this->user = $user;
        $this->userResolved = true;
    }

    /**
     * {@inheritdoc}
     */
    public function logout(): void
    {
        $this->clearUserData();
        $this->user = null;
        $this->userResolved = false;
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null) {
            return false;
        }

        return $this->provider->validateCredentials($user, $credentials);
    }

    /**
     * Get user ID from session.
     *
     * Uses framework's session() helper which stores in storage/sessions.
     *
     * @return int|string|null
     */
    private function getSessionId(): int|string|null
    {
        return session()->get($this->getName());
    }

    /**
     * Update session with user ID.
     *
     * Uses framework's session() helper which stores in storage/sessions.
     *
     * @param int|string $id User ID.
     * @return void
     */
    private function updateSession(int|string $id): void
    {
        session()->set($this->getName(), $id);
        session()->save();
    }

    /**
     * Get session key name.
     *
     * @return string
     */
    private function getName(): string
    {
        return 'auth_' . $this->name;
    }

    /**
     * Get remember token cookie name.
     *
     * @return string
     */
    private function getRememberTokenName(): string
    {
        return 'remember_' . $this->name;
    }

    /**
     * Set remember token cookie.
     *
     * Security: Uses secure cookie options to prevent token theft.
     * - Secure: Only sent over HTTPS (auto-detected)
     * - HttpOnly: Not accessible via JavaScript (XSS protection)
     * - SameSite: Lax to prevent CSRF while allowing normal navigation
     *
     * @param Authenticatable $user
     * @return void
     */
    private function setRememberToken(Authenticatable $user): void
    {
        $token = bin2hex(random_bytes(32));
        $this->provider->updateRememberToken($user, $token);

        // Security: Auto-detect HTTPS for Secure flag
        $isSecure = $this->isSecureConnection();

        // Set cookie for 30 days with secure options
        setcookie(
            $this->getRememberTokenName(),
            $user->getAuthIdentifier() . '|' . $token,
            [
                'expires' => now()->getTimestamp() + (86400 * 30),
                'path' => '/',
                'domain' => '',
                'secure' => $isSecure,    // Only send over HTTPS
                'httponly' => true,        // Not accessible via JavaScript
                'samesite' => 'Lax',       // CSRF protection
            ]
        );
    }

    /**
     * Check if the current connection is secure (HTTPS).
     *
     * @return bool
     */
    private function isSecureConnection(): bool
    {
        // Direct HTTPS
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Behind proxy (e.g., Cloudflare, AWS ELB)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        // Standard port check
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        return false;
    }

    /**
     * Get user by remember token from cookie.
     *
     * @return Authenticatable|null
     */
    private function getUserByRememberToken(): ?Authenticatable
    {
        $cookie = $_COOKIE[$this->getRememberTokenName()] ?? null;

        if ($cookie === null) {
            return null;
        }

        [$id, $token] = explode('|', $cookie, 2);

        if (empty($id) || empty($token)) {
            return null;
        }

        $user = $this->provider->retrieveByToken($id, $token);

        if ($user !== null) {
            // Re-login via session
            $this->login($user);
        }

        return $user;
    }

    /**
     * Clear user data from session and cookies.
     *
     * Security: Clears cookie with same options as when it was set
     * to ensure proper deletion across all browsers.
     *
     * @return void
     */
    private function clearUserData(): void
    {
        session()->remove($this->getName());
        session()->save();

        // Clear remember cookie with secure options
        if (isset($_COOKIE[$this->getRememberTokenName()])) {
            setcookie(
                $this->getRememberTokenName(),
                '',
                [
                    'expires' => now()->getTimestamp() - 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $this->isSecureConnection(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }
    }
}
