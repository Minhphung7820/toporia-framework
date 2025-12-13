<?php

declare(strict_types=1);

namespace Toporia\Framework\Security;

use Toporia\Framework\Security\Contracts\CsrfTokenManagerInterface;
use Toporia\Framework\Session\Store;

/**
 * Class SessionCsrfTokenManager
 *
 * Session-based CSRF token manager.
 * Stores CSRF tokens in the session for validation using cryptographically secure random tokens.
 *
 * Uses framework's Store class instead of $_SESSION superglobal for consistency
 * and to support different session drivers (file, redis, database, etc.).
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Security
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SessionCsrfTokenManager implements CsrfTokenManagerInterface
{
    private const SESSION_KEY_PREFIX = '_csrf_';

    public function __construct(
        private Store $session
    ) {}

    public function generate(string $key = '_token'): string
    {
        $token = $this->generateRandomToken();
        $this->storeToken($key, $token);
        return $token;
    }

    public function validate(string $token, string $key = '_token'): bool
    {
        $storedToken = $this->getToken($key);

        if ($storedToken === null) {
            return false;
        }

        // Use hash_equals to prevent timing attacks
        return hash_equals($storedToken, $token);
    }

    public function regenerate(string $key = '_token'): string
    {
        $this->remove($key);
        return $this->generate($key);
    }

    public function remove(string $key = '_token'): void
    {
        $sessionKey = $this->getSessionKey($key);
        $this->session->remove($sessionKey);
    }

    public function getToken(string $key = '_token'): ?string
    {
        $sessionKey = $this->getSessionKey($key);
        return $this->session->get($sessionKey);
    }

    /**
     * Generate a cryptographically secure random token
     *
     * @return string
     */
    private function generateRandomToken(): string
    {
        return bin2hex(random_bytes(32)); // 64 character hex string
    }

    /**
     * Store token in session
     *
     * @param string $key
     * @param string $token
     * @return void
     */
    private function storeToken(string $key, string $token): void
    {
        $sessionKey = $this->getSessionKey($key);
        $this->session->set($sessionKey, $token);
    }

    /**
     * Get the session key for a given token key
     *
     * @param string $key
     * @return string
     */
    private function getSessionKey(string $key): string
    {
        return self::SESSION_KEY_PREFIX . $key;
    }
}
