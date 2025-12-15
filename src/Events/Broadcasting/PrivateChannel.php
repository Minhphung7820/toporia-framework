<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Broadcasting;

/**
 * Class PrivateChannel
 *
 * Represents a private broadcast channel that requires authorization.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events
 * @since       2025-01-15
 */
class PrivateChannel extends Channel
{
    /**
     * Create a new private channel instance.
     *
     * @param string $name Channel name (without 'private-' prefix)
     */
    public function __construct(string $name)
    {
        // Auto-prepend 'private-' prefix if not present
        if (!str_starts_with($name, 'private-')) {
            $name = 'private-' . $name;
        }

        parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    public function isPrivate(): bool
    {
        return true;
    }
}
