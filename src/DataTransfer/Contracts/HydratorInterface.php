<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Contracts;

/**
 * Interface HydratorInterface
 *
 * Contract for hydrating objects from arrays/DTOs.
 * Hydrators populate domain entities from external data sources.
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
 *
 * @template T The target object type
 */
interface HydratorInterface
{
    /**
     * Hydrate an object with data.
     *
     * @param array<string, mixed> $data Source data
     * @param T|class-string<T> $target Target object or class name
     * @return T Hydrated object
     */
    public function hydrate(array $data, object|string $target): object;

    /**
     * Extract data from an object.
     *
     * @param T $object Source object
     * @return array<string, mixed> Extracted data
     */
    public function extract(object $object): array;

    /**
     * Check if hydrator supports the given class.
     *
     * @param class-string $class Class name
     * @return bool
     */
    public function supports(string $class): bool;
}
