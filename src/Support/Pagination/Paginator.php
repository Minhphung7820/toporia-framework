<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Pagination;

use Toporia\Framework\Support\Collection\Collection;


/**
 * Class Paginator
 *
 * Core class for the Pagination layer providing essential functionality
 * for the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Pagination
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class Paginator implements \JsonSerializable
{
    /**
     * @param Collection<int, TValue> $items Items for current page
     * @param int $total Total number of items across all pages
     * @param int $perPage Number of items per page
     * @param int $currentPage Current page number (1-indexed)
     * @param string|null $path Base URL path for pagination links
     * @param string|null $baseUrl Base URL (scheme + host) for building full URLs
     */
    public function __construct(
        private readonly Collection $items,
        private readonly int $total,
        private readonly int $perPage,
        private readonly int $currentPage = 1,
        private readonly ?string $path = null,
        private readonly ?string $baseUrl = null
    ) {}

    /**
     * Get the items for the current page.
     *
     * @return Collection<int, TValue>
     */
    public function items(): Collection
    {
        return $this->items;
    }

    /**
     * Get the total number of items.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Get the number of items per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get the current page number.
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Get the last page number.
     */
    public function lastPage(): int
    {
        return (int) ceil($this->total / $this->perPage);
    }

    /**
     * Get the number of the first item on the page.
     */
    public function firstItem(): int
    {
        if ($this->total === 0) {
            return 0;
        }
        return ($this->currentPage - 1) * $this->perPage + 1;
    }

    /**
     * Get the number of the last item on the page.
     */
    public function lastItem(): int
    {
        return min($this->firstItem() + $this->items->count() - 1, $this->total);
    }

    /**
     * Determine if there are more pages.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    /**
     * Determine if there are no items.
     */
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * Determine if there are items.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Get the URL for a given page number.
     *
     * Returns full URL (with domain) if baseUrl is provided, otherwise returns path with query.
     *
     * @param int $page Page number
     * @return string|null
     */
    public function url(int $page): ?string
    {
        if ($this->path === null) {
            return null;
        }

        // Build query string with page parameter
        $queryString = '?page=' . $page;

        // If baseUrl is provided, build full URL
        if ($this->baseUrl !== null) {
            return rtrim($this->baseUrl, '/') . $this->path . $queryString;
        }

        // Otherwise, return path with query
        return $this->path . $queryString;
    }

    /**
     * Get the URL for the next page.
     */
    public function nextPageUrl(): ?string
    {
        if (!$this->hasMorePages()) {
            return null;
        }

        return $this->url($this->currentPage + 1);
    }

    /**
     * Get the URL for the previous page.
     */
    public function previousPageUrl(): ?string
    {
        if ($this->currentPage <= 1) {
            return null;
        }

        return $this->url($this->currentPage - 1);
    }

    /**
     * Convert the paginator to an array.
     *
     * Standard pagination format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items->toArray(),
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'last_page' => $this->lastPage(),
            'from' => $this->firstItem(),
            'to' => $this->lastItem(),
            'path' => $this->path,
            'first_page_url' => $this->url(1),
            'last_page_url' => $this->url($this->lastPage()),
            'next_page_url' => $this->nextPageUrl(),
            'prev_page_url' => $this->previousPageUrl(),
        ];
    }

    /**
     * Convert the paginator to JSON.
     *
     * Implements JsonSerializable for automatic JSON encoding.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the paginator to JSON string.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Get the items as a plain array (shortcut).
     *
     * @return array<int, mixed>
     */
    public function all(): array
    {
        return $this->items->all();
    }

    /**
     * Apply a callback to each item.
     *
     * @param callable $callback
     * @return static New paginator with transformed items
     */
    public function map(callable $callback): static
    {
        return new static(
            $this->items->map($callback),
            $this->total,
            $this->perPage,
            $this->currentPage,
            $this->path,
            $this->baseUrl
        );
    }

    /**
     * Magic method to make items iterable.
     *
     * Allows: foreach ($paginator as $item)
     *
     * @return \Traversable<int, TValue>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items->all());
    }
}
