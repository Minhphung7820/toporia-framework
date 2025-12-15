<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\Rules\Concerns\ReplacesAttributes;

/**
 * Class Time
 *
 * Validates that the value is a valid time format (HH:MM or HH:MM:SS).
 *
 * Supported formats:
 * - 24-hour: 00:00 to 23:59 or 00:00:00 to 23:59:59
 * - With optional seconds
 *
 * Performance: O(1) - Single regex match
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 */
final class Time implements RuleInterface
{
    use ReplacesAttributes;

    /**
     * @param bool $withSeconds Whether to require seconds (HH:MM:SS)
     */
    public function __construct(
        private readonly bool $withSeconds = false
    ) {}

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

        // Pattern for HH:MM or HH:MM:SS (24-hour format)
        if ($this->withSeconds) {
            $pattern = '/^([01]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/';
        } else {
            $pattern = '/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/';
        }

        return preg_match($pattern, $value) === 1;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return 'The :attribute must be a valid time.';
    }
}
