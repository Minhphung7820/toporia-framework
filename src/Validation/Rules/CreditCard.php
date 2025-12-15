<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\Rules\Concerns\ReplacesAttributes;

/**
 * Class CreditCard
 *
 * Validates that the value is a valid credit card number using Luhn algorithm.
 *
 * Supports validation of:
 * - Visa, MasterCard, American Express, Discover, etc.
 * - Numbers with or without spaces/dashes
 *
 * Performance: O(n) where n = number of digits
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 */
final class CreditCard implements RuleInterface
{
    use ReplacesAttributes;

    /**
     * Card type patterns for optional type validation.
     */
    private const CARD_PATTERNS = [
        'visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
        'mastercard' => '/^5[1-5][0-9]{14}$|^2(?:2(?:2[1-9]|[3-9][0-9])|[3-6][0-9][0-9]|7(?:[01][0-9]|20))[0-9]{12}$/',
        'amex' => '/^3[47][0-9]{13}$/',
        'discover' => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
        'diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
        'jcb' => '/^(?:2131|1800|35\d{3})\d{11}$/',
    ];

    /**
     * @param string|null $type Optional card type to validate against
     */
    public function __construct(
        private readonly ?string $type = null
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

        // Remove all non-digit characters
        $number = preg_replace('/\D/', '', (string) $value);

        // Check length (13-19 digits)
        $length = strlen($number);
        if ($length < 13 || $length > 19) {
            return false;
        }

        // Validate card type if specified
        if ($this->type !== null) {
            $typeLower = strtolower($this->type);
            if (isset(self::CARD_PATTERNS[$typeLower])) {
                if (!preg_match(self::CARD_PATTERNS[$typeLower], $number)) {
                    return false;
                }
            }
        }

        // Luhn algorithm validation
        return $this->luhnCheck($number);
    }

    /**
     * Validate using Luhn algorithm (mod 10).
     *
     * @param string $number Credit card number (digits only)
     * @return bool
     */
    private function luhnCheck(string $number): bool
    {
        $sum = 0;
        $alternate = false;
        $length = strlen($number);

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $number[$i];

            if ($alternate) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit = ($digit % 10) + 1;
                }
            }

            $sum += $digit;
            $alternate = !$alternate;
        }

        return $sum % 10 === 0;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        if ($this->type !== null) {
            return "The :attribute must be a valid {$this->type} credit card number.";
        }

        return 'The :attribute must be a valid credit card number.';
    }
}
