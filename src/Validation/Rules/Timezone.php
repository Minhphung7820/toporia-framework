<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Timezone
 *
 * Validates that the value is a valid timezone identifier.
 *
 * Performance: O(1) - Timezone lookup (with static caching)
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
final class Timezone implements RuleInterface
{
    /**
     * @var array<string, true>|null Cached valid timezones
     */
    private static ?array $timezones = null;

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

        return $this->isValidTimezone($value);
    }

    /**
     * Check if the value is a valid timezone.
     *
     * @param string $value Timezone identifier
     * @return bool
     */
    private function isValidTimezone(string $value): bool
    {
        if (self::$timezones === null) {
            self::$timezones = array_fill_keys(
                timezone_identifiers_list(),
                true
            );
        }

        return isset(self::$timezones[$value]);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute must be a valid timezone.";
    }
}
