<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Contracts;

/**
 * Interface DTOInterface
 *
 * Base contract for all Data Transfer Objects.
 * DTOs are immutable value objects used to transfer data between layers.
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
interface DTOInterface
{
    /**
     * Create DTO from array data.
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public static function fromArray(array $data): static;

    /**
     * Convert DTO to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Check if DTO has a property.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Get a property value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;
}
