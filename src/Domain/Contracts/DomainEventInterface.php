<?php

declare(strict_types=1);

namespace Toporia\Framework\Domain\Contracts;

/**
 * Interface DomainEventInterface
 *
 * Contract for Domain Events in Domain-Driven Design.
 * Domain events capture something that happened in the domain.
 *
 * Key characteristics:
 * - Immutable: Events are facts, they cannot change
 * - Named in past tense: OrderPlaced, UserRegistered, PaymentReceived
 * - Contains all relevant data: Self-contained information about what happened
 * - Timestamped: When the event occurred
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Domain\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface DomainEventInterface
{
    /**
     * Get the unique event identifier.
     *
     * @return string UUID of this specific event occurrence.
     */
    public function getEventId(): string;

    /**
     * Get the event name.
     *
     * @return string Event name (e.g., 'order.placed', 'user.registered').
     */
    public function getEventName(): string;

    /**
     * Get when the event occurred.
     *
     * @return \DateTimeImmutable Timestamp of event occurrence.
     */
    public function getOccurredAt(): \DateTimeImmutable;

    /**
     * Get the aggregate ID that raised this event.
     *
     * @return int|string|null Aggregate root ID.
     */
    public function getAggregateId(): int|string|null;

    /**
     * Get the aggregate type that raised this event.
     *
     * @return string Aggregate class name.
     */
    public function getAggregateType(): string;

    /**
     * Get event payload as array.
     *
     * @return array<string, mixed> Event data.
     */
    public function toArray(): array;
}
