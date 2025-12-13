<?php

declare(strict_types=1);

namespace Toporia\Framework\Search\Elasticsearch;

use Toporia\Framework\Search\Contracts\{SearchClientInterface, SearchIndexerInterface};

/**
 * Class ElasticsearchIndexer
 *
 * Handles document indexing operations including single and bulk upsert and removal from Elasticsearch indices.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Search\Elasticsearch
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ElasticsearchIndexer implements SearchIndexerInterface
{
    public function __construct(
        private readonly SearchClientInterface $client
    ) {
    }

    public function upsert(string $index, string|int $id, array $document): void
    {
        $this->client->index($index, $id, $document);
    }

    public function remove(string $index, string|int $id): void
    {
        $this->client->delete($index, $id);
    }

    public function bulkUpsert(string $index, iterable $documents): void
    {
        $ops = [];

        foreach ($documents as $doc) {
            $ops[] = [
                'index' => [
                    '_index' => $index,
                    '_id' => (string) $doc['id'],
                ],
            ];
            $ops[] = $doc['body'];
        }

        $this->client->bulk($ops);
    }
}

