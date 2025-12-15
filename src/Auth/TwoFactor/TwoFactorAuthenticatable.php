<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\TwoFactor;

/**
 * Trait TwoFactorAuthenticatable
 *
 * Adds two-factor authentication capabilities to any authenticatable model.
 *
 * Usage:
 * ```php
 * class User extends Model implements Authenticatable {
 *     use TwoFactorAuthenticatable;
 * }
 * ```
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
 */
trait TwoFactorAuthenticatable
{
    /**
     * Get the two-factor secret key.
     *
     * @return string|null
     */
    public function getTwoFactorSecret(): ?string
    {
        return $this->two_factor_secret ?? null;
    }

    /**
     * Set the two-factor secret key.
     *
     * @param string $secret
     * @return void
     */
    public function setTwoFactorSecret(string $secret): void
    {
        $this->two_factor_secret = $secret;
    }

    /**
     * Get two-factor recovery codes.
     *
     * @return array<string>
     */
    public function getTwoFactorRecoveryCodes(): array
    {
        if (empty($this->two_factor_recovery_codes)) {
            return [];
        }

        $decoded = json_decode($this->two_factor_recovery_codes, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set two-factor recovery codes.
     *
     * @param array<string> $codes
     * @return void
     */
    public function setTwoFactorRecoveryCodes(array $codes): void
    {
        $this->two_factor_recovery_codes = json_encode($codes);
    }

    /**
     * Check if two-factor authentication is enabled.
     *
     * @return bool
     */
    public function hasTwoFactorEnabled(): bool
    {
        return !empty($this->two_factor_secret)
            && !empty($this->two_factor_confirmed_at);
    }

    /**
     * Enable two-factor authentication.
     *
     * @return void
     */
    public function enableTwoFactor(): void
    {
        $this->two_factor_confirmed_at = date('Y-m-d H:i:s');
    }

    /**
     * Disable two-factor authentication.
     *
     * @return void
     */
    public function disableTwoFactor(): void
    {
        $this->two_factor_secret = null;
        $this->two_factor_recovery_codes = null;
        $this->two_factor_confirmed_at = null;
    }

    /**
     * Replace a used recovery code with null.
     *
     * @param string $code
     * @return bool True if code was found and replaced
     */
    public function replaceRecoveryCode(string $code): bool
    {
        $codes = $this->getTwoFactorRecoveryCodes();

        $key = array_search($code, $codes, true);
        if ($key === false) {
            return false;
        }

        $codes[$key] = null;
        $this->setTwoFactorRecoveryCodes($codes);

        return true;
    }

    /**
     * Get remaining recovery codes (non-null).
     *
     * @return array<string>
     */
    public function getRemainingRecoveryCodes(): array
    {
        return array_filter($this->getTwoFactorRecoveryCodes(), fn($code) => $code !== null);
    }
}
