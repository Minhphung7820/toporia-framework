<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus;

use Toporia\Framework\Bus\Contracts\DispatcherInterface;
use Toporia\Framework\Bus\Contracts\QueueableInterface;

/**
 * Pending Dispatch
 *
 * Fluent API for configuring and dispatching commands/jobs.
 *
 * Performance:
 * - Lazy execution (only dispatches when needed)
 * - Zero-copy command modification (returns self)
 * - O(1) configuration changes
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
 *
 * @template T
 */
final class PendingDispatch
{
    private bool $afterResponse = false;
    private bool $dispatched = false;

    /**
     * @param DispatcherInterface $dispatcher Dispatcher instance
     * @param mixed $command Command/Job instance
     */
    public function __construct(
        private DispatcherInterface $dispatcher,
        private mixed $command
    ) {
    }

    /**
     * Set the queue name.
     *
     * @param string $queue Queue name
     * @return self
     */
    public function onQueue(string $queue): self
    {
        if ($this->command instanceof QueueableInterface) {
            $this->command->onQueue($queue);
        }

        return $this;
    }

    /**
     * Set the delay in seconds.
     *
     * @param int $delay Delay in seconds
     * @return self
     */
    public function delay(int $delay): self
    {
        if ($this->command instanceof QueueableInterface) {
            $this->command->delay($delay);
        }

        return $this;
    }

    /**
     * Dispatch after the current response is sent.
     *
     * @return self
     */
    public function afterResponse(): self
    {
        $this->afterResponse = true;
        return $this;
    }

    /**
     * Explicitly dispatch the command now.
     *
     * Call this to dispatch immediately instead of waiting for destructor.
     * Prevents duplicate dispatch on destruct.
     *
     * @return mixed Dispatch result
     */
    public function dispatch(): mixed
    {
        if ($this->dispatched) {
            return null; // Already dispatched
        }

        $this->dispatched = true;

        if ($this->afterResponse) {
            $this->dispatcher->dispatchAfterResponse($this->command);
            return null;
        }

        return $this->dispatcher->dispatch($this->command);
    }

    /**
     * Handle the object's destruction (auto-dispatch).
     *
     * Only dispatches if not already explicitly dispatched.
     * Exceptions in destructors are converted to fatal errors by PHP,
     * so we wrap in try-catch to prevent silent failures.
     */
    public function __destruct()
    {
        if ($this->dispatched) {
            return; // Already dispatched explicitly
        }

        $this->dispatched = true;

        try {
            if ($this->afterResponse) {
                $this->dispatcher->dispatchAfterResponse($this->command);
            } else {
                $this->dispatcher->dispatch($this->command);
            }
        } catch (\Throwable $e) {
            // Log error - exceptions in destructors cause fatal errors
            error_log("PendingDispatch auto-dispatch failed: " . $e->getMessage());
        }
    }
}
