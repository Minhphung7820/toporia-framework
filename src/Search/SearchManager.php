<?php

declare(strict_types=1);

namespace Toporia\Framework\Search;

use Toporia\Framework\Search\Contracts\{SearchClientInterface, SearchIndexerInterface, SearchQueryBuilderInterface};
use Toporia\Framework\Search\Query\SearchQueryBuilder;

/**
 * Class SearchManager
 *
 * Central facade for managing search operations, providing unified access to client, indexer, and query builder components.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Search
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SearchManager
{
    /**
     * @param array<string, array<string, mixed>> $indices
     */
    public function __construct(
        private readonly SearchClientInterface $client,
        private readonly SearchIndexerInterface $indexer,
        private readonly array $indices = []
    ) {
    }

    public function client(): SearchClientInterface
    {
        return $this->client;
    }

    public function indexer(): SearchIndexerInterface
    {
        return $this->indexer;
    }

    public function query(): SearchQueryBuilderInterface
    {
        return new SearchQueryBuilder();
    }

    public function ensureIndices(): void
    {
        foreach ($this->indices as $definition) {
            if (!isset($definition['name'])) {
                continue;
            }
            $this->client->ensureIndex($definition['name'], $definition);
        }
    }

    public function search(string $index, array $query): array
    {
        return $this->client->search($index, $query);
    }
}

