<?php

declare(strict_types=1);

namespace Toporia\Framework\Search\Contracts;


/**
 * Interface SearchClientInterface
 *
 * Contract defining the interface for SearchClientInterface
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
interface SearchClientInterface
{
    /**
     * Index a single document.
     *
     * @param string $index
     * @param string|int $id
     * @param array<string, mixed> $body
     */
    public function index(string $index, string|int $id, array $body): void;

    /**
     * Bulk index documents.
     *
     * @param iterable<array<string, mixed>> $operations
     */
    public function bulk(iterable $operations): void;

    /**
     * Delete document.
     *
     * @param string $index
     * @param string|int $id
     */
    public function delete(string $index, string|int $id): void;

    /**
     * Search.
     *
     * @param string $index
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function search(string $index, array $query): array;

    /**
     * Ensure index exists with settings/mappings.
     *
     * @param string $index
     * @param array<string, mixed> $definition
     */
    public function ensureIndex(string $index, array $definition): void;
}

