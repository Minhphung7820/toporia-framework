<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus;

use Toporia\Framework\Bus\Contracts\DispatcherInterface;
use Toporia\Framework\Bus\Contracts\QueueableInterface;

/**
 * Pending Chain
 *
 * Fluent API for creating and dispatching sequential job chains.
 *
 * Performance:
 * - Lazy execution (only executes when dispatched)
 * - O(1) option setting
 * - Zero-copy job passing
 * - Early termination on failure (stops immediately)
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
final class PendingChain
{
    private ?string $queue = null;
    private int $delay = 0;
    /** @var callable|null */
    private $catchCallback = null;
    /** @var callable|null */
    private $finallyCallback = null;

    /**
     * @param DispatcherInterface $dispatcher Dispatcher instance
     * @param array<mixed> $jobs Jobs to chain (executed sequentially)
     */
    public function __construct(
        private DispatcherInterface $dispatcher,
        private array $jobs = []
    ) {}

    /**
     * Set the queue name for all jobs in the chain.
     *
     * @param string $queue Queue name
     * @return self
     */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Set the delay in seconds for all jobs in the chain.
     *
     * @param int $delay Delay in seconds
     * @return self
     */
    public function delay(int $delay): self
    {
        $this->delay = $delay;
        return $this;
    }

    /**
     * Set callback to run if any job fails.
     *
     * @param callable $callback Callback receives (Throwable $exception, int $jobIndex, mixed $job)
     * @return self
     */
    public function catch(callable $callback): self
    {
        $this->catchCallback = $callback;
        return $this;
    }

    /**
     * Set callback to run after chain completes (success or failure).
     *
     * @param callable $callback Callback receives (bool $success, ?Throwable $exception)
     * @return self
     */
    public function finally(callable $callback): self
    {
        $this->finallyCallback = $callback;
        return $this;
    }

    /**
     * Dispatch the chain (execute jobs sequentially).
     *
     * Performance: O(N) where N = number of jobs
     * - Early termination on failure (stops immediately)
     * - Zero-copy job passing
     * - Direct handler execution (no queue overhead for sync jobs)
     *
     * @return mixed Result from last job, or null if chain failed
     * @throws \Throwable If any job fails and no catch callback is set
     */
    public function dispatch(): mixed
    {
        if (empty($this->jobs)) {
            return null;
        }

        $lastResult = null;
        $exception = null;
        $failedIndex = null;

        try {
            // Execute jobs sequentially
            // Performance: O(N) sequential execution, early termination on failure
            foreach ($this->jobs as $index => $job) {
                // Configure queue and delay if job supports it
                // Note: Even if job is queueable, we force sync execution for chain
                $this->configureJob($job);

                // Force synchronous execution to ensure sequential order
                // This ensures chain executes jobs one after another, regardless of queueable status
                $lastResult = $this->dispatcher->dispatchSync($job);
            }

            // All jobs completed successfully
            if ($this->finallyCallback !== null) {
                ($this->finallyCallback)(true, null);
            }

            return $lastResult;
        } catch (\Throwable $e) {
            $exception = $e;
            $failedIndex = $index ?? null;

            // Call catch callback if set
            if ($this->catchCallback !== null) {
                ($this->catchCallback)($e, $failedIndex, $this->jobs[$failedIndex] ?? null);
            }

            // Call finally callback
            if ($this->finallyCallback !== null) {
                ($this->finallyCallback)(false, $e);
            }

            // Re-throw if no catch callback (fail fast)
            if ($this->catchCallback === null) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Configure job with queue and delay if supported.
     *
     * Performance: O(1) - Simple property check and assignment
     *
     * @param mixed $job Job instance
     * @return void
     */
    private function configureJob(mixed $job): void
    {
        if (!($job instanceof QueueableInterface)) {
            return;
        }

        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }

        if ($this->delay > 0) {
            $job->delay($this->delay);
        }
    }
}
