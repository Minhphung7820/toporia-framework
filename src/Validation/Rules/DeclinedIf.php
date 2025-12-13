<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\Contracts\ImplicitRuleInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class DeclinedIf
 *
 * Validates that the field is declined when another field equals a specific value.
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
final class DeclinedIf implements DataAwareRuleInterface, ImplicitRuleInterface
{
    private ?ValidationData $data = null;

    /**
     * Declined values.
     */
    private const DECLINED_VALUES = ['no', 'off', '0', 0, false, 'false'];

    /**
     * @param string $field Field to check
     * @param mixed $value Value that triggers declined requirement
     */
    public function __construct(
        private readonly string $field,
        private readonly mixed $expectedValue
    ) {}

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
        // If condition is not met, always pass
        if (!$this->conditionMet()) {
            return true;
        }

        // Condition is met - must be declined
        return in_array($value, self::DECLINED_VALUES, true);
    }

    /**
     * Check if the condition is met.
     *
     * @return bool
     */
    private function conditionMet(): bool
    {
        if ($this->data === null) {
            return false;
        }

        $otherValue = $this->data->get($this->field);

        return $otherValue == $this->expectedValue;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute must be declined when {$this->field} is {$this->expectedValue}.";
    }
}
