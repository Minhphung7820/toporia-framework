<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus;


/**
 * Trait Queueable
 *
 * Trait providing reusable functionality for Queueable in the
 * Command/Query/Job dispatching layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Bus
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait Queueable
{
    /**
     * Queue name.
     */
    protected ?string $queue = null;

    /**
     * Delay in seconds.
     */
    protected int $delay = 0;

    /**
     * Get the queue name.
     */
    public function getQueue(): ?string
    {
        return $this->queue;
    }

    /**
     * Set the queue name.
     */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Get the delay in seconds.
     */
    public function getDelay(): int
    {
        return $this->delay;
    }

    /**
     * Set the delay in seconds.
     */
    public function delay(int $delay): self
    {
        $this->delay = $delay;
        return $this;
    }
}
