<?php

declare(strict_types=1);

namespace Toporia\Framework\Session\Drivers;

use Toporia\Framework\Session\Contracts\SessionStoreInterface;
use Toporia\Framework\Http\CookieJar;

/**
 * Class CookieSessionDriver
 *
 * Stores sessions in encrypted cookies.
 * Stateless sessions - no server-side storage needed.
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
final class CookieSessionDriver implements SessionStoreInterface
{
    private bool $started = false;
    private array $data = [];
    private string $id;
    private string $name;
    private const COOKIE_NAME = 'session';

    public function __construct(
        private CookieJar $cookieJar,
        string $name = 'PHPSESSID',
        private int $lifetime = 7200
    ) {
        $this->name = $name;
        $this->id = $this->generateId();
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

        // Load session from cookie
        $payload = $this->cookieJar->get(self::COOKIE_NAME);
        if ($payload !== null && is_string($payload)) {
            // CookieJar already decrypts if encryption is enabled
            // Use JSON instead of serialize for security (prevents PHP Object Injection)
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                $this->data = $decoded;
                if (isset($this->data['_id'])) {
                    $this->id = $this->data['_id'];
                }
            }
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
        $this->id = $this->generateId();
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

        // Store session ID in data
        $this->data['_id'] = $this->id;

        // Use JSON for security (prevents PHP Object Injection attacks)
        $payload = json_encode($this->data, JSON_THROW_ON_ERROR);

        // CookieJar will encrypt automatically if encryption key is set
        $minutes = (int) ($this->lifetime / 60);
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'local';
        $this->cookieJar->make(self::COOKIE_NAME, $payload, $minutes, [
            'httpOnly' => true,
            'secure' => $appEnv === 'production',
            'sameSite' => 'Lax',
        ]);

        return true;
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
}
