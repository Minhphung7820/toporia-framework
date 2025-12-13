<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class Distinct
 *
 * Validates that an array field does not have duplicate values.
 *
 * Modes:
 *   - strict: Use strict comparison (===)
 *   - ignore_case: Case-insensitive comparison for strings
 *
 * Performance: O(n) where n = array length
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
final class Distinct implements DataAwareRuleInterface
{
    private ?ValidationData $data = null;

    public const MODE_STRICT = 'strict';
    public const MODE_IGNORE_CASE = 'ignore_case';

    /**
     * @param string|null $mode Comparison mode
     */
    public function __construct(
        private readonly ?string $mode = null
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

        // Get all values for this array attribute pattern
        $allValues = $this->getArrayValues($attribute);

        if (empty($allValues)) {
            return true;
        }

        return $this->hasNoDuplicates($allValues);
    }

    /**
     * Get all values for array attribute pattern.
     *
     * @param string $attribute The attribute name
     * @return array<mixed>
     */
    private function getArrayValues(string $attribute): array
    {
        if ($this->data === null) {
            return [];
        }

        // Extract the array part from attribute (e.g., "items.*.value" -> "items")
        $parts = explode('.', $attribute);
        $arrayKey = $parts[0] ?? '';
        $fieldKey = end($parts);

        $arrayData = $this->data->get($arrayKey);

        if (!is_array($arrayData)) {
            return [];
        }

        // Collect values from all array items
        $values = [];
        foreach ($arrayData as $item) {
            if (is_array($item) && isset($item[$fieldKey])) {
                $values[] = $item[$fieldKey];
            } elseif (!is_array($item)) {
                // Direct array values (e.g., "tags.*")
                $values[] = $item;
            }
        }

        return $values;
    }

    /**
     * Check if array has no duplicate values.
     *
     * @param array<mixed> $values Values to check
     * @return bool
     */
    private function hasNoDuplicates(array $values): bool
    {
        $seen = [];

        foreach ($values as $value) {
            $normalizedValue = $this->normalizeValue($value);

            if ($this->mode === self::MODE_STRICT) {
                // Use strict type checking
                foreach ($seen as $seenValue) {
                    if ($value === $seenValue) {
                        return false;
                    }
                }
                $seen[] = $value;
            } else {
                // Use normalized comparison
                if (in_array($normalizedValue, $seen, true)) {
                    return false;
                }
                $seen[] = $normalizedValue;
            }
        }

        return true;
    }

    /**
     * Normalize value for comparison.
     *
     * @param mixed $value Value to normalize
     * @return mixed
     */
    private function normalizeValue(mixed $value): mixed
    {
        if ($this->mode === self::MODE_IGNORE_CASE && is_string($value)) {
            return mb_strtolower($value);
        }

        return $value;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute field has a duplicate value.";
    }
}
