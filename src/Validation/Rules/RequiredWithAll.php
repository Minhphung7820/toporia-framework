<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\Contracts\ImplicitRuleInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class RequiredWithAll
 *
 * Validates that the field is required when ALL specified fields are present.
 *
 * Usage:
 *   new RequiredWithAll(['first_name', 'last_name'])
 *   new RequiredWithAll('first_name,last_name')
 *
 * Performance: O(n) where n = number of fields to check
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
final class RequiredWithAll implements DataAwareRuleInterface, ImplicitRuleInterface
{
    private ?ValidationData $data = null;

    /**
     * @var array<string> Fields to check for presence
     */
    private readonly array $fields;

    /**
     * @param string|array<string> $fields Field(s) that must all be present
     */
    public function __construct(string|array $fields)
    {
        $this->fields = is_string($fields)
            ? array_map('trim', explode(',', $fields))
            : $fields;
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
        // If not all fields are present, always pass
        if (!$this->allFieldsPresent()) {
            return true;
        }

        // All fields are present - this field is required
        return $this->hasValue($value);
    }

    /**
     * Check if all specified fields are present.
     *
     * @return bool
     */
    private function allFieldsPresent(): bool
    {
        if ($this->data === null) {
            return false;
        }

        foreach ($this->fields as $field) {
            $fieldValue = $this->data->get($field);

            if (!$this->hasValue($fieldValue)) {
                return false;
            }
        }

        return true;
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
        return "The :attribute field is required when " . implode(' and ', $this->fields) . " are present.";
    }
}
