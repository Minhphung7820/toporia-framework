<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class MacAddress
 *
 * Validates that the value is a valid MAC address.
 *
 * Supports formats:
 *   - XX:XX:XX:XX:XX:XX (colon separated)
 *   - XX-XX-XX-XX-XX-XX (hyphen separated)
 *   - XXXX.XXXX.XXXX (dot notation)
 *
 * Performance: O(1) - Regex pattern matching
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MacAddress implements RuleInterface
{
    /**
     * MAC address patterns.
     */
    private const PATTERNS = [
        // XX:XX:XX:XX:XX:XX
        '/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/',
        // XX-XX-XX-XX-XX-XX
        '/^([0-9A-Fa-f]{2}-){5}[0-9A-Fa-f]{2}$/',
        // XXXX.XXXX.XXXX (Cisco format)
        '/^([0-9A-Fa-f]{4}\.){2}[0-9A-Fa-f]{4}$/',
    ];

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute The attribute name being validated
     * @param mixed $value The value being validated
     * @return bool
     */
    public function passes(string $attribute, mixed $value): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute must be a valid MAC address.";
    }
}
