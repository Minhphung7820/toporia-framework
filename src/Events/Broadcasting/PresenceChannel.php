<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Broadcasting;

/**
 * Class PresenceChannel
 *
 * Represents a presence broadcast channel for tracking online users.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events
 * @since       2025-01-15
 */
class PresenceChannel extends Channel
{
    /**
     * Create a new presence channel instance.
     *
     * @param string $name Channel name (without 'presence-' prefix)
     */
    public function __construct(string $name)
    {
        // Auto-prepend 'presence-' prefix if not present
        if (!str_starts_with($name, 'presence-')) {
            $name = 'presence-' . $name;
        }

        parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    public function isPrivate(): bool
    {
        return true; // Presence channels are also private
    }

    /**
     * {@inheritdoc}
     */
    public function isPresence(): bool
    {
        return true;
    }
}
