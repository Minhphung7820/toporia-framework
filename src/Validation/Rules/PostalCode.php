<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\Rules\Concerns\ReplacesAttributes;

/**
 * Class PostalCode
 *
 * Validates that the value is a valid postal/zip code.
 *
 * Supports:
 * - Generic validation (alphanumeric, 3-10 characters)
 * - Country-specific patterns (US, UK, CA, etc.)
 *
 * Performance: O(1) - Single regex match
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 */
final class PostalCode implements RuleInterface
{
    use ReplacesAttributes;

    /**
     * Country-specific postal code patterns.
     */
    private const COUNTRY_PATTERNS = [
        'US' => '/^\d{5}(-\d{4})?$/',                           // 12345 or 12345-6789
        'UK' => '/^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/i',      // SW1A 1AA
        'CA' => '/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/i',               // K1A 0B1
        'DE' => '/^\d{5}$/',                                    // 12345
        'FR' => '/^\d{5}$/',                                    // 75001
        'AU' => '/^\d{4}$/',                                    // 2000
        'JP' => '/^\d{3}-?\d{4}$/',                            // 123-4567 or 1234567
        'BR' => '/^\d{5}-?\d{3}$/',                            // 12345-678
        'IN' => '/^\d{6}$/',                                    // 110001
        'NL' => '/^\d{4}\s?[A-Z]{2}$/i',                       // 1234 AB
        'IT' => '/^\d{5}$/',                                    // 00100
        'ES' => '/^\d{5}$/',                                    // 28001
        'VN' => '/^\d{6}$/',                                    // 700000
    ];

    /**
     * @param string|null $country ISO 3166-1 alpha-2 country code (e.g., 'US', 'UK')
     */
    public function __construct(
        private readonly ?string $country = null
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

        $postalCode = (string) $value;

        // Use country-specific pattern if provided
        if ($this->country !== null) {
            $countryUpper = strtoupper($this->country);
            if (isset(self::COUNTRY_PATTERNS[$countryUpper])) {
                return preg_match(self::COUNTRY_PATTERNS[$countryUpper], $postalCode) === 1;
            }
        }

        // Generic validation: alphanumeric with optional spaces/dashes, 3-10 characters
        return preg_match('/^[A-Z0-9\s\-]{3,10}$/i', $postalCode) === 1;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        if ($this->country !== null) {
            return "The :attribute must be a valid {$this->country} postal code.";
        }

        return 'The :attribute must be a valid postal code.';
    }
}
