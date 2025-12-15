<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Broadcasting;

/**
 * Trait InteractsWithBroadcasting
 *
 * Provides default implementations for ShouldBroadcast interface methods.
 * Use this trait in your event classes to get sensible defaults.
 *
 * Usage:
 *   class OrderShipped extends Event implements ShouldBroadcast
 *   {
 *       use InteractsWithBroadcasting;
 *
 *       public function broadcastOn(): string
 *       {
 *           return 'orders';
 *       }
 *   }
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events
 * @since       2025-01-15
 */
trait InteractsWithBroadcasting
{
    /**
     * Get the event name to broadcast as.
     *
     * Default: null (uses event class name)
     *
     * @return string|null
     */
    public function broadcastAs(): ?string
    {
        return null;
    }

    /**
     * Get the data to broadcast with the event.
     *
     * Default: empty array (serializes all public properties)
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }

    /**
     * Determine if this event should broadcast.
     *
     * Default: true (always broadcast)
     *
     * @return bool
     */
    public function broadcastIf(): bool
    {
        return true;
    }
}
