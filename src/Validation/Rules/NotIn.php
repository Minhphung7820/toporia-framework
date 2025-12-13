<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class NotIn
 *
 * Validates that the value does NOT exist in a given list of values.
 * Uses strict type comparison.
 *
 * Performance: O(n) where n = number of forbidden values
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
final class NotIn implements RuleInterface
{
    /**
     * @var array<mixed> Forbidden values
     */
    private readonly array $values;

    /**
     * @param mixed ...$values Forbidden values
     */
    public function __construct(mixed ...$values)
    {
        // Handle case where first argument is an array
        if (count($values) === 1 && is_array($values[0])) {
            $this->values = $values[0];
        } else {
            $this->values = $values;
        }
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

        return !in_array($value, $this->values, true);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return 'The selected :attribute is invalid.';
    }
}
