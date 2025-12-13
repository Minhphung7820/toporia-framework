<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Size
 *
 * Validates exact size/length constraint.
 * - For strings: exact character length
 * - For numerics: exact value
 * - For arrays: exact item count
 * - For files: exact file size
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
final class Size implements RuleInterface
{
    /**
     * @param int|float $size Expected size
     */
    public function __construct(
        private readonly int|float $size
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
            return (float) $value == $this->size;
        }

        if (is_string($value)) {
            return mb_strlen($value) == $this->size;
        }

        if (is_array($value)) {
            return count($value) == $this->size;
        }

        // File object with getSize method
        if (is_object($value) && method_exists($value, 'getSize')) {
            return $value->getSize() == $this->size;
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
        return "The :attribute must be {$this->size}.";
    }
}
