<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Ulid
 *
 * Validates that the value is a valid ULID (Universally Unique Lexicographically Sortable Identifier).
 *
 * ULID format: 26 characters using Crockford's Base32 alphabet
 *
 * Performance: O(1) - Pattern matching and character validation
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
final class Ulid implements RuleInterface
{
    /**
     * ULID regex pattern.
     * 26 characters using Crockford's Base32 (0-9, A-Z except I, L, O, U).
     */
    private const ULID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i';

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

        if (strlen($value) !== 26) {
            return false;
        }

        return preg_match(self::ULID_PATTERN, $value) === 1;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute must be a valid ULID.";
    }
}
