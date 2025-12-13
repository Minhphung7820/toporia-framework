<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Resource;

use Countable;
use IteratorAggregate;
use Traversable;
use ArrayIterator;
use JsonSerializable;
use Toporia\Framework\Support\Pagination\Paginator;

/**
 * Class ResourceCollection
 *
 * Collection wrapper for JSON resources.
 * Handles array/collection of resources with pagination support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\Resource
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class ResourceCollection implements JsonSerializable, Countable, IteratorAggregate
{
    /**
     * The resource that this collection collects.
     *
     * @var class-string<JsonResource>
     */
    public string $collects;

    /**
     * The resource collection.
     *
     * @var array<JsonResource>
     */
    protected array $collection = [];

    /**
     * Original resource (may be paginator).
     *
     * @var mixed
     */
    protected mixed $resource;

    /**
     * Additional data to merge.
     *
     * @var array<string, mixed>
     */
    protected array $additional = [];

    /**
     * The wrapper key.
     *
     * @var string|null
     */
    protected ?string $wrapper = 'data';

    /**
     * Pagination metadata.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $pagination = null;

    /**
     * Create a new resource collection.
     *
     * @param mixed $resource
     * @param class-string<JsonResource>|null $collects
     */
    public function __construct(mixed $resource, ?string $collects = null)
    {
        $this->resource = $resource;
        $this->collects = $collects ?? JsonResource::class;
        $this->wrapper = JsonResource::$wrap;

        $this->collectResource($resource);
    }

    /**
     * Collect resources from source.
     *
     * @param mixed $resource
     * @return void
     */
    protected function collectResource(mixed $resource): void
    {
        // Handle Paginator
        if ($resource instanceof Paginator) {
            $this->pagination = $this->extractPaginationMeta($resource);
            $items = $resource->getItems();
        } elseif (is_array($resource)) {
            $items = $resource;
        } elseif ($resource instanceof Traversable) {
            $items = iterator_to_array($resource);
        } else {
            $items = [$resource];
        }

        $this->collection = array_map(
            fn($item) => $this->transformItem($item),
            $items
        );
    }

    /**
     * Transform single item to resource.
     *
     * @param mixed $item
     * @return JsonResource
     */
    protected function transformItem(mixed $item): JsonResource
    {
        if ($item instanceof JsonResource) {
            return $item;
        }

        $resourceClass = $this->collects;
        return new $resourceClass($item);
    }

    /**
     * Extract pagination metadata.
     *
     * @param Paginator $paginator
     * @return array<string, mixed>
     */
    protected function extractPaginationMeta(Paginator $paginator): array
    {
        return [
            'current_page' => $paginator->getCurrentPage(),
            'per_page' => $paginator->getPerPage(),
            'total' => $paginator->getTotal(),
            'total_pages' => $paginator->getTotalPages(),
            'has_more' => $paginator->hasMorePages(),
            'from' => $paginator->getFrom(),
            'to' => $paginator->getTo(),
        ];
    }

    /**
     * Convert collection to array.
     *
     * @return array<int, array>
     */
    public function toArray(): array
    {
        return array_map(
            fn(JsonResource $resource) => $resource->toArray(),
            $this->collection
        );
    }

    /**
     * Add additional data.
     *
     * @param array<string, mixed> $additional
     * @return static
     */
    public function additional(array $additional): static
    {
        $this->additional = array_merge($this->additional, $additional);
        return $this;
    }

    /**
     * Set wrapper key.
     *
     * @param string|null $wrapper
     * @return static
     */
    public function wrap(?string $wrapper): static
    {
        $this->wrapper = $wrapper;
        return $this;
    }

    /**
     * Disable wrapping.
     *
     * @return static
     */
    public function withoutWrapping(): static
    {
        return $this->wrap(null);
    }

    /**
     * Resolve collection to array.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $data = $this->toArray();

        $result = [];

        if ($this->wrapper !== null) {
            $result[$this->wrapper] = $data;
        } else {
            $result = $data;
        }

        // Add pagination meta
        if ($this->pagination !== null) {
            $result['meta'] = array_merge(
                $result['meta'] ?? [],
                ['pagination' => $this->pagination]
            );
        }

        // Merge additional data
        if (!empty($this->additional)) {
            $result = array_merge($result, $this->additional);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return count($this->collection);
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->collection);
    }

    /**
     * Get raw collection.
     *
     * @return array<JsonResource>
     */
    public function getCollection(): array
    {
        return $this->collection;
    }

    /**
     * Get original resource.
     *
     * @return mixed
     */
    public function getResource(): mixed
    {
        return $this->resource;
    }

    /**
     * Check if collection is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->collection);
    }

    /**
     * Check if collection is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Map over collection.
     *
     * @param callable $callback
     * @return array
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->collection);
    }

    /**
     * Filter collection.
     *
     * @param callable $callback
     * @return static
     */
    public function filter(callable $callback): static
    {
        $clone = clone $this;
        $clone->collection = array_filter($this->collection, $callback);
        return $clone;
    }

    /**
     * Get first resource.
     *
     * @return JsonResource|null
     */
    public function first(): ?JsonResource
    {
        return $this->collection[0] ?? null;
    }

    /**
     * Get last resource.
     *
     * @return JsonResource|null
     */
    public function last(): ?JsonResource
    {
        return $this->collection[count($this->collection) - 1] ?? null;
    }
}
