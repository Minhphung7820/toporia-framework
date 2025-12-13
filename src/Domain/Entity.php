<?php

declare(strict_types=1);

namespace Toporia\Framework\Domain;

use Toporia\Framework\Domain\Contracts\EntityInterface;
use Toporia\Framework\Domain\Contracts\ValueObjectInterface;

/**
 * Abstract Class Entity
 *
 * Base class for Entities in Domain-Driven Design.
 * Entities are identified by their unique identity, not their attributes.
 *
 * Usage with typed properties (recommended):
 * ```php
 * final class Order extends Entity
 * {
 *     public function __construct(
 *         private ?int $id,
 *         private CustomerId $customerId,
 *         private OrderStatus $status,
 *         private \DateTimeImmutable $createdAt
 *     ) {}
 *
 *     public function getId(): ?int
 *     {
 *         return $this->id;
 *     }
 *
 *     public function confirm(): void
 *     {
 *         if (!$this->status->canTransitionTo(OrderStatus::Confirmed)) {
 *             throw new \DomainException('Cannot confirm order');
 *         }
 *         $this->status = OrderStatus::Confirmed;
 *     }
 * }
 * ```
 *
 * @package Toporia\Framework\Domain
 */
abstract class Entity implements EntityInterface
{
    /**
     * {@inheritdoc}
     */
    abstract public function getId(): int|string|null;

    /**
     * {@inheritdoc}
     */
    public function isPersisted(): bool
    {
        return $this->getId() !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function equals(EntityInterface $other): bool
    {
        // Different types are never equal
        if (!($other instanceof static)) {
            return false;
        }

        // Unpersisted entities are never equal (even to themselves in this context)
        if (!$this->isPersisted() || !$other->isPersisted()) {
            return false;
        }

        return $this->getId() === $other->getId();
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        $array = [];
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (!$property->isInitialized($this)) {
                continue;
            }

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

        if ($value instanceof EntityInterface) {
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

        if (is_array($value)) {
            return array_map(fn($v) => $this->serializeValue($v), $value);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return $value;
    }
}
