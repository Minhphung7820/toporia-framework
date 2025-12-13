<?php

declare(strict_types=1);

namespace Toporia\Framework\Domain;

use Toporia\Framework\Domain\Contracts\AggregateRootInterface;
use Toporia\Framework\Domain\Contracts\DomainEventInterface;

/**
 * Abstract Class AggregateRoot
 *
 * Base class for Aggregate Roots in Domain-Driven Design.
 * Aggregate roots are the entry point to an aggregate cluster.
 *
 * Usage:
 * ```php
 * final class Order extends AggregateRoot
 * {
 *     private OrderStatus $status;
 *     private array $items = [];
 *
 *     public function __construct(
 *         private ?int $id,
 *         private CustomerId $customerId
 *     ) {
 *         $this->status = OrderStatus::Pending;
 *     }
 *
 *     public function getId(): ?int
 *     {
 *         return $this->id;
 *     }
 *
 *     public static function place(CustomerId $customerId): self
 *     {
 *         $order = new self(null, $customerId);
 *         $order->recordEvent(new OrderPlaced($order->id, $customerId));
 *         return $order;
 *     }
 *
 *     public function addItem(Product $product, int $quantity): void
 *     {
 *         $this->items[] = new OrderItem($product, $quantity);
 *         $this->recordEvent(new OrderItemAdded($this->id, $product->id, $quantity));
 *     }
 *
 *     public function confirm(): void
 *     {
 *         if ($this->status !== OrderStatus::Pending) {
 *             throw new \DomainException('Only pending orders can be confirmed');
 *         }
 *         $this->status = OrderStatus::Confirmed;
 *         $this->recordEvent(new OrderConfirmed($this->id));
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
abstract class AggregateRoot extends Entity implements AggregateRootInterface
{
    /**
     * @var array<DomainEventInterface> Uncommitted domain events.
     */
    private array $domainEvents = [];

    /**
     * @var int Version for optimistic concurrency control.
     */
    private int $version = 0;

    /**
     * Record a domain event.
     *
     * @param DomainEventInterface $event Event to record.
     * @return void
     */
    protected function recordEvent(DomainEventInterface $event): void
    {
        $this->domainEvents[] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function pullDomainEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Increment the version.
     *
     * Called after successful persistence.
     *
     * @return void
     */
    public function incrementVersion(): void
    {
        $this->version++;
    }

    /**
     * Set the version from persistence.
     *
     * @param int $version Version from database.
     * @return void
     */
    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    /**
     * Check if there are uncommitted events.
     *
     * @return bool True if there are pending events.
     */
    public function hasUncommittedEvents(): bool
    {
        return count($this->domainEvents) > 0;
    }

    /**
     * Get uncommitted events without clearing them.
     *
     * @return array<DomainEventInterface> Pending domain events.
     */
    public function peekDomainEvents(): array
    {
        return $this->domainEvents;
    }
}
