<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus\Contracts;

use Toporia\Framework\Bus\Batch;


/**
 * Interface BatchRepositoryInterface
 *
 * Contract defining the interface for BatchRepositoryInterface
 * implementations in the Command/Query/Job dispatching layer of the
 * Toporia Framework.
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
interface BatchRepositoryInterface
{
    /**
     * Store a batch.
     *
     * @param \Toporia\Framework\Bus\Batch $batch Batch instance
     * @return void
     */
    public function store(Batch $batch): void;

    /**
     * Retrieve a batch by ID.
     *
     * @param string $batchId Batch ID
     * @return \Toporia\Framework\Bus\Batch|null
     */
    public function find(string $batchId): ?Batch;

    /**
     * Update a batch.
     *
     * @param \Toporia\Framework\Bus\Batch $batch Batch instance
     * @return void
     */
    public function update(Batch $batch): void;

    /**
     * Delete a batch.
     *
     * @param string $batchId Batch ID
     * @return void
     */
    public function delete(string $batchId): void;

    /**
     * Increment job counts.
     *
     * @param string $batchId Batch ID
     * @param int $processed Processed count
     * @param int $failed Failed count
     * @return void
     */
    public function incrementCounts(string $batchId, int $processed, int $failed): void;
}
