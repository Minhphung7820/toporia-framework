<?php

declare(strict_types=1);

namespace Toporia\Framework\Domain;

use Toporia\Framework\Domain\Contracts\ValueObjectInterface;

/**
 * Abstract Class ValueObject
 *
 * Base class for immutable Value Objects in Domain-Driven Design.
 * Provides default implementations for equality, hashing, and serialization.
 *
 * Usage:
 * ```php
 * final class Money extends ValueObject
 * {
 *     public function __construct(
 *         public readonly int $amount,
 *         public readonly string $currency
 *     ) {
 *         $this->validate();
 *     }
 *
 *     protected function validate(): void
 *     {
 *         if ($this->amount < 0) {
 *             throw new \InvalidArgumentException('Amount cannot be negative');
 *         }
 *     }
 *
 *     protected function equalityComponents(): array
 *     {
 *         return [$this->amount, $this->currency];
 *     }
 *
 *     public function __toString(): string
 *     {
 *         return "{$this->amount} {$this->currency}";
 *     }
 * }
 * ```
 *
 * @package Toporia\Framework\Domain
 */
abstract class ValueObject implements ValueObjectInterface
{
    /**
     * Cached hash code for performance.
     */
    private ?string $cachedHashCode = null;

    /**
     * Get components used for equality comparison.
     *
     * Override this method to define which properties determine equality.
     * Default implementation uses all public readonly properties.
     *
     * @return array<int, mixed> Array of values to compare.
     */
    protected function equalityComponents(): array
    {
        $components = [];
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isReadOnly()) {
                $components[] = $property->getValue($this);
            }
        }

        return $components;
    }

    /**
     * {@inheritdoc}
     */
    public function equals(ValueObjectInterface $other): bool
    {
        if (!($other instanceof static)) {
            return false;
        }

        return $this->equalityComponents() === $other->equalityComponents();
    }

    /**
     * {@inheritdoc}
     */
    public function hashCode(): string
    {
        if ($this->cachedHashCode === null) {
            $this->cachedHashCode = md5(serialize($this->equalityComponents()));
        }

        return $this->cachedHashCode;
    }

    /**
     * {@inheritdoc}
     */
    abstract public function __toString(): string;

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        $array = [];
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($this);
            $array[$property->getName()] = $this->serializeValue($value);
        }

        return $array;
    }

    /**
     * Serialize a value for array output.
     *
     * @param mixed $value Value to serialize.
     * @return mixed Serialized value.
     */
    private function serializeValue(mixed $value): mixed
    {
        if ($value instanceof ValueObjectInterface) {
            return $value->toArray();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return $value;
    }

    /**
     * Validate the value object state.
     *
     * Override in child classes to add validation.
     * Should throw InvalidArgumentException if invalid.
     *
     * @return void
     * @throws \InvalidArgumentException If validation fails.
     */
    protected function validate(): void
    {
        // Override in child classes
    }

    /**
     * Prevent cloning to maintain immutability.
     */
    private function __clone(): void
    {
        // Value objects are immutable, cloning is not needed
    }
}
