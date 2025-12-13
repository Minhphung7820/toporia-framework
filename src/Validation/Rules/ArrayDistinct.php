<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class ArrayDistinct
 *
 * Validates that all values in an array are unique.
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
final class ArrayDistinct implements RuleInterface
{
    /**
     * {@inheritdoc}
     */
    public function passes(string $attribute, mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        // Check for duplicates using array_unique
        $unique = array_unique($value, SORT_REGULAR);
        return count($unique) === count($value);
    }

    /**
     * {@inheritdoc}
     */
    public function message(): string
    {
        return 'The :attribute must have unique values.';
    }
}

