<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Broadcasting;

use Toporia\Framework\Events\Contracts\BroadcastChannelInterface;

/**
 * Class Channel
 *
 * Represents a public broadcast channel.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events
 * @since       2025-01-15
 */
class Channel implements BroadcastChannelInterface
{
    /**
     * Create a new channel instance.
     *
     * @param string $name Channel name
     */
    public function __construct(
        protected string $name
    ) {}

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function isPrivate(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isPresence(): bool
    {
        return false;
    }

    /**
     * Convert to string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
