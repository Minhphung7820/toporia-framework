<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\Rules\Concerns\ReplacesAttributes;

/**
 * Class Phone
 *
 * Validates that the value is a valid phone number.
 *
 * Supports:
 * - International format (+1234567890)
 * - Local formats with various separators
 * - Configurable minimum digit count
 *
 * Performance: O(n) where n = string length
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 */
final class Phone implements RuleInterface
{
    use ReplacesAttributes;

    /**
     * @param int $minDigits Minimum number of digits required
     * @param int $maxDigits Maximum number of digits allowed
     * @param bool $requireCountryCode Whether to require country code (+)
     */
    public function __construct(
        private readonly int $minDigits = 10,
        private readonly int $maxDigits = 15,
        private readonly bool $requireCountryCode = false
    ) {}

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

        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        $phone = (string) $value;

        // Check if country code is required
        if ($this->requireCountryCode && !str_starts_with($phone, '+')) {
            return false;
        }

        // Allow: digits, spaces, dashes, parentheses, plus sign, dots
        if (!preg_match('/^[\d\s\-\+\(\)\.]+$/', $phone)) {
            return false;
        }

        // Extract digits only
        $digits = preg_replace('/\D/', '', $phone);
        $digitCount = strlen($digits);

        // Check digit count range
        return $digitCount >= $this->minDigits && $digitCount <= $this->maxDigits;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return 'The :attribute must be a valid phone number.';
    }
}
