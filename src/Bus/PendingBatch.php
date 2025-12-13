<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus;

use Toporia\Framework\Bus\Contracts\BatchRepositoryInterface;
use Toporia\Framework\Bus\Contracts\DispatcherInterface;

/**
 * Pending Batch
 *
 * Fluent API for creating and dispatching batches.
 *
 * Performance:
 * - Lazy execution (only creates batch when dispatched)
 * - O(1) option setting
 * - Bulk job dispatching
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
final class PendingBatch
{
    private string $name = '';
    private array $options = [];

    /**
     * @param BatchRepositoryInterface $repository Batch repository
     * @param DispatcherInterface $dispatcher Dispatcher
     * @param array<mixed> $jobs Jobs to batch
     */
    public function __construct(
        private BatchRepositoryInterface $repository,
        private DispatcherInterface $dispatcher,
        private array $jobs = []
    ) {
    }

    /**
     * Set the batch name.
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set an option.
     */
    public function option(string $key, mixed $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    /**
     * Set callback to run when batch finishes.
     */
    public function then(callable $callback): self
    {
        $this->options['then'] = $callback;
        return $this;
    }

    /**
     * Set callback to run if any job fails.
     */
    public function catch(callable $callback): self
    {
        $this->options['catch'] = $callback;
        return $this;
    }

    /**
     * Set callback to run after batch completes (success or failure).
     */
    public function finally(callable $callback): self
    {
        $this->options['finally'] = $callback;
        return $this;
    }

    /**
     * Allow failures without stopping the batch.
     */
    public function allowFailures(bool $allow = true): self
    {
        $this->options['allow_failures'] = $allow;
        return $this;
    }

    /**
     * Dispatch the batch.
     */
    public function dispatch(): Batch
    {
        $batch = new Batch(
            id: $this->generateId(),
            name: $this->name ?: 'Batch',
            totalJobs: count($this->jobs),
            options: $this->options
        );

        $batch->setRepository($this->repository);

        // Store batch
        $this->repository->store($batch);

        // Dispatch all jobs
        foreach ($this->jobs as $job) {
            // Set batch ID on job if it supports it
            if (method_exists($job, 'setBatchId')) {
                $job->setBatchId($batch->id());
            }

            $this->dispatcher->dispatch($job);
        }

        return $batch;
    }

    /**
     * Generate unique batch ID.
     *
     * Uses random_bytes() for cryptographically secure ID generation.
     * This prevents collisions in high-throughput batch creation scenarios.
     *
     * @return string UUID-like batch ID
     */
    private function generateId(): string
    {
        // Generate 16 random bytes (128 bits) for UUID-like uniqueness
        // This is cryptographically secure and collision-resistant
        $bytes = random_bytes(16);

        // Format as batch_xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
        return sprintf(
            'batch_%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6))
        );
    }
}
