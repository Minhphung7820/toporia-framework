<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

/**
 * Class CookieJar
 *
 * Manages HTTP cookies with encryption support.
 * Provides a fluent interface for creating and managing cookies.
 *
 * Security Features:
 * - AES-256-GCM authenticated encryption (prevents tampering)
 * - Random IV per encryption
 * - HKDF key derivation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class CookieJar
{
    /**
     * AES-256-GCM cipher for authenticated encryption.
     */
    private const CIPHER = 'aes-256-gcm';

    /**
     * IV length for AES-GCM (12 bytes recommended by NIST).
     */
    private const IV_LENGTH = 12;

    /**
     * Authentication tag length for GCM (16 bytes = 128 bits).
     */
    private const TAG_LENGTH = 16;

    private array $queued = [];
    private ?string $encryptionKey = null;

    public function __construct(?string $encryptionKey = null)
    {
        $this->encryptionKey = $encryptionKey;
    }

    /**
     * Get a cookie value
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed
    {
        if (!isset($_COOKIE[$name])) {
            return $default;
        }

        $value = $_COOKIE[$name];

        // Decrypt if encryption is enabled
        if ($this->encryptionKey !== null) {
            $value = $this->decrypt($value);
        }

        return $value;
    }

    /**
     * Check if a cookie exists
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($_COOKIE[$name]);
    }

    /**
     * Queue a cookie to be sent
     *
     * @param Cookie $cookie
     * @return self
     */
    public function queue(Cookie $cookie): self
    {
        $this->queued[$cookie->name] = $cookie;
        return $this;
    }

    /**
     * Create and queue a cookie
     *
     * @param string $name
     * @param string $value
     * @param int $minutes
     * @param array $options
     * @return self
     */
    public function make(string $name, string $value, int $minutes = 60, array $options = []): self
    {
        // Encrypt value if encryption is enabled
        if ($this->encryptionKey !== null) {
            $value = $this->encrypt($value);
        }

        $cookie = Cookie::make($name, $value, $minutes, $options);
        return $this->queue($cookie);
    }

    /**
     * Create and queue a cookie that lasts forever
     *
     * @param string $name
     * @param string $value
     * @param array $options
     * @return self
     */
    public function forever(string $name, string $value, array $options = []): self
    {
        return $this->make($name, $value, 60 * 24 * 365 * 5, $options);
    }

    /**
     * Queue a cookie for deletion
     *
     * @param string $name
     * @param array $options
     * @return self
     */
    public function forget(string $name, array $options = []): self
    {
        $cookie = Cookie::forget($name, $options);
        return $this->queue($cookie);
    }

    /**
     * Send all queued cookies
     *
     * @return void
     */
    public function sendQueued(): void
    {
        foreach ($this->queued as $cookie) {
            $cookie->send();
        }

        $this->queued = [];
    }

    /**
     * Get all queued cookies
     *
     * @return array
     */
    public function getQueued(): array
    {
        return $this->queued;
    }

    /**
     * Encrypt a cookie value.
     *
     * Security: Uses AES-256-GCM (authenticated encryption) with random IV.
     * This prevents both tampering (via authentication tag) and decryption attacks.
     *
     * Format: base64(iv + tag + ciphertext)
     * - IV: 12 bytes (NIST recommended for GCM)
     * - Tag: 16 bytes (128-bit authentication)
     * - Ciphertext: variable length
     *
     * Performance: O(N) where N = value length
     *
     * @param string $value
     * @return string Encrypted value (base64 encoded)
     * @throws \RuntimeException If encryption fails
     */
    public function encrypt(string $value): string
    {
        if ($this->encryptionKey === null) {
            return $value; // No encryption if key not set
        }

        // Derive encryption key from APP_KEY (32 bytes for AES-256)
        $key = $this->deriveKey($this->encryptionKey);

        // Generate random IV (12 bytes for GCM as recommended by NIST)
        $iv = random_bytes(self::IV_LENGTH);

        // Encrypt with AES-256-GCM (authenticated encryption)
        $tag = '';
        $encrypted = openssl_encrypt(
            $value,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',  // Additional authenticated data (AAD)
            self::TAG_LENGTH
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Cookie encryption failed');
        }

        // Format: IV + Tag + Ciphertext (all base64 encoded together)
        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Decrypt a cookie value.
     *
     * Security: Uses AES-256-GCM which validates the authentication tag
     * before returning decrypted data. This prevents tampering attacks.
     *
     * Performance: O(N) where N = value length
     *
     * @param string $value Encrypted value (base64 encoded)
     * @return string|null Decrypted value or null on failure/tampering
     */
    public function decrypt(string $value): ?string
    {
        if ($this->encryptionKey === null) {
            return $value; // No decryption if key not set
        }

        $data = base64_decode($value, true);

        // Minimum length: IV (12) + Tag (16) + at least 1 byte ciphertext
        $minLength = self::IV_LENGTH + self::TAG_LENGTH + 1;
        if ($data === false || strlen($data) < $minLength) {
            return null; // Invalid format
        }

        // Extract IV, Tag, and Ciphertext
        $iv = substr($data, 0, self::IV_LENGTH);
        $tag = substr($data, self::IV_LENGTH, self::TAG_LENGTH);
        $encrypted = substr($data, self::IV_LENGTH + self::TAG_LENGTH);

        // Derive encryption key from APP_KEY
        $key = $this->deriveKey($this->encryptionKey);

        // Decrypt with AES-256-GCM (will fail if tag doesn't match = tampering detected)
        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        // Returns false if decryption fails OR if authentication tag is invalid
        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Derive encryption key from APP_KEY.
     *
     * Security: Uses HKDF to derive a 32-byte key for AES-256.
     * Performance: O(1) - Single hash operation
     *
     * @param string $key APP_KEY
     * @return string 32-byte encryption key
     */
    private function deriveKey(string $key): string
    {
        // Use HKDF to derive a 32-byte key for AES-256
        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', $key, 32, 'cookie-encryption');
        }

        // Fallback: hash and take first 32 bytes
        return substr(hash('sha256', $key . 'cookie-encryption', true), 0, 32);
    }
}
