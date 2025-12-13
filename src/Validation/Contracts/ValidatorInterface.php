<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Contracts;


/**
 * Interface ValidatorInterface
 *
 * Contract defining the interface for ValidatorInterface implementations
 * in the Form and data validation layer of the Toporia Framework.
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
interface ValidatorInterface
{
    /**
     * Validate data against rules.
     *
     * @param array $data Data to validate
     * @param array $rules Validation rules
     * @param array $messages Custom error messages
     * @return bool True if validation passes
     */
    public function validate(array $data, array $rules, array $messages = []): bool;

    /**
     * Check if validation failed.
     *
     * @return bool
     */
    public function fails(): bool;

    /**
     * Check if validation passed.
     *
     * @return bool
     */
    public function passes(): bool;

    /**
     * Get validation errors.
     *
     * @return array<string, array<string>>
     */
    public function errors(): array;

    /**
     * Get validated data (only fields that passed validation).
     *
     * @return array
     */
    public function validated(): array;

    /**
     * Register a custom validation rule.
     *
     * Allows extending the validator with custom validation logic.
     * Open/Closed Principle: Open for extension without modifying core.
     *
     * @param string $name Rule name (e.g., 'unique', 'custom_rule')
     * @param callable $callback Validation callback (value, parameters, data) => bool
     * @param string|null $message Default error message (optional)
     * @return void
     */
    public function extend(string $name, callable $callback, ?string $message = null): void;
}
