<?php

declare(strict_types=1);

namespace Toporia\Framework\Domain;

use Toporia\Framework\Domain\Contracts\DomainEventInterface;

/**
 * Abstract Class DomainEvent
 *
 * Base class for Domain Events in Domain-Driven Design.
 * Represents something that happened in the domain.
 *
 * Usage:
 * ```php
 * final class OrderPlaced extends DomainEvent
 * {
 *     public function __construct(
 *         private int $orderId,
 *         private int $customerId,
 *         private float $totalAmount
 *     ) {
 *         parent::__construct();
 *     }
 *
 *     public function getAggregateId(): int
 *     {
 *         return $this->orderId;
 *     }
 *
 *     public function getAggregateType(): string
 *     {
 *         return Order::class;
 *     }
 *
 *     protected function getPayload(): array
 *     {
 *         return [
 *             'order_id' => $this->orderId,
 *             'customer_id' => $this->customerId,
 *             'total_amount' => $this->totalAmount,
 *         ];
 *     }
 * }
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Domain
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class DomainEvent implements DomainEventInterface
{
    private string $eventId;
    private \DateTimeImmutable $occurredAt;

    public function __construct(?string $eventId = null, ?\DateTimeImmutable $occurredAt = null)
    {
        $this->eventId = $eventId ?? $this->generateEventId();
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    /**
     * {@inheritdoc}
     */
    public function getEventId(): string
    {
        return $this->eventId;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventName(): string
    {
        // Convert class name to event name: OrderPlaced -> order.placed
        $className = (new \ReflectionClass($this))->getShortName();

        return strtolower(
            preg_replace('/(?<!^)[A-Z]/', '.$0', $className) ?? $className
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * {@inheritdoc}
     */
    abstract public function getAggregateId(): int|string|null;

    /**
     * {@inheritdoc}
     */
    abstract public function getAggregateType(): string;

    /**
     * Get the event-specific payload.
     *
     * @return array<string, mixed> Event data.
     */
    abstract protected function getPayload(): array;

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->getEventName(),
            'aggregate_id' => $this->getAggregateId(),
            'aggregate_type' => $this->getAggregateType(),
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s.u'),
            'payload' => $this->getPayload(),
        ];
    }

    /**
     * Generate a unique event ID.
     *
     * @return string UUID v4.
     */
    private function generateEventId(): string
    {
        // Generate UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Reconstruct event from stored data.
     *
     * @param string $eventId Original event ID.
     * @param \DateTimeImmutable $occurredAt Original occurrence time.
     * @return static Reconstructed event.
     */
    public function withMetadata(string $eventId, \DateTimeImmutable $occurredAt): static
    {
        $clone = clone $this;
        $clone->eventId = $eventId;
        $clone->occurredAt = $occurredAt;

        return $clone;
    }
}
