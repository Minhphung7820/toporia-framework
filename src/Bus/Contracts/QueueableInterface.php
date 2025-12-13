<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus\Contracts;


/**
 * Interface QueueableInterface
 *
 * Contract defining the interface for QueueableInterface implementations
 * in the Command/Query/Job dispatching layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Bus\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface QueueableInterface
{
    /**
     * Get the queue name.
     *
     * @return string|null
     */
    public function getQueue(): ?string;

    /**
     * Set the queue name.
     *
     * @param string $queue Queue name
     * @return self
     */
    public function onQueue(string $queue): self;

    /**
     * Get the delay in seconds.
     *
     * @return int
     */
    public function getDelay(): int;

    /**
     * Set the delay in seconds.
     *
     * @param int $delay Delay in seconds
     * @return self
     */
    public function delay(int $delay): self;
}
