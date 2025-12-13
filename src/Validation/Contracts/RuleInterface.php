<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Contracts;


/**
 * Interface RuleInterface
 *
 * Contract defining the interface for RuleInterface implementations in the
 * Form and data validation layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface RuleInterface
{
    /**
     * Determine if the validation rule passes.
     *
     * This method is called for each field value during validation.
     * It should be pure (no side effects) and fast (O(1) or O(n) where n is value size).
     *
     * @param string $attribute The attribute name being validated
     * @param mixed $value The value being validated
     * @return bool True if validation passes, false otherwise
     */
    public function passes(string $attribute, mixed $value): bool;

    /**
     * Get the validation error message.
     *
     * This method is called when validation fails.
     * It should return a human-readable error message.
     *
     * Performance: Called only on validation failure (lazy evaluation)
     *
     * @return string The error message (supports :attribute placeholder)
     */
    public function message(): string;
}

