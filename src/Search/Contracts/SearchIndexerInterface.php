<?php

declare(strict_types=1);

namespace Toporia\Framework\Search\Contracts;


/**
 * Interface SearchIndexerInterface
 *
 * Contract defining the interface for SearchIndexerInterface
 * implementations in the Elasticsearch integration layer of the Toporia
 * Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Search\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface SearchIndexerInterface
{
    /**
     * Index or update a document.
     *
     * @param string $index
     * @param string|int $id
     * @param array<string, mixed> $document
     */
    public function upsert(string $index, string|int $id, array $document): void;

    /**
     * Remove a document from index.
     *
     * @param string $index
     * @param string|int $id
     */
    public function remove(string $index, string|int $id): void;

    /**
     * Bulk sync multiple documents.
     *
     * @param string $index
     * @param iterable<array{ id: string|int, body: array<string, mixed> }> $documents
     */
    public function bulkUpsert(string $index, iterable $documents): void;
}

