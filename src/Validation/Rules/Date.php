<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Date
 *
 * Validates that the value is a valid date in the specified format.
 * Default format: Y-m-d (e.g., 2025-01-15)
 *
 * Performance: O(1) - Single DateTime::createFromFormat call
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
final class Date implements RuleInterface
{
    /**
     * @param string|null $format Date format (PHP date format), null for any parseable date
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

        if (!is_string($value)) {
            return false;
        }

        // If format is specified, use strict format validation
        if ($this->format !== null) {
            $date = \DateTime::createFromFormat($this->format, $value);
            return $date !== false && $date->format($this->format) === $value;
        }

        // Otherwise, check if it's any valid parseable date
        try {
            $date = new \DateTime($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        if ($this->format !== null) {
            return "The :attribute must be a valid date in format {$this->format}.";
        }
        return "The :attribute must be a valid date.";
    }
}
