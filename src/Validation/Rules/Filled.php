<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\Contracts\ValidatesWhenPresentInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class Filled
 *
 * Validates that the field must not be empty when present.
 * Unlike 'required', this rule only applies when the field is present in the input.
 *
 * Performance: O(1) - Empty check
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
final class Filled implements DataAwareRuleInterface, ValidatesWhenPresentInterface
{
    private ?ValidationData $data = null;

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
        // Only validate if the field is present in the data
        if ($this->data !== null && !$this->data->has($attribute)) {
            return true;
        }

        // Field is present - must not be empty
        return $this->hasValue($value);
    }

    /**
     * Check if value is not empty.
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
        return "The :attribute field must not be empty.";
    }
}
