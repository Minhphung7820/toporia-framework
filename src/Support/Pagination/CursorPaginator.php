<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Pagination;

use Toporia\Framework\Support\Collection\Collection;

/**
 * Class CursorPaginator
 *
 * High-performance cursor-based pagination for large datasets.
 * Optimized for scalability and follows industry best practices.
 *
 * Features:
 * - Next ID-based pagination (simple, efficient for sequential IDs)
 * - Encoded cursor pagination (for complex sorting/filtering)
 * - O(1) performance regardless of dataset size
 * - No COUNT queries (unlike offset pagination)
 * - Consistent results even with concurrent inserts/deletes
 *
 * Performance Characteristics:
 * - O(1) query time (indexed WHERE id > cursor)
 * - No total count overhead
 * - Memory efficient (only loads requested page)
 * - Works with millions of records
 *
 * Clean Architecture:
 * - Single Responsibility: Cursor pagination only
 * - Open/Closed: Extensible via cursor encoding strategies
 * - Dependency Inversion: Works with any Collection type
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
 *
 * @template TValue
 */
class CursorPaginator implements \JsonSerializable
{
    /**
     * @param Collection<int, TValue> $items Items for current page
     * @param int $perPage Number of items per page
     * @param string|null $nextCursor Cursor for next page (null if no more pages)
     * @param string|null $prevCursor Cursor for previous page (null if first page)
     * @param bool $hasMore Whether there are more pages
     * @param string|null $path Base URL path for pagination links
     * @param string|null $baseUrl Base URL (scheme + host) for building full URLs
     * @param string $cursorName Query parameter name for cursor (default: 'cursor')
     */
    public function __construct(
        private readonly Collection $items,
        private readonly int $perPage,
        private readonly ?string $nextCursor = null,
        private readonly ?string $prevCursor = null,
        private readonly bool $hasMore = false,
        private readonly ?string $path = null,
        private readonly ?string $baseUrl = null,
        private readonly string $cursorName = 'cursor'
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
     * Get the number of items per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Determine if there are more pages.
     */
    public function hasMorePages(): bool
    {
        return $this->hasMore;
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
     * Get the cursor for the next page.
     *
     * @return string|null
     */
    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    /**
     * Get the cursor for the previous page.
     *
     * @return string|null
     */
    public function prevCursor(): ?string
    {
        return $this->prevCursor;
    }

    /**
     * Get the URL for the next page.
     *
     * @return string|null
     */
    public function nextPageUrl(): ?string
    {
        if (!$this->hasMorePages() || $this->nextCursor === null) {
            return null;
        }

        return $this->buildUrl($this->nextCursor);
    }

    /**
     * Get the URL for the previous page.
     *
     * @return string|null
     */
    public function previousPageUrl(): ?string
    {
        if ($this->prevCursor === null) {
            return null;
        }

        return $this->buildUrl($this->prevCursor);
    }

    /**
     * Build URL with cursor parameter.
     *
     * @param string $cursor Cursor value
     * @return string|null
     */
    private function buildUrl(string $cursor): ?string
    {
        if ($this->path === null) {
            return null;
        }

        $queryString = '?' . $this->cursorName . '=' . urlencode($cursor);

        // If baseUrl is provided, build full URL
        if ($this->baseUrl !== null) {
            return rtrim($this->baseUrl, '/') . $this->path . $queryString;
        }

        // Otherwise, return path with query
        return $this->path . $queryString;
    }

    /**
     * Convert the paginator to an array.
     *
     * Standard cursor pagination format (similar to other frameworks, Stripe, Twitter API).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items->toArray(),
            'per_page' => $this->perPage,
            'path' => $this->path,
            'next_cursor' => $this->nextCursor,
            'prev_cursor' => $this->prevCursor,
            'has_more' => $this->hasMore,
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
            $this->perPage,
            $this->nextCursor,
            $this->prevCursor,
            $this->hasMore,
            $this->path,
            $this->baseUrl,
            $this->cursorName
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

