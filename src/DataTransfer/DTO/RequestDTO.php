<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\DTO;

use Toporia\Framework\DataTransfer\Contracts\RequestDTOInterface;
use Toporia\Framework\Validation\Contracts\ValidatorInterface;
use Toporia\Framework\Validation\ValidationException;

/**
 * Class RequestDTO
 *
 * Base class for request DTOs with validation support.
 * Validates incoming data before creating DTO instance.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\DTO
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class RequestDTO extends DataTransferObject implements RequestDTOInterface
{
    /**
     * Validator instance (injected via service container).
     *
     * @var ValidatorInterface|null
     */
    protected static ?ValidatorInterface $validator = null;

    /**
     * Set validator instance.
     *
     * @param ValidatorInterface $validator
     * @return void
     */
    public static function setValidator(ValidatorInterface $validator): void
    {
        self::$validator = $validator;
    }

    /**
     * Get validation rules.
     *
     * @return array<string, string|array>
     */
    public static function rules(): array
    {
        return [];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [];
    }

    /**
     * Get custom attribute names.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [];
    }

    /**
     * Create validated DTO from request data.
     *
     * @param array<string, mixed> $data
     * @return static
     * @throws ValidationException
     */
    public static function fromRequest(array $data): static
    {
        // Validate if validator is available and rules exist
        $rules = static::rules();

        if (!empty($rules) && self::$validator !== null) {
            $validation = self::$validator->make($data, $rules, static::messages(), static::attributes());

            if ($validation->fails()) {
                throw new ValidationException($validation);
            }

            $data = $validation->validated();
        }

        return static::fromArray($data);
    }

    /**
     * Prepare data before validation.
     * Override to sanitize/normalize input.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected static function prepareForValidation(array $data): array
    {
        return $data;
    }

    /**
     * Get default values for missing properties.
     *
     * @return array<string, mixed>
     */
    protected static function defaults(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public static function fromArray(array $data): static
    {
        // Merge with defaults
        $data = array_merge(static::defaults(), $data);

        return parent::fromArray($data);
    }
}
