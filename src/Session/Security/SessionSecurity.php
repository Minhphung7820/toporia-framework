<?php

declare(strict_types=1);

namespace Toporia\Framework\Session\Security;

use Toporia\Framework\Session\Contracts\SessionStoreInterface;

/**
 * Class SessionSecurity
 *
 * Enhances session security with rotation, IP binding, device fingerprinting, and timeout enforcement.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Session\Security
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SessionSecurity
{
    /**
     * Session key for IP address.
     */
    private const KEY_IP = '_security.ip';

    /**
     * Session key for device fingerprint.
     */
    private const KEY_FINGERPRINT = '_security.fingerprint';

    /**
     * Session key for last rotation time.
     */
    private const KEY_LAST_ROTATION = '_security.last_rotation';

    /**
     * Session key for created time.
     */
    private const KEY_CREATED_AT = '_security.created_at';

    /**
     * @param SessionStoreInterface $session Session store
     * @param bool $enableIpBinding Enable IP binding
     * @param bool $enableFingerprinting Enable device fingerprinting
     * @param int $rotationInterval Session rotation interval in seconds (0 = disable)
     * @param int $maxLifetime Maximum session lifetime in seconds (0 = no limit)
     */
    public function __construct(
        private SessionStoreInterface $session,
        private bool $enableIpBinding = true,
        private bool $enableFingerprinting = true,
        private int $rotationInterval = 300, // 5 minutes
        private int $maxLifetime = 0 // No limit by default
    ) {}

    /**
     * Initialize session security (call on session start).
     *
     * @return void
     */
    public function initialize(): void
    {
        // Store creation time if not exists
        if (!$this->session->has(self::KEY_CREATED_AT)) {
            $this->session->put(self::KEY_CREATED_AT, now()->getTimestamp());
        }

        // Store IP if enabled
        if ($this->enableIpBinding) {
            $this->storeIp();
        }

        // Store fingerprint if enabled
        if ($this->enableFingerprinting) {
            $this->storeFingerprint();
        }

        // Rotate session if needed
        if ($this->rotationInterval > 0) {
            $this->rotateIfNeeded();
        }

        // Check session lifetime
        if ($this->maxLifetime > 0) {
            $this->checkLifetime();
        }
    }

    /**
     * Validate session security (call on each request).
     *
     * @return bool True if session is secure
     * @throws \RuntimeException If security check fails
     */
    public function validate(): bool
    {
        // Check IP binding
        if ($this->enableIpBinding && !$this->validateIp()) {
            throw new \RuntimeException('Session IP address mismatch');
        }

        // Check device fingerprint
        if ($this->enableFingerprinting && !$this->validateFingerprint()) {
            throw new \RuntimeException('Session device fingerprint mismatch');
        }

        // Check session lifetime
        if ($this->maxLifetime > 0 && !$this->checkLifetime()) {
            throw new \RuntimeException('Session has expired');
        }

        return true;
    }

    /**
     * Store current IP address in session.
     *
     * @return void
     */
    private function storeIp(): void
    {
        $ip = $this->getClientIp();
        if ($ip !== null) {
            $this->session->put(self::KEY_IP, $ip);
        }
    }

    /**
     * Validate IP address matches session.
     *
     * @return bool True if IP matches
     */
    private function validateIp(): bool
    {
        $storedIp = $this->session->get(self::KEY_IP);
        $currentIp = $this->getClientIp();

        if ($storedIp === null || $currentIp === null) {
            return true; // Allow if IP not available
        }

        return $storedIp === $currentIp;
    }

    /**
     * Store device fingerprint in session.
     *
     * @return void
     */
    private function storeFingerprint(): void
    {
        if (!$this->session->has(self::KEY_FINGERPRINT)) {
            $fingerprint = $this->generateFingerprint();
            $this->session->put(self::KEY_FINGERPRINT, $fingerprint);
        }
    }

    /**
     * Validate device fingerprint matches session.
     *
     * @return bool True if fingerprint matches
     */
    private function validateFingerprint(): bool
    {
        $storedFingerprint = $this->session->get(self::KEY_FINGERPRINT);
        $currentFingerprint = $this->generateFingerprint();

        if ($storedFingerprint === null) {
            return true; // Allow if fingerprint not set
        }

        return $storedFingerprint === $currentFingerprint;
    }

    /**
     * Generate device fingerprint from user agent and other headers.
     *
     * @return string Fingerprint hash
     */
    private function generateFingerprint(): string
    {
        $components = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Rotate session ID if interval has passed.
     *
     * @return void
     */
    private function rotateIfNeeded(): void
    {
        $lastRotation = $this->session->get(self::KEY_LAST_ROTATION, 0);
        $now = now()->getTimestamp();

        if ($now - $lastRotation >= $this->rotationInterval) {
            $this->session->regenerate();
            $this->session->put(self::KEY_LAST_ROTATION, $now);
        }
    }

    /**
     * Check if session has exceeded maximum lifetime.
     *
     * @return bool True if session is still valid
     */
    private function checkLifetime(): bool
    {
        $createdAt = $this->session->get(self::KEY_CREATED_AT, 0);
        $now = now()->getTimestamp();

        if ($createdAt > 0 && ($now - $createdAt) > $this->maxLifetime) {
            $this->session->flush();
            return false;
        }

        return true;
    }

    /**
     * Get client IP address.
     *
     * @return string|null IP address or null
     */
    private function getClientIp(): ?string
    {
        // Check various headers for real IP (behind proxy/load balancer)
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle X-Forwarded-For (can contain multiple IPs)
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }
}
