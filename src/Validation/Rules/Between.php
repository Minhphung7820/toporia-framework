<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Between
 *
 * Validates that value is between min and max (inclusive).
 * - For strings: character length between min and max
 * - For numerics: value between min and max
 * - For arrays: item count between min and max
 *
 * Performance: O(n) for strings where n = string length, O(1) for others
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
final class Between implements RuleInterface
{
    /**
     * @param int|float $min Minimum value/length
     * @param int|float $max Maximum value/length
     */
    public function __construct(
        private readonly int|float $min,
        private readonly int|float $max
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

        if (is_numeric($value)) {
            $numValue = (float) $value;
            return $numValue >= $this->min && $numValue <= $this->max;
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            return $length >= $this->min && $length <= $this->max;
        }

        if (is_array($value)) {
            $count = count($value);
            return $count >= $this->min && $count <= $this->max;
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
        return "The :attribute must be between {$this->min} and {$this->max}.";
    }
}
