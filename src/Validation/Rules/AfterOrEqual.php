<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class AfterOrEqual
 *
 * Validates that the date is after or equal to a given date or another field's date.
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
final class AfterOrEqual implements DataAwareRuleInterface
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

        $afterDate = $this->resolveDate();

        if ($afterDate === null) {
            return false;
        }

        try {
            $valueDate = $this->parseDate($value);
            return $valueDate >= $afterDate;
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
        if ($this->data !== null && $this->data->has($this->dateOrField)) {
            $fieldValue = $this->data->get($this->dateOrField);
            if ($fieldValue) {
                return $this->parseDate($fieldValue);
            }
        }

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
        return "The :attribute must be a date after or equal to {$this->dateOrField}.";
    }
}
