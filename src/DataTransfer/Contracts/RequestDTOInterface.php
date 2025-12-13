<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Contracts;

/**
 * Interface RequestDTOInterface
 *
 * Contract for request-specific DTOs with validation support.
 * Used to encapsulate and validate incoming request data.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface RequestDTOInterface extends DTOInterface
{
    /**
     * Get validation rules for this DTO.
     *
     * @return array<string, string|array>
     */
    public static function rules(): array;

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public static function messages(): array;

    /**
     * Get custom attribute names.
     *
     * @return array<string, string>
     */
    public static function attributes(): array;

    /**
     * Create validated DTO from request data.
     *
     * @param array<string, mixed> $data Raw request data
     * @return static
     * @throws \Toporia\Framework\DataTransfer\Exceptions\ValidationException
     */
    public static function fromRequest(array $data): static;
}
