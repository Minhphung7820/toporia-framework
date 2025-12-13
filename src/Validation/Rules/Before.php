<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class Before
 *
 * Validates that the date is before a given date or another field's date.
 *
 * Performance: O(1) - Date parsing and comparison
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
final class Before implements DataAwareRuleInterface
{
    private ?ValidationData $data = null;

    /**
     * @param string $dateOrField Date string or field name to compare against
     */
    public function __construct(
        private readonly string $dateOrField
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
        if (is_null($value) || $value === '') {
            return true;
        }

        $beforeDate = $this->resolveDate();

        if ($beforeDate === null) {
            return false;
        }

        try {
            $valueDate = $this->parseDate($value);
            return $valueDate < $beforeDate;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Resolve the comparison date (from field or direct value).
     *
     * @return \DateTimeInterface|null
     */
    private function resolveDate(): ?\DateTimeInterface
    {
        // Check if it's a field name in data
        if ($this->data !== null && $this->data->has($this->dateOrField)) {
            $fieldValue = $this->data->get($this->dateOrField);
            if ($fieldValue) {
                return $this->parseDate($fieldValue);
            }
        }

        // Parse as direct date value
        return $this->parseDate($this->dateOrField);
    }

    /**
     * Parse date string to DateTime.
     *
     * @param mixed $value Date value
     * @return \DateTimeInterface|null
     */
    private function parseDate(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        try {
            // Try framework's Chronos first if available
            if (class_exists(\Toporia\Framework\DateTime\Chronos::class)) {
                return \Toporia\Framework\DateTime\Chronos::parse($value);
            }

            return new \DateTime($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute must be a date before {$this->dateOrField}.";
    }
}
