<?php

declare(strict_types=1);

namespace Toporia\Framework\Search\Elasticsearch;

use Elastic\Elasticsearch\Client;
use Toporia\Framework\Search\Contracts\SearchClientInterface;

/**
 * Class ElasticsearchClient
 *
 * Provides Elasticsearch client implementation with bulk operation buffering and index management capabilities.
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
final class ElasticsearchClient implements SearchClientInterface
{
    private array $bulkBuffer = [];
    private int $lastFlushTime;

    public function __construct(
        private readonly Client $client,
        private readonly int $batchSize = 500,
        private readonly int $flushIntervalMs = 1000
    ) {
        $this->lastFlushTime = (int) (microtime(true) * 1000);
    }

    public function index(string $index, string|int $id, array $body): void
    {
        $this->client->index([
            'index' => $index,
            'id' => (string) $id,
            'body' => $body,
            'refresh' => false,
        ]);
    }

    public function bulk(iterable $operations): void
    {
        $payload = ['body' => []];
        foreach ($operations as $operation) {
            $payload['body'][] = $operation;
        }

        if (empty($payload['body'])) {
            return;
        }

        $this->client->bulk($payload);
    }

    public function delete(string $index, string|int $id): void
    {
        $this->client->delete([
            'index' => $index,
            'id' => (string) $id,
        ]);
    }

    public function search(string $index, array $query): array
    {
        $response = $this->client->search([
            'index' => $index,
            'body' => $query,
        ]);

        // Elasticsearch 8.x returns Response object, convert to array
        return $response->asArray();
    }

    public function ensureIndex(string $index, array $definition): void
    {
        $exists = $this->client->indices()->exists(['index' => $index]);
        if ($exists->asBool()) {
            return;
        }

        $this->client->indices()->create([
            'index' => $index,
            'body' => [
                'settings' => $definition['settings'] ?? [],
                'mappings' => $definition['mappings'] ?? [],
            ],
        ]);
    }

    /**
     * Buffer bulk operations for performance.
     *
     * @param array<string, mixed> $actionMeta
     * @param array<string, mixed>|null $source
     */
    public function queueBulkOperation(array $actionMeta, ?array $source = null): void
    {
        $this->bulkBuffer[] = $actionMeta;
        if ($source !== null) {
            $this->bulkBuffer[] = $source;
        }

        $now = (int) (microtime(true) * 1000);

        if (count($this->bulkBuffer) >= $this->batchSize * 2 || ($now - $this->lastFlushTime) >= $this->flushIntervalMs) {
            $this->flushBulkBuffer();
        }
    }

    public function flushBulkBuffer(): void
    {
        if (empty($this->bulkBuffer)) {
            return;
        }

        $this->client->bulk(['body' => $this->bulkBuffer]);
        $this->bulkBuffer = [];
        $this->lastFlushTime = (int) (microtime(true) * 1000);
    }

    /**
     * Delete an index.
     *
     * @param string $index Index name
     */
    public function deleteIndex(string $index): void
    {
        $exists = $this->client->indices()->exists(['index' => $index]);
        if (!$exists->asBool()) {
            return;
        }

        $this->client->indices()->delete(['index' => $index]);
    }

    /**
     * Get index statistics.
     *
     * @param string $index Index name
     * @return array<string, mixed>
     */
    public function getIndexStats(string $index): array
    {
        $response = $this->client->indices()->stats(['index' => $index]);
        return $response->asArray();
    }

    /**
     * Check if an index exists.
     *
     * @param string $index Index name
     * @return bool
     */
    public function indexExists(string $index): bool
    {
        $response = $this->client->indices()->exists(['index' => $index]);
        return $response->asBool();
    }

    /**
     * Refresh an index to make recent changes searchable.
     *
     * @param string $index Index name
     */
    public function refresh(string $index): void
    {
        $this->client->indices()->refresh(['index' => $index]);
    }

    public function __destruct()
    {
        $this->flushBulkBuffer();
    }
}
