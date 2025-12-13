<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class DateFormat
 *
 * Validates that the date matches a specific format.
 *
 * Performance: O(1) - Date parsing
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
final class DateFormat implements RuleInterface
{
    /**
     * @var array<string> Allowed date formats
     */
    private readonly array $formats;

    /**
     * @param string|array<string> $formats Expected date format(s)
     */
    public function __construct(string|array $formats)
    {
        $this->formats = is_string($formats) ? [$formats] : $formats;
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

        foreach ($this->formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);

            if ($date !== false && $date->format($format) === $value) {
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
        $formatsStr = implode(', ', $this->formats);
        return "The :attribute must match the format: {$formatsStr}.";
    }
}
