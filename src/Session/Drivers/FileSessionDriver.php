<?php

declare(strict_types=1);

namespace Toporia\Framework\Session\Drivers;

use Toporia\Framework\Session\Contracts\SessionStoreInterface;

/**
 * Class FileSessionDriver
 *
 * Stores sessions as files in the filesystem.
 * Uses PHP's native session handler with custom save path.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Session\Drivers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class FileSessionDriver implements SessionStoreInterface
{
    private bool $started = false;
    private array $data = [];
    private string $id;
    private string $name;

    public function __construct(
        private string $path,
        string $name = 'PHPSESSID',
        private int $lifetime = 7200
    ) {
        $this->name = $name;
        $this->ensureDirectoryExists();
        // Read session ID from cookie first, generate new if not exists
        $this->id = $this->getSessionIdFromCookie() ?? $this->generateId();
    }

    /**
     * Get session ID from cookie.
     *
     * @return string|null Session ID or null if not found
     */
    private function getSessionIdFromCookie(): ?string
    {
        $sessionId = $_COOKIE[$this->name] ?? null;

        // Validate session ID format (strict alphanumeric only - prevent injection)
        if ($sessionId !== null && preg_match('/^[a-zA-Z0-9]{22,256}$/', $sessionId)) {
            return $sessionId;
        }

        return null;
    }

    /**
     * Start the session.
     *
     * @return bool True on success
     */
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        // Load session data if exists
        $file = $this->getFilePath($this->id);
        if (file_exists($file)) {
            $this->data = $this->loadFromFile($file);
        } else {
            $this->data = [];
        }

        $this->started = true;
        return true;
    }

    /**
     * Get a session value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Set a session value.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Put a session value (alias for set).
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
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Remove a session value.
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Get all session data.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Clear all session data.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->data = [];
    }

    /**
     * Regenerate session ID.
     *
     * @param bool $deleteOldSession
     * @return bool True on success
     */
    public function regenerate(bool $deleteOldSession = false): bool
    {
        $oldId = $this->id;
        $this->id = $this->generateId();

        if ($deleteOldSession) {
            $oldFile = $this->getFilePath($oldId);
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
        }

        return true;
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
     * @return bool True on success
     */
    public function save(): bool
    {
        if (!$this->started) {
            return true;
        }

        $file = $this->getFilePath($this->id);
        $data = serialize([
            'data' => $this->data,
            'expires_at' => now()->getTimestamp() + $this->lifetime,
        ]);

        $result = file_put_contents($file, $data, LOCK_EX) !== false;

        // Set session cookie if not already set
        if ($result && !isset($_COOKIE[$this->name])) {
            $this->setSessionCookie();
        }

        return $result;
    }

    /**
     * Set session cookie with secure options.
     *
     * @return void
     */
    private function setSessionCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        $isSecure = $this->isSecureConnection();

        setcookie(
            $this->name,
            $this->id,
            [
                'expires' => 0, // Session cookie (expires when browser closes)
                'path' => '/',
                'domain' => '',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Check if current connection is secure (HTTPS).
     *
     * @return bool
     */
    private function isSecureConnection(): bool
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }

    /**
     * Get file path for session ID.
     *
     * @param string $id
     * @return string
     */
    private function getFilePath(string $id): string
    {
        return $this->path . '/sess_' . $id;
    }

    /**
     * Load session data from file.
     *
     * @param string $file
     * @return array
     */
    private function loadFromFile(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        // SECURITY: Restrict unserialize to prevent PHP Object Injection attacks
        $data = unserialize($content, ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['data'], $data['expires_at'])) {
            return [];
        }

        // Check expiration
        if ($data['expires_at'] < now()->getTimestamp()) {
            unlink($file);
            return [];
        }

        return $data['data'];
    }

    /**
     * Generate a cryptographically secure session ID.
     *
     * @return string
     */
    private function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Ensure session directory exists with proper permissions.
     *
     * SECURITY: Uses 0700 permissions (owner only) to prevent
     * other users on the server from reading session files.
     * This prevents session hijacking via file system access.
     *
     * @return void
     */
    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->path)) {
            // SECURITY: 0700 = only owner can read/write/execute
            mkdir($this->path, 0700, true);
        }

        // Ensure directory is writable by owner
        if (!is_writable($this->path)) {
            // Try 0700 first (secure), fallback to 0770 for group scenarios
            if (!@chmod($this->path, 0700)) {
                @chmod($this->path, 0770);
            }
        }
    }
}
