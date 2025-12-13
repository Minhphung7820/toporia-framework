<?php

declare(strict_types=1);

namespace Toporia\Framework\Domain\Contracts;

/**
 * Interface ValueObjectInterface
 *
 * Contract for immutable Value Objects in Domain-Driven Design.
 * Value Objects are identified by their attribute values, not identity.
 *
 * Key characteristics:
 * - Immutability: Once created, cannot be changed
 * - Equality by value: Two VOs with same attributes are equal
 * - Self-validation: Always in valid state
 * - Side-effect free: Methods don't modify state
 *
 * @package Toporia\Framework\Domain\Contracts
 */
interface ValueObjectInterface
{
    /**
     * Check equality with another value object.
     *
     * Two value objects are equal if they are of the same type
     * and all their attributes have equal values.
     *
     * @param ValueObjectInterface $other Value object to compare.
     * @return bool True if equal.
     */
    public function equals(ValueObjectInterface $other): bool;

    /**
     * Get hash code for use in hash-based collections.
     *
     * Objects that are equal MUST have the same hash code.
     * Used for efficient lookups in sets and map keys.
     *
     * @return string Hash code string.
     */
    public function hashCode(): string;

    /**
     * Get string representation.
     *
     * @return string Human-readable string representation.
     */
    public function __toString(): string;

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed> Associative array of attributes.
     */
    public function toArray(): array;
}
