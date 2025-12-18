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

    // ============================================================================
    // Flash Message Methods
    // ============================================================================

    /**
     * Flash a key / value pair to the session.
     *
     * Flash data is available only for the next request.
     * Commonly used for success/error messages after form submissions.
     *
     * Performance: O(1) - Array operations
     *
     * @param string $key The flash key
     * @param mixed $value The flash value (default: true)
     * @return void
     */
    public function flash(string $key, mixed $value = true): void
    {
        $this->ensureStarted();

        // Store in new flash data
        $newFlash = $this->driver->get('_flash.new', []);
        $newFlash[$key] = $value;
        $this->driver->set('_flash.new', $newFlash);

        // Remove from old flash (if exists) so it persists
        $oldFlash = $this->driver->get('_flash.old', []);
        unset($oldFlash[$key]);
        $this->driver->set('_flash.old', $oldFlash);
    }

    /**
     * Flash an input array to the session.
     *
     * Stores all form input for repopulating forms after validation failure.
     *
     * Performance: O(N) where N = number of input fields
     *
     * @param array<string, mixed> $value Input data to flash
     * @return void
     */
    public function flashInput(array $value): void
    {
        $this->flash('_old_input', $value);
    }

    /**
     * Flash data only for the current request.
     *
     * Unlike flash(), this data will NOT be available in the next request.
     * Useful for displaying data immediately without persisting.
     *
     * Performance: O(1) - Array operations
     *
     * @param string $key The flash key
     * @param mixed $value The flash value
     * @return void
     */
    public function now(string $key, mixed $value): void
    {
        $this->ensureStarted();

        // Store directly in old flash (will be cleared after this request)
        $oldFlash = $this->driver->get('_flash.old', []);
        $oldFlash[$key] = $value;
        $this->driver->set('_flash.old', $oldFlash);
    }

    /**
     * Reflash all flash data for an additional request.
     *
     * Keeps all current flash data available for one more request.
     * Useful when redirecting multiple times.
     *
     * Performance: O(N) where N = number of flash keys
     *
     * @return void
     */
    public function reflash(): void
    {
        $this->ensureStarted();

        $oldFlash = $this->driver->get('_flash.old', []);
        $newFlash = $this->driver->get('_flash.new', []);

        // Merge old into new to persist
        $this->driver->set('_flash.new', array_merge($oldFlash, $newFlash));
        $this->driver->set('_flash.old', []);
    }

    /**
     * Keep specific flash keys for an additional request.
     *
     * Selectively persists only the specified keys.
     *
     * Performance: O(K) where K = number of keys to keep
     *
     * @param array<int, string>|string $keys Keys to keep
     * @return void
     */
    public function keep(array|string $keys): void
    {
        $this->ensureStarted();

        $keys = is_array($keys) ? $keys : func_get_args();
        $oldFlash = $this->driver->get('_flash.old', []);
        $newFlash = $this->driver->get('_flash.new', []);

        foreach ($keys as $key) {
            if (isset($oldFlash[$key])) {
                $newFlash[$key] = $oldFlash[$key];
            }
        }

        $this->driver->set('_flash.new', $newFlash);
    }

    /**
     * Age the flash data for the session.
     *
     * Moves "new" flash data to "old" (making it available for current request).
     * Called automatically at the start of each request by middleware.
     *
     * Performance: O(1) - Array swap
     *
     * @return void
     */
    public function ageFlashData(): void
    {
        $this->ensureStarted();

        // Move new flash to old
        $newFlash = $this->driver->get('_flash.new', []);
        $this->driver->set('_flash.old', $newFlash);
        $this->driver->set('_flash.new', []);
    }

    /**
     * Get flash data by key.
     *
     * Retrieves flash data from the "old" storage (set in previous request).
     *
     * Performance: O(1) - Array access
     *
     * @param string $key The flash key
     * @param mixed $default Default value if key not found
     * @return mixed The flash value
     */
    public function getFlashData(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        $oldFlash = $this->driver->get('_flash.old', []);

        return $oldFlash[$key] ?? $default;
    }

    /**
     * Get all flash data.
     *
     * Returns all flash data available for the current request.
     *
     * Performance: O(1) - Array access
     *
     * @return array<string, mixed> All flash data
     */
    public function getAllFlash(): array
    {
        $this->ensureStarted();

        return $this->driver->get('_flash.old', []);
    }

    /**
     * Check if flash data exists for a key.
     *
     * Performance: O(1) - Array key check
     *
     * @param string $key The flash key to check
     * @return bool True if flash data exists
     */
    public function hasFlashData(string $key): bool
    {
        $this->ensureStarted();

        $oldFlash = $this->driver->get('_flash.old', []);

        return array_key_exists($key, $oldFlash);
    }

    /**
     * Remove flash data by key.
     *
     * Performance: O(1) - Array unset
     *
     * @param string $key The flash key to remove
     * @return void
     */
    public function forgetFlash(string $key): void
    {
        $this->ensureStarted();

        $oldFlash = $this->driver->get('_flash.old', []);
        $newFlash = $this->driver->get('_flash.new', []);

        unset($oldFlash[$key], $newFlash[$key]);

        $this->driver->set('_flash.old', $oldFlash);
        $this->driver->set('_flash.new', $newFlash);
    }

    /**
     * Clear all flash data.
     *
     * Removes all flash data from both old and new storage.
     *
     * Performance: O(1) - Array clear
     *
     * @return void
     */
    public function flushFlash(): void
    {
        $this->ensureStarted();

        $this->driver->remove('_flash.old');
        $this->driver->remove('_flash.new');
    }

    // ============================================================================
    // Additional Utility Methods
    // ============================================================================

    /**
     * Increment a session value.
     *
     * Performance: O(1) - Read, increment, write
     *
     * @param string $key The session key
     * @param int $amount Amount to increment by
     * @return int The new value
     */
    public function increment(string $key, int $amount = 1): int
    {
        $this->ensureStarted();

        $value = (int) $this->driver->get($key, 0);
        $value += $amount;
        $this->driver->set($key, $value);

        return $value;
    }

    /**
     * Decrement a session value.
     *
     * Performance: O(1) - Read, decrement, write
     *
     * @param string $key The session key
     * @param int $amount Amount to decrement by
     * @return int The new value
     */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, -$amount);
    }

    /**
     * Push a value onto a session array.
     *
     * Performance: O(1) - Array append
     *
     * @param string $key The session key
     * @param mixed $value Value to push
     * @return void
     */
    public function push(string $key, mixed $value): void
    {
        $this->ensureStarted();

        $array = $this->driver->get($key, []);

        if (!is_array($array)) {
            $array = [$array];
        }

        $array[] = $value;
        $this->driver->set($key, $array);
    }

    /**
     * Get the value of a given key and then forget it.
     *
     * Alias for pull() method for semantic clarity.
     *
     * Performance: O(1) - Get and remove
     *
     * @param string $key The session key
     * @param mixed $default Default value if key not found
     * @return mixed The session value
     */
    public function forget(string $key, mixed $default = null): mixed
    {
        return $this->pull($key, $default);
    }

    /**
     * Determine if the session contains old input.
     *
     * Performance: O(1) - Array key check
     *
     * @param string|null $key Specific key to check (null = any old input)
     * @return bool True if old input exists
     */
    public function hasOld(?string $key = null): bool
    {
        $old = $this->getFlashData('_old_input', []);

        if ($key === null) {
            return !empty($old);
        }

        return array_key_exists($key, $old);
    }

    /**
     * Get old input value.
     *
     * Retrieves previously flashed input for form repopulation.
     *
     * Performance: O(1) - Array access
     *
     * @param string|null $key Specific key to get (null = all old input)
     * @param mixed $default Default value if key not found
     * @return mixed Old input value
     */
    public function old(?string $key = null, mixed $default = null): mixed
    {
        $old = $this->getFlashData('_old_input', []);

        if ($key === null) {
            return $old;
        }

        return $old[$key] ?? $default;
    }

    /**
     * Check if any errors exist in session.
     *
     * Performance: O(1) - Array check
     *
     * @param string|null $key Specific error key to check
     * @return bool True if errors exist
     */
    public function hasErrors(?string $key = null): bool
    {
        $errors = $this->getFlashData('errors', []);

        if ($key === null) {
            return !empty($errors);
        }

        return isset($errors[$key]);
    }

    /**
     * Get error messages from session.
     *
     * Performance: O(1) - Array access
     *
     * @param string|null $key Specific error key to get
     * @return mixed Error messages
     */
    public function errors(?string $key = null): mixed
    {
        $errors = $this->getFlashData('errors', []);

        if ($key === null) {
            return $errors;
        }

        return $errors[$key] ?? null;
    }

    /**
     * Get the CSRF token value.
     *
     * Performance: O(1) - Direct get
     *
     * @return string|null CSRF token
     */
    public function token(): ?string
    {
        return $this->get('_token');
    }

    /**
     * Regenerate the CSRF token.
     *
     * Performance: O(1) - Generate and store
     *
     * @return string New CSRF token
     */
    public function regenerateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->set('_token', $token);

        return $token;
    }

    /**
     * Get the previous URL from the session.
     *
     * Performance: O(1) - Direct get
     *
     * @return string|null Previous URL
     */
    public function previousUrl(): ?string
    {
        return $this->get('_previous.url');
    }

    /**
     * Set the previous URL in the session.
     *
     * Performance: O(1) - Direct set
     *
     * @param string $url The URL to store
     * @return void
     */
    public function setPreviousUrl(string $url): void
    {
        $this->set('_previous.url', $url);
    }

    /**
     * Get the intended URL from the session.
     *
     * Used for redirecting users back to their original destination after login.
     *
     * Performance: O(1) - Direct get
     *
     * @param string $default Default URL if no intended URL
     * @return string Intended URL
     */
    public function intended(string $default = '/'): string
    {
        return $this->pull('url.intended', $default);
    }

    /**
     * Set the intended URL in the session.
     *
     * Performance: O(1) - Direct set
     *
     * @param string $url The intended URL
     * @return void
     */
    public function setIntended(string $url): void
    {
        $this->set('url.intended', $url);
    }
}
