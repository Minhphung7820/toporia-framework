<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class Lt
 *
 * Validates that the value is less than another field's value.
 *
 * Performance: O(1) - Numeric comparison
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
final class Lt implements DataAwareRuleInterface
{
    private ?ValidationData $data = null;

    /**
     * @param string $field Field to compare against
     */
    public function __construct(
        private readonly string $field
    ) {}

    /**
     * Set validation data.
     *
     * @param ValidationData $data All validation data
     * @return void
     */
    public function setData(ValidationData $data): void
    {
        $this->data = $data;
    }

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

        $otherValue = $this->data?->get($this->field);

        if ($otherValue === null) {
            return false;
        }

        return $this->compare($value, $otherValue);
    }

    /**
     * Compare two values.
     *
     * @param mixed $value Current value
     * @param mixed $other Other value
     * @return bool
     */
    private function compare(mixed $value, mixed $other): bool
    {
        // Numeric comparison
        if (is_numeric($value) && is_numeric($other)) {
            return (float) $value < (float) $other;
        }

        // String length comparison
        if (is_string($value) && is_string($other)) {
            return mb_strlen($value) < mb_strlen($other);
        }

        // Array count comparison
        if (is_array($value) && is_array($other)) {
            return count($value) < count($other);
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
        return "The :attribute must be less than {$this->field}.";
    }
}
