<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\ImplicitRuleInterface;

/**
 * Class Accepted
 *
 * Validates that the field is "yes", "on", 1, "1", true, or "true".
 * Useful for validating checkbox acceptance like terms of service.
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
final class Accepted implements ImplicitRuleInterface
{
    /**
     * Accepted values.
     */
    private const ACCEPTED_VALUES = ['yes', 'on', '1', 1, true, 'true'];

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute The attribute name being validated
     * @param mixed $value The value being validated
     * @return bool
     */
    public function passes(string $attribute, mixed $value): bool
    {
        return in_array($value, self::ACCEPTED_VALUES, true);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute must be accepted.";
    }
}
