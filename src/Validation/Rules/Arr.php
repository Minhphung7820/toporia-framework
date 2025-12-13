<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Arr
 *
 * Validates that the value is an array.
 * Optionally validates array size constraints.
 *
 * Performance: O(1) - Single is_array check
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
final class Arr implements RuleInterface
{
    /**
     * @param int|null $min Minimum array size (optional)
     * @param int|null $max Maximum array size (optional)
     * @param array<string>|null $keys Required keys that must be present
     */
    public function __construct(
        private readonly ?int $min = null,
        private readonly ?int $max = null,
        private readonly ?array $keys = null
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
        if (is_null($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        $count = count($value);

        if ($this->min !== null && $count < $this->min) {
            return false;
        }

        if ($this->max !== null && $count > $this->max) {
            return false;
        }

        // Check required keys
        if ($this->keys !== null) {
            foreach ($this->keys as $key) {
                if (!array_key_exists($key, $value)) {
                    return false;
                }
            }
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
        if ($this->min !== null && $this->max !== null) {
            return "The :attribute must be an array with {$this->min} to {$this->max} items.";
        }

        if ($this->min !== null) {
            return "The :attribute must be an array with at least {$this->min} items.";
        }

        if ($this->max !== null) {
            return "The :attribute must be an array with no more than {$this->max} items.";
        }

        return 'The :attribute must be an array.';
    }
}
