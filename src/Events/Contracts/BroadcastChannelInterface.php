<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Contracts;

/**
 * Interface BroadcastChannelInterface
 *
 * Represents a broadcast channel that events can be sent to.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events
 * @since       2025-01-15
 */
interface BroadcastChannelInterface
{
    /**
     * Get the channel name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if this is a private channel.
     *
     * @return bool
     */
    public function isPrivate(): bool;

    /**
     * Check if this is a presence channel.
     *
     * @return bool
     */
    public function isPresence(): bool;
}
