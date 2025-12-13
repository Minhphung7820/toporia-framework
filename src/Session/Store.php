<?php

declare(strict_types=1);

namespace Toporia\Framework\Session;

use Toporia\Framework\Session\Contracts\SessionStoreInterface;

/**
 * Class Store
 *
 * Wrapper around PHP native session with driver support.
 * Provides unified interface for session management.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Session
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Store implements SessionStoreInterface
{
    private bool $started = false;
    private string $id;
    private string $name;

    /**
     * Session cookie configuration.
     * SECURITY: Secure defaults for session cookies.
     */
    private array $cookieConfig;

    public function __construct(
        private SessionStoreInterface $driver,
        string $name = 'PHPSESSID',
        array $cookieConfig = []
    ) {
        $this->name = $name;
        $this->id = $this->driver->getId();

        // SECURITY: Merge with secure defaults
        $this->cookieConfig = array_merge([
            'lifetime' => 0,           // Session cookie (expires when browser closes)
            'path' => '/',             // Available across entire domain
            'domain' => '',            // Current domain only
            'secure' => true,          // HTTPS only (set to false for development)
            'httponly' => true,        // No JavaScript access (XSS protection)
            'samesite' => 'Lax',       // CSRF protection (Strict, Lax, or None)
        ], $cookieConfig);
    }

    /**
     * Start the session.
     *
     * SECURITY: Configures secure session cookie parameters before starting.
     * Performance: O(1) - Direct driver call
     *
     * @return bool True on success
     */
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        // SECURITY: Set secure cookie parameters before starting session
        $this->configureSecureCookie();

        $this->started = $this->driver->start();
        if ($this->started) {
            $this->id = $this->driver->getId();
        }

        return $this->started;
    }

    /**
     * Configure secure session cookie parameters.
     *
     * SECURITY: Sets HttpOnly, Secure, SameSite attributes to prevent:
     * - XSS attacks (HttpOnly)
     * - Session hijacking over HTTP (Secure)
     * - CSRF attacks (SameSite)
     *
     * @return void
     */
    private function configureSecureCookie(): void
    {
        // Only configure if session not already started
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Set session name
        session_name($this->name);

        // PHP 7.3+ supports SameSite in session_set_cookie_params
        session_set_cookie_params([
            'lifetime' => $this->cookieConfig['lifetime'],
            'path' => $this->cookieConfig['path'],
            'domain' => $this->cookieConfig['domain'],
            'secure' => $this->cookieConfig['secure'],
            'httponly' => $this->cookieConfig['httponly'],
            'samesite' => $this->cookieConfig['samesite'],
        ]);
    }

    /**
     * Get session cookie configuration.
     *
     * @return array Cookie configuration
     */
    public function getCookieConfig(): array
    {
        return $this->cookieConfig;
    }

    /**
     * Get a session value.
     *
     * Performance: O(1) - Array access
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $this->driver->get($key, $default);
    }

    /**
     * Set a session value.
     *
     * Performance: O(1) - Array assignment
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $this->driver->set($key, $value);
    }

    /**
     * Put a session value (alias for set).
     *
     * Performance: O(1) - Array assignment
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function put(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    /**
     * Check if a session key exists.
     *
     * Performance: O(1) - Array key check
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        return $this->driver->has($key);
    }

    /**
     * Remove a session value.
     *
     * Performance: O(1) - Array unset
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void
    {
        $this->ensureStarted();
        $this->driver->remove($key);
    }

    /**
     * Get all session data.
     *
     * Performance: O(N) where N = session keys
     *
     * @return array
     */
    public function all(): array
    {
        $this->ensureStarted();
        return $this->driver->all();
    }

    /**
     * Clear all session data.
     *
     * Performance: O(1) - Direct clear
     *
     * @return void
     */
    public function flush(): void
    {
        $this->ensureStarted();
        $this->driver->flush();
    }

    /**
     * Regenerate session ID.
     *
     * Security: Prevents session fixation attacks.
     * Performance: O(1) - Direct driver call
     *
     * @param bool $deleteOldSession Delete old session data
     * @return bool True on success
     */
    public function regenerate(bool $deleteOldSession = false): bool
    {
        $this->ensureStarted();
        $result = $this->driver->regenerate($deleteOldSession);
        if ($result) {
            $this->id = $this->driver->getId();
        }
        return $result;
    }

    /**
     * Get session ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Set session ID.
     *
     * @param string $id
     * @return void
     */
    public function setId(string $id): void
    {
        $this->id = $id;
        $this->driver->setId($id);
    }

    /**
     * Get session name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Save session data.
     *
     * Performance: O(N) where N = session data size
     *
     * @return bool True on success
     */
    public function save(): bool
    {
        if (!$this->started) {
            return true;
        }

        return $this->driver->save();
    }

    /**
     * Ensure session is started.
     *
     * Performance: O(1) - Check flag, start if needed
     *
     * @return void
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }

    // ============================================================================
    // Enhanced Session Methods for Request Integration
    // ============================================================================

    /**
     * Get flash data from session.
     *
     * Flash data is temporary data that persists only for the next request.
     *
     * @param string|null $key Flash key or null for all flash data
     * @param mixed $default Default value if key not found
     * @return mixed Flash data
     */
    public function getFlash(?string $key = null, mixed $default = null): mixed
    {
        $this->ensureStarted();
        $flashData = $this->driver->get('_flash', []);

        if ($key === null) {
            return $flashData;
        }

        return $flashData[$key] ?? $default;
    }

    /**
     * Set flash data in session.
     *
     * @param string|array $key Flash key or array of key-value pairs
     * @param mixed $value Flash value (ignored if key is array)
     * @return void
     */
    public function setFlash(string|array $key, mixed $value = null): void
    {
        $this->ensureStarted();
        $flashData = $this->driver->get('_flash', []);

        if (is_array($key)) {
            $flashData = array_merge($flashData, $key);
        } else {
            $flashData[$key] = $value;
        }

        $this->driver->set('_flash', $flashData);
    }

    /**
     * Check if flash data exists.
     *
     * @param string|null $key Specific flash key to check (null = any flash data)
     * @return bool True if flash data exists
     */
    public function hasFlash(?string $key = null): bool
    {
        $this->ensureStarted();
        $flashData = $this->driver->get('_flash', []);

        if ($key === null) {
            return !empty($flashData);
        }

        return isset($flashData[$key]);
    }

    /**
     * Remove flash data from session.
     *
     * @param string|null $key Specific flash key to remove (null = all flash data)
     * @return void
     */
    public function removeFlash(?string $key = null): void
    {
        $this->ensureStarted();

        if ($key === null) {
            $this->driver->remove('_flash');
        } else {
            $flashData = $this->driver->get('_flash', []);
            unset($flashData[$key]);
            $this->driver->set('_flash', $flashData);
        }
    }

    /**
     * Get old input data from session.
     *
     * Old input is form data from the previous request, used for form repopulation.
     *
     * @param string|null $key Input key or null for all old input
     * @param mixed $default Default value if key not found
     * @return mixed Old input data
     */
    public function getOldInput(?string $key = null, mixed $default = null): mixed
    {
        $this->ensureStarted();
        $oldInput = $this->driver->get('_old_input', []);

        if ($key === null) {
            return $oldInput;
        }

        return $oldInput[$key] ?? $default;
    }

    /**
     * Set old input data in session.
     *
     * @param array $input Input data to store
     * @return void
     */
    public function setOldInput(array $input): void
    {
        $this->ensureStarted();
        $this->driver->set('_old_input', $input);
    }

    /**
     * Check if old input data exists.
     *
     * @param string|null $key Specific input key to check (null = any old input)
     * @return bool True if old input exists
     */
    public function hasOldInput(?string $key = null): bool
    {
        $this->ensureStarted();
        $oldInput = $this->driver->get('_old_input', []);

        if ($key === null) {
            return !empty($oldInput);
        }

        return isset($oldInput[$key]);
    }

    /**
     * Remove old input data from session.
     *
     * @return void
     */
    public function removeOldInput(): void
    {
        $this->ensureStarted();
        $this->driver->remove('_old_input');
    }

    /**
     * Get multiple session values at once.
     *
     * @param array<string> $keys Session keys to retrieve
     * @param mixed $default Default value for missing keys
     * @return array<string, mixed> Session values
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        $this->ensureStarted();
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->driver->get($key, $default);
        }

        return $result;
    }

    /**
     * Set multiple session values at once.
     *
     * @param array<string, mixed> $values Key-value pairs to set
     * @return void
     */
    public function setMultiple(array $values): void
    {
        $this->ensureStarted();

        foreach ($values as $key => $value) {
            $this->driver->set($key, $value);
        }
    }

    /**
     * Pull a value from session (get and remove).
     *
     * @param string $key Session key
     * @param mixed $default Default value if key not found
     * @return mixed Session value
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        $value = $this->driver->get($key, $default);
        $this->driver->remove($key);
        return $value;
    }

    /**
     * Check if session is started.
     *
     * @return bool True if session is started
     */
    public function isStarted(): bool
    {
        return $this->started;
    }
}
