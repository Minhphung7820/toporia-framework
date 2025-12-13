<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\Contracts\ImplicitRuleInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class RequiredIf
 *
 * Validates that the field is required when another field equals a specific value.
 *
 * Usage:
 *   new RequiredIf('payment_type', 'credit_card')
 *   new RequiredIf('payment_type', ['credit_card', 'debit_card'])
 *
 * Performance: O(1) - Field lookup and comparison
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
final class RequiredIf implements DataAwareRuleInterface, ImplicitRuleInterface
{
    private ?ValidationData $data = null;

    /**
     * @var array<mixed> Values that trigger the required condition
     */
    private readonly array $values;

    /**
     * @param string $field The field to check
     * @param mixed $values Value(s) that make this field required
     */
    public function __construct(
        private readonly string $field,
        mixed $values
    ) {
        $this->values = is_array($values) ? $values : [$values];
    }

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
        // If condition is not met, always pass
        if (!$this->conditionMet()) {
            return true;
        }

        // Condition is met - field is required
        return $this->hasValue($value);
    }

    /**
     * Check if the condition is met (other field has specific value).
     *
     * @return bool
     */
    private function conditionMet(): bool
    {
        if ($this->data === null) {
            return false;
        }

        $otherValue = $this->data->get($this->field);

        foreach ($this->values as $expectedValue) {
            // Handle boolean comparisons
            if (is_bool($expectedValue)) {
                if ($this->toBool($otherValue) === $expectedValue) {
                    return true;
                }
                continue;
            }

            // Loose comparison for flexibility
            if ($otherValue == $expectedValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert value to boolean.
     *
     * @param mixed $value Value to convert
     * @return bool|null
     */
    private function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === '1' || $value === 1 || $value === 'true' || $value === 'yes') {
            return true;
        }

        if ($value === '0' || $value === 0 || $value === 'false' || $value === 'no') {
            return false;
        }

        return null;
    }

    /**
     * Check if value is present.
     *
     * @param mixed $value Value to check
     * @return bool
     */
    private function hasValue(mixed $value): bool
    {
        if (is_null($value)) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_array($value) && count($value) === 0) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        $valuesStr = implode(', ', array_map(
            fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
            $this->values
        ));

        return "The :attribute field is required when {$this->field} is {$valuesStr}.";
    }
}
