<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class StartsWith
 *
 * Validates that the string starts with one of the given values.
 *
 * Performance: O(n) where n = number of prefixes to check
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
final class StartsWith implements RuleInterface
{
    /**
     * @var array<string> Valid prefixes
     */
    private readonly array $values;

    /**
     * @param string|array<string> $values Allowed starting values
     */
    public function __construct(string|array $values)
    {
        $this->values = is_string($values)
            ? array_map('trim', explode(',', $values))
            : $values;
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

        if (!is_string($value)) {
            return false;
        }

        foreach ($this->values as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
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
        return "The :attribute must start with one of: " . implode(', ', $this->values) . ".";
    }
}
