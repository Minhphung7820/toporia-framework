<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus;

use Toporia\Framework\Bus\Contracts\BatchRepositoryInterface;
use Toporia\Framework\Database\Connection;

/**
 * Database Batch Repository
 *
 * Stores batches in database table.
 *
 * Performance:
 * - O(1) lookups via indexed batch_id
 * - Atomic updates for counters
 * - Lazy loading (only load when needed)
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
final class DatabaseBatchRepository implements BatchRepositoryInterface
{
    private string $table = 'job_batches';

    public function __construct(
        private Connection $connection
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function store(Batch $batch): void
    {
        $data = $batch->toArray();

        $this->connection->execute(
            "INSERT INTO {$this->table} (id, name, total_jobs, processed_jobs, failed_jobs, options, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $data['id'],
                $data['name'],
                $data['total_jobs'],
                $data['processed_jobs'],
                $data['failed_jobs'],
                json_encode($data['options']),
                $data['created_at'],
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function find(string $batchId): ?Batch
    {
        // Use query() and get first result - Connection doesn't have fetchOne()
        $results = $this->connection->query(
            "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1",
            [$batchId]
        );

        if (empty($results)) {
            return null;
        }

        return $this->hydrate($results[0]);
    }

    /**
     * {@inheritdoc}
     */
    public function update(Batch $batch): void
    {
        $data = $batch->toArray();

        $this->connection->execute(
            "UPDATE {$this->table}
             SET processed_jobs = ?, failed_jobs = ?, finished_at = ?, cancelled_at = ?
             WHERE id = ?",
            [
                $data['processed_jobs'],
                $data['failed_jobs'],
                $data['finished_at'],
                $data['cancelled_at'],
                $data['id'],
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $batchId): void
    {
        $this->connection->execute(
            "DELETE FROM {$this->table} WHERE id = ?",
            [$batchId]
        );
    }

    /**
     * {@inheritdoc}
     *
     * CRITICAL: Uses atomic SQL to prevent race conditions.
     * The finish detection is done in a single UPDATE statement
     * to avoid TOCTOU (Time-of-check to Time-of-use) race condition.
     */
    public function incrementCounts(string $batchId, int $processed, int $failed): void
    {
        // CRITICAL: Atomic increment AND finish detection in single query
        // This prevents race condition where multiple workers could:
        // 1. Both increment counter
        // 2. Both read batch (seeing different states)
        // 3. Both try to set finished_at
        //
        // With this approach, only one UPDATE sets finished_at atomically
        $this->connection->execute(
            "UPDATE {$this->table}
             SET processed_jobs = processed_jobs + ?,
                 failed_jobs = failed_jobs + ?,
                 finished_at = CASE
                     WHEN finished_at IS NULL
                          AND (processed_jobs + ?) >= total_jobs
                     THEN ?
                     ELSE finished_at
                 END
             WHERE id = ?",
            [$processed, $failed, $processed, now()->getTimestamp(), $batchId]
        );
    }

    /**
     * Hydrate batch from database row.
     *
     * @param array $row Database row
     * @return Batch
     * @throws \RuntimeException If options JSON is corrupted
     */
    private function hydrate(array $row): Batch
    {
        // CRITICAL: Validate JSON decode result to prevent silent data loss
        $optionsJson = $row['options'] ?? '{}';
        $options = json_decode($optionsJson, true);

        if ($options === null && $optionsJson !== 'null' && $optionsJson !== '') {
            // JSON decode failed - data is corrupted
            throw new \RuntimeException(
                sprintf(
                    'Batch %s has corrupted options JSON: %s',
                    $row['id'],
                    json_last_error_msg()
                )
            );
        }

        return new Batch(
            id: $row['id'],
            name: $row['name'],
            totalJobs: (int) $row['total_jobs'],
            processedJobs: (int) $row['processed_jobs'],
            failedJobs: (int) $row['failed_jobs'],
            options: $options ?? [],
            createdAt: $row['created_at'] ? (int) $row['created_at'] : null,
            finishedAt: $row['finished_at'] ? (int) $row['finished_at'] : null,
            cancelledAt: $row['cancelled_at'] ? (int) $row['cancelled_at'] : null
        );
    }
}
