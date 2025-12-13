<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation;

use Toporia\Framework\Validation\Contracts\ValidatorInterface;

/**
 * Class ArrayValidator
 *
 * Handles validation for array fields with nested structures.
 * Supports wildcard notation (items.*.name) and indexed arrays (items.0.name).
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ArrayValidator
{
    /**
     * Validate array field with nested rules.
     *
     * Supports:
     * - Wildcard notation: items.*.name
     * - Indexed notation: items.0.name, items.1.name
     * - Nested arrays: items.*.tags.*
     *
     * Performance: O(n * m) where n = array size, m = rules per element
     *
     * @param string $field Field name (e.g., "items", "items.*", "items.*.name")
     * @param mixed $value Field value
     * @param array<string|RuleInterface> $rules Validation rules
     * @param array<string, mixed> $data All validation data
     * @param array<string, string> $messages Custom error messages
     * @param ValidatorInterface $validator Validator instance
     * @return array<string, array<string>> Validation errors
     */
    public static function validateArrayField(
        string $field,
        mixed $value,
        array $rules,
        array $data,
        array $messages,
        ValidatorInterface $validator
    ): array {
        $errors = [];

        // If value is not an array, validate as single value
        if (!is_array($value)) {
            // Let main validator handle it
            return $errors;
        }

        // Check if field uses wildcard notation
        if (str_contains($field, '.*')) {
            return self::validateWildcardArray($field, $value, $rules, $data, $messages, $validator);
        }

        // Check if field uses indexed notation
        if (preg_match('/\.\d+\./', $field)) {
            return self::validateIndexedArray($field, $value, $rules, $data, $messages, $validator);
        }

        // Simple array validation (validate array itself)
        return self::validateSimpleArray($field, $value, $rules, $data, $messages, $validator);
    }

    /**
     * Validate array with wildcard notation (items.*.name).
     *
     * Supports nested wildcards (items.*.tags.*).
     *
     * Performance: O(n * m) where n = array size, m = rules per element
     *
     * @param string $field Field name with wildcard
     * @param array $value Array value
     * @param array<string|RuleInterface> $rules Validation rules
     * @param array<string, mixed> $data All validation data
     * @param array<string, string> $messages Custom error messages
     * @param ValidatorInterface $validator Validator instance
     * @return array<string, array<string>> Validation errors
     */
    private static function validateWildcardArray(
        string $field,
        array $value,
        array $rules,
        array $data,
        array $messages,
        ValidatorInterface $validator
    ): array {
        $errors = [];
        $baseField = self::getBaseField($field);

        // Expand wildcard to all array indices
        foreach ($value as $index => $item) {
            $expandedField = str_replace('.*', ".{$index}", $field);
            $expandedData = $data;

            // Update nested data structure
            self::setNestedValue($expandedData, $baseField, $index, $item);

            // Check if expanded field is still an array (nested wildcard)
            if (is_array($item) && str_contains($expandedField, '.*')) {
                // Recursive validation for nested arrays
                $nestedErrors = self::validateWildcardArray(
                    $expandedField,
                    $item,
                    $rules,
                    $expandedData,
                    $messages,
                    $validator
                );
                $errors = array_merge($errors, $nestedErrors);
            } else {
                // Validate each element
                $elementErrors = self::validateArrayElement(
                    $expandedField,
                    $item,
                    $rules,
                    $expandedData,
                    $messages,
                    $validator
                );

                $errors = array_merge($errors, $elementErrors);
            }
        }

        return $errors;
    }

    /**
     * Set nested value in data array using dot notation path.
     *
     * Performance: O(d) where d = depth
     *
     * @param array<string, mixed> $data Data array (by reference)
     * @param string $baseField Base field name
     * @param int|string $index Array index
     * @param mixed $value Value to set
     * @return void
     */
    private static function setNestedValue(array &$data, string $baseField, int|string $index, mixed $value): void
    {
        if (!isset($data[$baseField])) {
            $data[$baseField] = [];
        }

        if (!is_array($data[$baseField])) {
            $data[$baseField] = [];
        }

        $data[$baseField][$index] = $value;
    }

    /**
     * Validate array with indexed notation (items.0.name).
     *
     * @param string $field Field name with index
     * @param array $value Array value
     * @param array<string|RuleInterface> $rules Validation rules
     * @param array<string, mixed> $data All validation data
     * @param array<string, string> $messages Custom error messages
     * @param ValidatorInterface $validator Validator instance
     * @return array<string, array<string>> Validation errors
     */
    private static function validateIndexedArray(
        string $field,
        array $value,
        array $rules,
        array $data,
        array $messages,
        ValidatorInterface $validator
    ): array {
        $errors = [];

        // Extract index from field (e.g., "items.0.name" -> index = 0)
        if (preg_match('/\.(\d+)\./', $field, $matches)) {
            $index = (int) $matches[1];
            $item = $value[$index] ?? null;

            if ($item !== null) {
                $elementErrors = self::validateArrayElement(
                    $field,
                    $item,
                    $rules,
                    $data,
                    $messages,
                    $validator
                );

                $errors = array_merge($errors, $elementErrors);
            }
        }

        return $errors;
    }

    /**
     * Validate simple array (no wildcard/index).
     *
     * @param string $field Field name
     * @param array $value Array value
     * @param array<string|RuleInterface> $rules Validation rules
     * @param array<string, mixed> $data All validation data
     * @param array<string, string> $messages Custom error messages
     * @param ValidatorInterface $validator Validator instance
     * @return array<string, array<string>> Validation errors
     */
    private static function validateSimpleArray(
        string $field,
        array $value,
        array $rules,
        array $data,
        array $messages,
        ValidatorInterface $validator
    ): array {
        $errors = [];

        // Validate array itself (size, type, etc.)
        foreach ($rules as $rule) {
            $ruleString = is_string($rule) ? $rule : get_class($rule);
            [$ruleName] = self::parseRule($ruleString);

            // Skip rules that don't apply to arrays
            if (in_array($ruleName, ['array', 'min', 'max', 'size', 'between'], true)) {
                continue; // These are handled by main validator
            }

            // Validate each element with the rule
            foreach ($value as $index => $item) {
                $elementField = "{$field}.{$index}";
                $elementData = $data;
                $elementData[$field][$index] = $item;

                $elementErrors = self::validateArrayElement(
                    $elementField,
                    $item,
                    [$rule],
                    $elementData,
                    $messages,
                    $validator
                );

                $errors = array_merge($errors, $elementErrors);
            }
        }

        return $errors;
    }

    /**
     * Validate a single array element.
     *
     * @param string $field Element field name
     * @param mixed $value Element value
     * @param array<string|RuleInterface> $rules Validation rules
     * @param array<string, mixed> $data All validation data
     * @param array<string, string> $messages Custom error messages
     * @param ValidatorInterface $validator Validator instance
     * @return array<string, array<string>> Validation errors
     */
    private static function validateArrayElement(
        string $field,
        mixed $value,
        array $rules,
        array $data,
        array $messages,
        ValidatorInterface $validator
    ): array {
        $errors = [];

        // Create temporary validator for this element
        $elementData = [$field => $value];
        $elementRules = [$field => $rules];

        // Create new validator instance (more efficient than clone)
        $elementValidator = new Validator();
        $elementValidator->validate($elementData, $elementRules, $messages);

        $elementErrors = $elementValidator->errors();
        if (!empty($elementErrors) && isset($elementErrors[$field])) {
            $errors[$field] = $elementErrors[$field];
        }

        return $errors;
    }

    /**
     * Get base field name from wildcard field.
     *
     * @param string $field Field name (e.g., "items.*.name")
     * @return string Base field (e.g., "items")
     */
    private static function getBaseField(string $field): string
    {
        $parts = explode('.', $field);
        return $parts[0];
    }

    /**
     * Parse rule string into name and parameters.
     *
     * @param string $rule Rule string
     * @return array{string, array<string>}
     */
    private static function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) {
            return [$rule, []];
        }

        [$ruleName, $params] = explode(':', $rule, 2);
        return [$ruleName, explode(',', $params)];
    }

    /**
     * Expand wildcard field to all array indices.
     *
     * Performance: O(n) where n = array size
     *
     * @param string $field Field with wildcard (e.g., "items.*.name")
     * @param array $value Array value
     * @return array<string> Expanded field names
     *
     * @example
     * expandWildcard("items.*.name", [0 => [...], 1 => [...]])
     * Returns: ["items.0.name", "items.1.name"]
     */
    public static function expandWildcard(string $field, array $value): array
    {
        if (!str_contains($field, '.*')) {
            return [$field];
        }

        $expanded = [];
        foreach (array_keys($value) as $index) {
            $expanded[] = str_replace('.*', ".{$index}", $field);
        }

        return $expanded;
    }

    /**
     * Check if field uses array notation.
     *
     * @param string $field Field name
     * @return bool
     */
    public static function isArrayField(string $field): bool
    {
        return str_contains($field, '.*') || preg_match('/\.\d+\./', $field);
    }
}
