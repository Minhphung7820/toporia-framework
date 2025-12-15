<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\Rules\Concerns\ReplacesAttributes;

/**
 * Class DateTime
 *
 * Validates that the value is a valid date-time.
 *
 * Uses PHP's strtotime() or DateTime::createFromFormat() for flexible parsing.
 *
 * Performance: O(1) - Single parse attempt
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 */
final class DateTime implements RuleInterface
{
    use ReplacesAttributes;

    /**
     * @param string|null $format Specific format to validate against
     */
    public function __construct(
        private readonly ?string $format = null
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

        if ($value instanceof \DateTimeInterface) {
            return true;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        $value = (string) $value;

        // If specific format is provided, use createFromFormat
        if ($this->format !== null) {
            $date = \DateTime::createFromFormat($this->format, $value);
            return $date !== false && $date->format($this->format) === $value;
        }

        // Try Chronos if available
        if (class_exists(\Toporia\Framework\DateTime\Chronos::class)) {
            try {
                \Toporia\Framework\DateTime\Chronos::parse($value);
                return true;
            } catch (\Exception) {
                return false;
            }
        }

        // Fallback to strtotime
        return strtotime($value) !== false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        if ($this->format !== null) {
            return "The :attribute must be a valid date-time in the format {$this->format}.";
        }

        return 'The :attribute must be a valid date and time.';
    }
}
