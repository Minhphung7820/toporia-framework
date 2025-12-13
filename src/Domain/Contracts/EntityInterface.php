<?php

declare(strict_types=1);

namespace Toporia\Framework\Domain\Contracts;

/**
 * Interface EntityInterface
 *
 * Contract for Entities in Domain-Driven Design.
 * Entities are identified by their unique identity, not their attributes.
 *
 * Key characteristics:
 * - Identity: Has a unique identifier that persists through state changes
 * - Equality by identity: Two entities with same ID are the same entity
 * - Lifecycle: Can change state over time while maintaining identity
 * - Encapsulation: Protects invariants through behavior methods
 *
 * @package Toporia\Framework\Domain\Contracts
 */
interface EntityInterface
{
    /**
     * Get the entity's unique identifier.
     *
     * @return int|string|null Entity ID. Null only for new unpersisted entities.
     */
    public function getId(): int|string|null;

    /**
     * Check if this entity has been persisted.
     *
     * @return bool True if entity has an ID.
     */
    public function isPersisted(): bool;

    /**
     * Check if this entity is the same as another.
     *
     * Entities are equal if they have the same type and ID.
     * Two new entities (without ID) are never equal.
     *
     * @param EntityInterface $other Entity to compare.
     * @return bool True if same entity.
     */
    public function equals(EntityInterface $other): bool;

    /**
     * Convert entity to array representation.
     *
     * @return array<string, mixed> Entity attributes.
     */
    public function toArray(): array;
}
