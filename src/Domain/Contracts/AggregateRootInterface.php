<?php

declare(strict_types=1);

namespace Toporia\Framework\Domain\Contracts;

/**
 * Interface AggregateRootInterface
 *
 * Contract for Aggregate Roots in Domain-Driven Design.
 * Aggregate roots are the entry point to an aggregate cluster.
 *
 * Key characteristics:
 * - Consistency boundary: Ensures invariants within the aggregate
 * - Transaction boundary: Changes are atomic within aggregate
 * - Event sourcing ready: Records domain events
 * - Identity: Has a unique identity across the system
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
interface AggregateRootInterface extends EntityInterface
{
    /**
     * Get all uncommitted domain events.
     *
     * @return array<DomainEventInterface> Pending domain events.
     */
    public function pullDomainEvents(): array;

    /**
     * Get the aggregate version for optimistic concurrency.
     *
     * @return int Current version number.
     */
    public function getVersion(): int;
}
