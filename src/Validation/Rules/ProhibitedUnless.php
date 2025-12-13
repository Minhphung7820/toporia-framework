<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\Contracts\ImplicitRuleInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class ProhibitedUnless
 *
 * Validates that the field is empty unless another field equals a specific value.
 *
 * Performance: O(1) - Value comparison
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
final class ProhibitedUnless implements DataAwareRuleInterface, ImplicitRuleInterface
{
    private ?ValidationData $data = null;

    /**
     * @var array<mixed> Values that allow this field
     */
    private readonly array $values;

    /**
     * @param string $field Field to check
     * @param mixed $values Value(s) that allow this field
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
        // If exemption condition is met, always pass
        if ($this->exemptionMet()) {
            return true;
        }

        // Exemption not met - field must be empty
        return $this->isEmpty($value);
    }

    /**
     * Check if the exemption condition is met.
     *
     * @return bool
     */
    private function exemptionMet(): bool
    {
        if ($this->data === null) {
            return false;
        }

        $otherValue = $this->data->get($this->field);

        foreach ($this->values as $allowedValue) {
            if ($otherValue == $allowedValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if value is empty.
     *
     * @param mixed $value Value to check
     * @return bool
     */
    private function isEmpty(mixed $value): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        if (is_array($value) && count($value) === 0) {
            return true;
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
        $valuesStr = implode(', ', array_map(fn($v) => (string) $v, $this->values));

        return "The :attribute field is prohibited unless {$this->field} is {$valuesStr}.";
    }
}
