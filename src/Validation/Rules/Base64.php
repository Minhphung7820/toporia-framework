<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\Rules\Concerns\ReplacesAttributes;

/**
 * Class Base64
 *
 * Validates that the value is a valid base64 encoded string.
 *
 * Validates by:
 * 1. Checking if string can be decoded
 * 2. Verifying the re-encoded value matches original
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
final class Base64 implements RuleInterface
{
    use ReplacesAttributes;

    /**
     * @param bool $strict Use strict mode (reject invalid characters)
     */
    public function __construct(
        private readonly bool $strict = true
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

        if (!is_string($value)) {
            return false;
        }

        // Decode with strict mode
        $decoded = base64_decode($value, $this->strict);

        if ($decoded === false) {
            return false;
        }

        // Verify by re-encoding
        return base64_encode($decoded) === $value;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return 'The :attribute must be a valid base64 encoded string.';
    }
}
