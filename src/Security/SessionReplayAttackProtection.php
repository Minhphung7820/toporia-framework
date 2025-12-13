<?php

declare(strict_types=1);

namespace Toporia\Framework\Security;

use Toporia\Framework\Security\Contracts\ReplayAttackProtectionInterface;
use Toporia\Framework\Session\Store;

/**
 * Class SessionReplayAttackProtection
 *
 * Prevents replay attacks by generating unique nonces with timestamps,
 * storing used nonces in session, and validating nonce expiration.
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
final class SessionReplayAttackProtection implements ReplayAttackProtectionInterface
{
    private const SESSION_KEY_PREFIX = '_replay_nonce_';
    private const NONCE_SEPARATOR = ':';
    private const DEFAULT_TTL = 300; // 5 minutes

    public function __construct(
        private Store $session
    ) {}

    /**
     * Generate a new nonce with timestamp.
     *
     * Format: {timestamp}:{random_token}
     * Example: "1700000000:a1b2c3d4e5f6..."
     *
     * Performance: O(1) - Direct token generation
     *
     * @param int $ttl Time-to-live in seconds
     * @return string Nonce token
     */
    public function generateNonce(int $ttl = self::DEFAULT_TTL): string
    {
        $timestamp = now()->getTimestamp() + $ttl;
        $token = $this->generateRandomToken();
        $nonce = $timestamp . self::NONCE_SEPARATOR . $token;

        return $nonce;
    }

    /**
     * Validate a nonce and mark it as used.
     *
     * Validation steps:
     * 1. Parse nonce format (timestamp:token)
     * 2. Check if nonce has expired
     * 3. Check if nonce has been used before
     * 4. Mark nonce as used if valid
     *
     * Performance: O(1) - Session array lookup
     *
     * @param string $nonce The nonce to validate
     * @return bool True if valid and not used, false otherwise
     */
    public function validateNonce(string $nonce): bool
    {
        // Check format
        if (!$this->isValidFormat($nonce)) {
            return false;
        }

        // Parse nonce
        [$timestamp, $token] = $this->parseNonce($nonce);

        // Check expiration
        if ($timestamp < now()->getTimestamp()) {
            return false; // Expired
        }

        // Check if already used
        if ($this->isNonceUsed($token)) {
            return false; // Already used (replay attack detected)
        }

        // Mark as used
        $this->markNonceAsUsed($token, $timestamp);

        return true;
    }

    /**
     * Check if a nonce is valid without consuming it.
     *
     * Performance: O(1) - Session array lookup
     *
     * @param string $nonce The nonce to check
     * @return bool True if valid, false otherwise
     */
    public function isValidNonce(string $nonce): bool
    {
        // Check format
        if (!$this->isValidFormat($nonce)) {
            return false;
        }

        // Parse nonce
        [$timestamp, $token] = $this->parseNonce($nonce);

        // Check expiration
        if ($timestamp < now()->getTimestamp()) {
            return false; // Expired
        }

        // Check if already used
        if ($this->isNonceUsed($token)) {
            return false; // Already used
        }

        return true;
    }

    /**
     * Clean up expired nonces from session.
     *
     * Performance: O(N) where N = number of stored nonces
     *
     * @return int Number of nonces cleaned up
     */
    public function cleanupExpired(): int
    {
        $cleaned = 0;
        $currentTime = now()->getTimestamp();

        // Get all session data
        $allData = $this->session->all();
        $nonceKeys = array_filter(array_keys($allData), function ($key) {
            return str_starts_with($key, self::SESSION_KEY_PREFIX);
        });

        foreach ($nonceKeys as $key) {
            $nonceData = $allData[$key] ?? null;

            if ($nonceData === null) {
                continue;
            }

            // Check if expired
            if (isset($nonceData['expires_at']) && $nonceData['expires_at'] < $currentTime) {
                $this->session->remove($key);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Check if nonce format is valid.
     *
     * Performance: O(1) - String operations
     *
     * @param string $nonce
     * @return bool
     */
    private function isValidFormat(string $nonce): bool
    {
        return str_contains($nonce, self::NONCE_SEPARATOR) && substr_count($nonce, self::NONCE_SEPARATOR) === 1;
    }

    /**
     * Parse nonce into timestamp and token.
     *
     * Performance: O(1) - String split
     *
     * @param string $nonce
     * @return array{0: int, 1: string}
     */
    private function parseNonce(string $nonce): array
    {
        $parts = explode(self::NONCE_SEPARATOR, $nonce, 2);
        $timestamp = (int) $parts[0];
        $token = $parts[1] ?? '';

        return [$timestamp, $token];
    }

    /**
     * Check if nonce has been used before.
     *
     * Performance: O(1) - Session array lookup
     *
     * @param string $token
     * @return bool
     */
    private function isNonceUsed(string $token): bool
    {
        $sessionKey = $this->getSessionKey($token);
        return $this->session->has($sessionKey);
    }

    /**
     * Mark nonce as used.
     *
     * Performance: O(1) - Session array assignment
     *
     * @param string $token
     * @param int $expiresAt
     * @return void
     */
    private function markNonceAsUsed(string $token, int $expiresAt): void
    {
        $sessionKey = $this->getSessionKey($token);
        $this->session->set($sessionKey, [
            'used_at' => now()->getTimestamp(),
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Generate a cryptographically secure random token.
     *
     * Performance: O(1) - Direct random generation
     *
     * @return string
     */
    private function generateRandomToken(): string
    {
        return bin2hex(random_bytes(32)); // 64 character hex string
    }

    /**
     * Get the session key for a given token.
     *
     * Performance: O(1) - String concatenation
     *
     * @param string $token
     * @return string
     */
    private function getSessionKey(string $token): string
    {
        return self::SESSION_KEY_PREFIX . hash('sha256', $token);
    }
}
