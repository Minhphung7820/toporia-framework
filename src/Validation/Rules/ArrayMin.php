<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class ArrayMin
 *
 * Validates that an array has at least N elements.
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
final class ArrayMin implements RuleInterface
{
    /**
     * @param int $min Minimum number of elements
     */
    public function __construct(
        private readonly int $min
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function passes(string $attribute, mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        return count($value) >= $this->min;
    }

    /**
     * {@inheritdoc}
     */
    public function message(): string
    {
        return "The :attribute must have at least {$this->min} items.";
    }
}

