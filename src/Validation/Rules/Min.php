<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Min
 *
 * Validates minimum value/length constraint.
 * - For strings: minimum character length (mb_strlen)
 * - For numerics: minimum value
 * - For arrays: minimum item count
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
final class Min implements RuleInterface
{
    /**
     * @param int|float $min Minimum value/length
     */
    public function __construct(
        private readonly int|float $min
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
            return (float) $value >= $this->min;
        }

        if (is_string($value)) {
            return mb_strlen($value) >= $this->min;
        }

        if (is_array($value)) {
            return count($value) >= $this->min;
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
        return "The :attribute must be at least {$this->min}.";
    }

    /**
     * Get the minimum value.
     *
     * @return int|float
     */
    public function getMin(): int|float
    {
        return $this->min;
    }
}
