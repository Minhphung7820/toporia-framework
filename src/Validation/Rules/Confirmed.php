<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class Confirmed
 *
 * Validates that the value matches a confirmation field.
 * Looks for a field named {attribute}_confirmation.
 *
 * Example: For field 'password', looks for 'password_confirmation'
 *
 * Performance: O(1) - Single comparison
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
final class Confirmed implements DataAwareRuleInterface
{
    private ?ValidationData $data = null;

    /**
     * Set validation data for comparison.
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
        if ($this->data === null) {
            return false;
        }

        $confirmationField = $attribute . '_confirmation';
        $confirmationValue = $this->data->get($confirmationField);

        return $value === $confirmationValue;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return 'The :attribute confirmation does not match.';
    }
}
