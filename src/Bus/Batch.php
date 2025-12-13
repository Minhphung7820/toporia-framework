<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus;

use Toporia\Framework\Bus\Contracts\BatchRepositoryInterface;

/**
 * Batch
 *
 * Represents a batch of jobs with progress tracking.
 *
 * Performance:
 * - O(1) status checks
 * - Lazy repository updates
 * - Atomic counter updates
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
final class Batch
{
    private ?BatchRepositoryInterface $repository = null;

    public function __construct(
        private string $id,
        private string $name,
        private int $totalJobs,
        private int $processedJobs = 0,
        private int $failedJobs = 0,
        private array $options = [],
        private ?int $createdAt = null,
        private ?int $finishedAt = null,
        private ?int $cancelledAt = null
    ) {
        $this->createdAt = $createdAt ?? now()->getTimestamp();
    }

    /**
     * Get batch ID.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get batch name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get total jobs count.
     */
    public function totalJobs(): int
    {
        return $this->totalJobs;
    }

    /**
     * Get processed jobs count.
     */
    public function processedJobs(): int
    {
        return $this->processedJobs;
    }

    /**
     * Get failed jobs count.
     */
    public function failedJobs(): int
    {
        return $this->failedJobs;
    }

    /**
     * Get pending jobs count.
     */
    public function pendingJobs(): int
    {
        return $this->totalJobs - $this->processedJobs;
    }

    /**
     * Get progress percentage (0-100).
     */
    public function progress(): int
    {
        if ($this->totalJobs === 0) {
            return 100;
        }

        return (int) (($this->processedJobs / $this->totalJobs) * 100);
    }

    /**
     * Check if batch is finished.
     */
    public function finished(): bool
    {
        return $this->finishedAt !== null;
    }

    /**
     * Check if batch is cancelled.
     */
    public function cancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    /**
     * Check if all jobs succeeded.
     */
    public function hasFailures(): bool
    {
        return $this->failedJobs > 0;
    }

    /**
     * Increment processed/failed counts.
     */
    public function incrementCounts(int $processed = 1, int $failed = 0): void
    {
        $this->processedJobs += $processed;
        $this->failedJobs += $failed;

        // Auto-finish when all jobs processed
        if ($this->processedJobs >= $this->totalJobs) {
            $this->finishedAt = now()->getTimestamp();
        }

        // Persist to repository
        if ($this->repository) {
            $this->repository->incrementCounts($this->id, $processed, $failed);
        }
    }

    /**
     * Cancel the batch.
     */
    public function cancel(): void
    {
        $this->cancelledAt = now()->getTimestamp();

        if ($this->repository) {
            $this->repository->update($this);
        }
    }

    /**
     * Set the repository.
     */
    public function setRepository(BatchRepositoryInterface $repository): void
    {
        $this->repository = $repository;
    }

    /**
     * Get option value.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'total_jobs' => $this->totalJobs,
            'processed_jobs' => $this->processedJobs,
            'failed_jobs' => $this->failedJobs,
            'pending_jobs' => $this->pendingJobs(),
            'progress' => $this->progress(),
            'finished' => $this->finished(),
            'cancelled' => $this->cancelled(),
            'has_failures' => $this->hasFailures(),
            'options' => $this->options,
            'created_at' => $this->createdAt,
            'finished_at' => $this->finishedAt,
            'cancelled_at' => $this->cancelledAt,
        ];
    }
}
