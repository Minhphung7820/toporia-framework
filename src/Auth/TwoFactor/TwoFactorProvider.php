<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\TwoFactor;

/**
 * Class TwoFactorProvider
 *
 * Provides TOTP (Time-based One-Time Password) two-factor authentication.
 * Compatible with Google Authenticator, Authy, Microsoft Authenticator, etc.
 *
 * Based on RFC 6238 (TOTP) and RFC 4226 (HOTP).
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\TwoFactor
 * @since       2025-01-15
 *
 * @link        https://github.com/Minhphung7820/toporia
 * @link        https://tools.ietf.org/html/rfc6238
 */
final class TwoFactorProvider
{
    /**
     * Time window in seconds (30 seconds standard).
     */
    private const TIME_WINDOW = 30;

    /**
     * Number of digits in OTP code (6 or 8).
     */
    private const CODE_LENGTH = 6;

    /**
     * Recovery code length.
     */
    private const RECOVERY_CODE_LENGTH = 8;

    /**
     * Number of recovery codes to generate.
     */
    private const RECOVERY_CODE_COUNT = 8;

    /**
     * Generate a new secret key for TOTP.
     *
     * @return string Base32-encoded secret (160 bits)
     */
    public function generateSecret(): string
    {
        // Generate 20 bytes (160 bits) of random data
        $secret = random_bytes(20);

        // Encode to Base32 (RFC 4648)
        return $this->base32Encode($secret);
    }

    /**
     * Generate recovery codes.
     *
     * @return array<string> Array of recovery codes
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = $this->generateRecoveryCode();
        }

        return $codes;
    }

    /**
     * Generate a single recovery code.
     *
     * @return string Recovery code (format: XXXX-XXXX)
     */
    private function generateRecoveryCode(): string
    {
        $bytes = random_bytes(self::RECOVERY_CODE_LENGTH / 2);
        $hex = strtoupper(bin2hex($bytes));

        // Format: XXXX-XXXX
        return substr($hex, 0, 4) . '-' . substr($hex, 4, 4);
    }

    /**
     * Verify a TOTP code.
     *
     * @param string $secret Base32-encoded secret
     * @param string $code User-provided code
     * @param int $window Time window tolerance (± windows * 30 seconds)
     * @return bool True if code is valid
     */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        // Remove spaces and dashes from code
        $code = str_replace([' ', '-'], '', $code);

        if (strlen($code) !== self::CODE_LENGTH) {
            return false;
        }

        $currentTime = time();

        // Check current window and adjacent windows
        for ($i = -$window; $i <= $window; $i++) {
            $timestamp = $currentTime + ($i * self::TIME_WINDOW);
            $expectedCode = $this->generateCode($secret, $timestamp);

            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate TOTP code for a given timestamp.
     *
     * @param string $secret Base32-encoded secret
     * @param int|null $timestamp Unix timestamp (null = current time)
     * @return string 6-digit code
     */
    public function generateCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();

        // Calculate counter (number of time windows since Unix epoch)
        $counter = (int) floor($timestamp / self::TIME_WINDOW);

        // Decode secret from Base32
        $secretBinary = $this->base32Decode($secret);

        // Generate HOTP code
        return $this->generateHOTP($secretBinary, $counter);
    }

    /**
     * Generate QR code URL for Google Authenticator.
     *
     * @param string $email User email/identifier
     * @param string $secret Base32-encoded secret
     * @param string $issuer Application name
     * @return string QR code URL
     */
    public function getQRCodeUrl(string $email, string $secret, string $issuer = 'Toporia'): string
    {
        $otpauthUrl = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($email),
            $secret,
            rawurlencode($issuer),
            self::CODE_LENGTH,
            self::TIME_WINDOW
        );

        // Return URL for QR code generation (can use any QR code service)
        return sprintf(
            'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=%s',
            urlencode($otpauthUrl)
        );
    }

    /**
     * Generate HOTP (HMAC-based One-Time Password).
     *
     * @param string $secret Binary secret
     * @param int $counter Counter value
     * @return string Numeric code
     */
    private function generateHOTP(string $secret, int $counter): string
    {
        // Pack counter as 64-bit big-endian
        $counterBytes = pack('J', $counter);

        // HMAC-SHA1
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);

        // Dynamic truncation (RFC 4226 section 5.4)
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        // Modulo to get desired length
        $code = $code % (10 ** self::CODE_LENGTH);

        // Pad with leading zeros
        return str_pad((string) $code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Encode binary data to Base32 (RFC 4648).
     *
     * @param string $data Binary data
     * @return string Base32-encoded string
     */
    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result = '';
        $buffer = 0;
        $bufferLength = 0;

        for ($i = 0; $i < strlen($data); $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bufferLength += 8;

            while ($bufferLength >= 5) {
                $bufferLength -= 5;
                $index = ($buffer >> $bufferLength) & 0x1F;
                $result .= $alphabet[$index];
            }
        }

        if ($bufferLength > 0) {
            $index = ($buffer << (5 - $bufferLength)) & 0x1F;
            $result .= $alphabet[$index];
        }

        return $result;
    }

    /**
     * Decode Base32 to binary data (RFC 4648).
     *
     * @param string $data Base32-encoded string
     * @return string Binary data
     */
    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper($data);
        $result = '';
        $buffer = 0;
        $bufferLength = 0;

        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $pos = strpos($alphabet, $char);

            if ($pos === false) {
                continue; // Skip invalid characters
            }

            $buffer = ($buffer << 5) | $pos;
            $bufferLength += 5;

            if ($bufferLength >= 8) {
                $bufferLength -= 8;
                $result .= chr(($buffer >> $bufferLength) & 0xFF);
            }
        }

        return $result;
    }
}
