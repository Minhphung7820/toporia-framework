<?php

declare(strict_types=1);

namespace Toporia\Framework\Events;

use Toporia\Framework\Events\Contracts\{EventInterface, ListenerInterface, ShouldQueue};
use Toporia\Framework\Events\Exceptions\ListenerException;
use Toporia\Framework\Queue\Contracts\QueueInterface;

/**
 * Class QueuedListener
 *
 * Wraps a listener to queue it for asynchronous execution with support
 * for delayed execution and background processing.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class QueuedListener implements ListenerInterface, ShouldQueue
{
    /**
     * The wrapped listener.
     *
     * Note: Using mixed instead of union type because callable cannot be
     * part of a property union type in PHP.
     *
     * @var string|ListenerInterface|callable
     */
    private mixed $listener;

    /**
     * @param callable|string|ListenerInterface $listener The listener to queue
     * @param QueueInterface $queue Queue instance
     * @param int $delay Delay in seconds before execution
     * @throws ListenerException If listener is a Closure (not serializable)
     */
    public function __construct(
        callable|string|ListenerInterface $listener,
        private QueueInterface $queue,
        private int $delay = 0
    ) {
        // CRITICAL: Closures cannot be serialized for queue storage
        // Validate early to fail fast with clear error message
        if ($listener instanceof \Closure) {
            throw ListenerException::closureNotQueueable();
        }

        $this->listener = $listener;
    }

    /**
     * Handle the event by queuing it.
     *
     * @param EventInterface $event The event instance
     * @return void
     */
    public function handle(EventInterface $event): void
    {
        $job = new ListenerJob($this->listener, $event);

        if ($this->delay > 0) {
            $this->queue->later($job, $this->delay);
        } else {
            $this->queue->push($job);
        }
    }

    /**
     * Get the wrapped listener.
     *
     * @return string|ListenerInterface|callable
     */
    public function getListener(): mixed
    {
        return $this->listener;
    }

    /**
     * Get the delay in seconds.
     *
     * @return int
     */
    public function getDelay(): int
    {
        return $this->delay;
    }
}
