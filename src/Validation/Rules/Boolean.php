<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Boolean
 *
 * Validates that the value is a boolean or boolean-like value.
 * Accepts: true, false, 0, 1, '0', '1'
 *
 * Performance: O(1) - Single in_array check
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
final class Boolean implements RuleInterface
{
    /**
     * Acceptable boolean values.
     */
    private const ACCEPTABLE = [true, false, 0, 1, '0', '1', 'true', 'false'];

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

        return in_array($value, self::ACCEPTABLE, true);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return 'The :attribute must be true or false.';
    }
}
