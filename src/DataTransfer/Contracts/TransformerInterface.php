<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Contracts;

/**
 * Interface TransformerInterface
 *
 * Contract for transforming entities to resources/DTOs.
 * Transformers convert domain entities to presentation-layer representations.
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
 * @template TEntity The source entity type
 * @template TResource The target resource type
 */
interface TransformerInterface
{
    /**
     * Transform a single entity to resource.
     *
     * @param TEntity $entity Source entity
     * @param array<string, mixed> $context Transformation context
     * @return TResource Transformed resource
     */
    public function transform(mixed $entity, array $context = []): mixed;

    /**
     * Transform a collection of entities.
     *
     * @param iterable<TEntity> $entities Source entities
     * @param array<string, mixed> $context Transformation context
     * @return array<TResource> Transformed resources
     */
    public function transformCollection(iterable $entities, array $context = []): array;

    /**
     * Check if transformer can handle the given entity.
     *
     * @param mixed $entity Entity to check
     * @return bool
     */
    public function supports(mixed $entity): bool;

    /**
     * Get available includes for this transformer.
     *
     * @return array<string>
     */
    public function getAvailableIncludes(): array;

    /**
     * Get default includes.
     *
     * @return array<string>
     */
    public function getDefaultIncludes(): array;
}
