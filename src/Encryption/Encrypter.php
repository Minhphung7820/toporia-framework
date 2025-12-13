<?php

declare(strict_types=1);

namespace Toporia\Framework\Encryption;

use Toporia\Framework\Encryption\Contracts\EncrypterInterface;
use Toporia\Framework\Encryption\Exceptions\DecryptException;
use Toporia\Framework\Encryption\Exceptions\EncryptException;

/**
 * Class Encrypter
 *
 * Provides encryption and decryption using OpenSSL with AES-256-GCM.
 * Similar to other frameworks's Encrypter but with modern cipher support.
 *
 * Performance:
 * - Uses AES-256-GCM (authenticated encryption)
 * - Hardware-accelerated when available (AES-NI)
 * - O(n) complexity where n is data size
 *
 * Security:
 * - Authenticated encryption (GCM mode)
 * - Random IV per encryption
 * - Timing-safe comparison
 * - Key rotation support
 *
 * Example:
 * ```php
 * $encrypter = new Encrypter($key); // 32 bytes for AES-256
 *
 * $encrypted = $encrypter->encrypt('secret data');
 * $decrypted = $encrypter->decrypt($encrypted);
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Encryption
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class Encrypter implements EncrypterInterface
{
    /**
     * The encryption key.
     *
     * @var string
     */
    protected string $key;

    /**
     * The cipher algorithm.
     *
     * @var string
     */
    protected string $cipher;

    /**
     * Previous keys for rotation.
     *
     * @var array<string>
     */
    protected array $previousKeys = [];

    /**
     * Supported ciphers and their key lengths.
     *
     * @var array<string, array{size: int, aead: bool}>
     */
    protected static array $supportedCiphers = [
        'aes-128-cbc' => ['size' => 16, 'aead' => false],
        'aes-256-cbc' => ['size' => 32, 'aead' => false],
        'aes-128-gcm' => ['size' => 16, 'aead' => true],
        'aes-256-gcm' => ['size' => 32, 'aead' => true],
    ];

    /**
     * Create a new encrypter instance.
     *
     * @param string $key The encryption key (base64 encoded or raw bytes)
     * @param string $cipher The cipher algorithm
     * @throws \RuntimeException If the cipher is not supported or key is invalid
     */
    public function __construct(string $key, string $cipher = 'aes-256-gcm')
    {
        $key = $this->parseKey($key);
        $cipher = strtolower($cipher);

        if (!$this->supported($key, $cipher)) {
            $ciphers = implode(', ', array_keys(static::$supportedCiphers));

            throw new \RuntimeException(
                "Unsupported cipher or incorrect key length. Supported ciphers are: {$ciphers}."
            );
        }

        $this->key = $key;
        $this->cipher = $cipher;
    }

    /**
     * Parse the encryption key.
     *
     * @param string $key
     * @return string
     */
    protected function parseKey(string $key): string
    {
        // Check if base64 encoded
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false) {
                throw new \RuntimeException('The encryption key could not be decoded.');
            }

            return $decoded;
        }

        return $key;
    }

    /**
     * Check if the key and cipher combination is supported.
     *
     * @param string $key
     * @param string $cipher
     * @return bool
     */
    public static function supported(string $key, string $cipher): bool
    {
        $cipher = strtolower($cipher);

        if (!isset(static::$supportedCiphers[$cipher])) {
            return false;
        }

        return strlen($key) === static::$supportedCiphers[$cipher]['size'];
    }

    /**
     * Generate a new encryption key.
     *
     * @param string $cipher
     * @return string Base64 encoded key
     */
    public static function generateKey(string $cipher = 'aes-256-gcm'): string
    {
        $cipher = strtolower($cipher);

        if (!isset(static::$supportedCiphers[$cipher])) {
            throw new \RuntimeException("Unsupported cipher: {$cipher}");
        }

        $bytes = random_bytes(static::$supportedCiphers[$cipher]['size']);

        return 'base64:' . base64_encode($bytes);
    }

    /**
     * Encrypt the given value.
     *
     * @param mixed $value
     * @param bool $serialize
     * @return string
     * @throws EncryptException
     */
    public function encrypt(mixed $value, bool $serialize = true): string
    {
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher) ?: 16);

        $value = $serialize ? serialize($value) : $value;

        if ($this->isAeadCipher()) {
            return $this->encryptAead($value, $iv);
        }

        return $this->encryptCbc($value, $iv);
    }

    /**
     * Encrypt using AEAD cipher (GCM mode).
     *
     * @param string $value
     * @param string $iv
     * @return string
     * @throws EncryptException
     */
    protected function encryptAead(string $value, string $iv): string
    {
        $tag = '';

        $encrypted = openssl_encrypt(
            $value,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($encrypted === false) {
            throw new EncryptException('Could not encrypt the data.');
        }

        $payload = [
            'iv' => base64_encode($iv),
            'value' => base64_encode($encrypted),
            'tag' => base64_encode($tag),
            'mac' => '', // AEAD doesn't need separate MAC
        ];

        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Encrypt using CBC cipher.
     *
     * @param string $value
     * @param string $iv
     * @return string
     * @throws EncryptException
     */
    protected function encryptCbc(string $value, string $iv): string
    {
        $encrypted = openssl_encrypt(
            $value,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new EncryptException('Could not encrypt the data.');
        }

        $mac = $this->hash($iv . $encrypted);

        $payload = [
            'iv' => base64_encode($iv),
            'value' => base64_encode($encrypted),
            'mac' => $mac,
            'tag' => '',
        ];

        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Encrypt a string without serialization.
     *
     * @param string $value
     * @return string
     */
    public function encryptString(string $value): string
    {
        return $this->encrypt($value, false);
    }

    /**
     * Decrypt the given value.
     *
     * @param string $payload
     * @param bool $unserialize
     * @return mixed
     * @throws DecryptException
     */
    public function decrypt(string $payload, bool $unserialize = true): mixed
    {
        $payload = $this->getJsonPayload($payload);

        $iv = base64_decode($payload['iv'], true);
        $value = base64_decode($payload['value'], true);

        if ($iv === false || $value === false) {
            throw new DecryptException('The payload is invalid.');
        }

        // Try current key first
        $decrypted = $this->decryptPayload($payload, $iv, $value, $this->key);

        // Try previous keys if current fails
        if ($decrypted === null && !empty($this->previousKeys)) {
            foreach ($this->previousKeys as $previousKey) {
                $decrypted = $this->decryptPayload($payload, $iv, $value, $previousKey);

                if ($decrypted !== null) {
                    break;
                }
            }
        }

        if ($decrypted === null) {
            throw new DecryptException('The MAC is invalid.');
        }

        // SECURITY: Restrict unserialize to prevent PHP Object Injection attacks
        // Only allow scalar values by default for encrypted data
        return $unserialize ? unserialize($decrypted, ['allowed_classes' => false]) : $decrypted;
    }

    /**
     * Decrypt a payload with a specific key.
     *
     * @param array<string, string> $payload
     * @param string $iv
     * @param string $value
     * @param string $key
     * @return string|null
     */
    protected function decryptPayload(array $payload, string $iv, string $value, string $key): ?string
    {
        if ($this->isAeadCipher()) {
            $tag = base64_decode($payload['tag'], true);

            if ($tag === false) {
                return null;
            }

            $decrypted = openssl_decrypt(
                $value,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            return $decrypted !== false ? $decrypted : null;
        }

        // CBC mode - verify MAC first
        $mac = $this->hash($iv . $value, $key);

        if (!hash_equals($mac, $payload['mac'])) {
            return null;
        }

        $decrypted = openssl_decrypt(
            $value,
            $this->cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Decrypt a string without unserialization.
     *
     * @param string $payload
     * @return string
     */
    public function decryptString(string $payload): string
    {
        return $this->decrypt($payload, false);
    }

    /**
     * Get the JSON payload from an encrypted string.
     *
     * @param string $payload
     * @return array<string, string>
     * @throws DecryptException
     */
    protected function getJsonPayload(string $payload): array
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new DecryptException('The payload is invalid.');
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DecryptException('The payload is invalid.');
        }

        if (!$this->validPayload($payload)) {
            throw new DecryptException('The payload is invalid.');
        }

        return $payload;
    }

    /**
     * Verify that the payload is valid.
     *
     * @param mixed $payload
     * @return bool
     */
    protected function validPayload(mixed $payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        foreach (['iv', 'value'] as $item) {
            if (!isset($payload[$item]) || !is_string($payload[$item])) {
                return false;
            }
        }

        if ($this->isAeadCipher()) {
            return isset($payload['tag']) && is_string($payload['tag']);
        }

        return isset($payload['mac']) && is_string($payload['mac']);
    }

    /**
     * Create a MAC for the given value.
     *
     * @param string $value
     * @param string|null $key
     * @return string
     */
    protected function hash(string $value, ?string $key = null): string
    {
        return hash_hmac('sha256', $value, $key ?? $this->key);
    }

    /**
     * Check if the cipher is an AEAD cipher.
     *
     * @return bool
     */
    protected function isAeadCipher(): bool
    {
        return static::$supportedCiphers[$this->cipher]['aead'] ?? false;
    }

    /**
     * Get the encryption key.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Get the cipher.
     *
     * @return string
     */
    public function getCipher(): string
    {
        return $this->cipher;
    }

    /**
     * Set previous keys for rotation support.
     *
     * @param array<string> $keys
     * @return static
     */
    public function previousKeys(array $keys): static
    {
        $this->previousKeys = array_map(
            fn($key) => $this->parseKey($key),
            $keys
        );

        return $this;
    }

    /**
     * Get supported ciphers.
     *
     * @return array<string>
     */
    public static function getSupportedCiphers(): array
    {
        return array_keys(static::$supportedCiphers);
    }
}
