<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules\Concerns;

/**
 * Trait ReplacesAttributes
 *
 * Provides message placeholder replacement functionality for validation rules.
 * Supports :attribute, :value, and custom parameter placeholders.
 *
 * Performance: O(n) where n = number of placeholders
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules\Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait ReplacesAttributes
{
    /**
     * Replace placeholders in message with actual values.
     *
     * Supported placeholders:
     * - :attribute - The field name being validated
     * - :value - The actual value being validated
     * - :param0, :param1, etc. - Rule parameters by index
     * - Custom placeholders defined in $replacements array
     *
     * @param string $message The message template
     * @param string $attribute The attribute name
     * @param mixed $value The value being validated
     * @param array<string, mixed> $replacements Additional replacements
     * @return string
     */
    protected function replaceAttributes(
        string $message,
        string $attribute,
        mixed $value = null,
        array $replacements = []
    ): string {
        // Convert attribute name to display format (snake_case to words)
        $displayAttribute = str_replace('_', ' ', $attribute);

        // Base replacements
        $replace = [
            ':attribute' => $displayAttribute,
            ':Attribute' => ucfirst($displayAttribute),
            ':ATTRIBUTE' => strtoupper($displayAttribute),
        ];

        // Add value if scalar
        if (is_scalar($value)) {
            $replace[':value'] = (string) $value;
        }

        // Merge custom replacements
        $replace = array_merge($replace, $replacements);

        return strtr($message, $replace);
    }

    /**
     * Format value for display in error message.
     *
     * @param mixed $value The value to format
     * @return string
     */
    protected function formatValue(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return get_class($value);
        }

        return (string) $value;
    }
}
