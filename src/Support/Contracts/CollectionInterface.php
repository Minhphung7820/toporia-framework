<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Contracts;

use Countable;
use IteratorAggregate;
use Traversable;


/**
 * Interface CollectionInterface
 *
 * Contract defining the interface for CollectionInterface implementations
 * in the Support layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface CollectionInterface extends IteratorAggregate, Countable
{
    /**
     * @inheritDoc
     * @return Traversable<TKey, TValue>
     */
    public function getIterator(): Traversable;

    /**
     * Lazily/eagerly map items.
     *
     * @template TOut
     * @param callable(TValue, TKey): TOut $callback
     * @return static<TKey, TOut>
     */
    public function map(callable $callback): static;

    /**
     * Map then flatten one level (if the callback returns iterable/Traversable/array).
     *
     * @template TOut
     * @param callable(TValue, TKey): (iterable<mixed, TOut>|TOut|null) $callback
     * @return static<array-key, TOut>
     */
    public function flatMap(callable $callback): static;

    /**
     * Concat additional iterables after current sequence.
     *
     * @param mixed ...$iters
     * @return static
     */
    public function concat(mixed ...$iters): static;

    /**
     * Filter items. If $callback is null, truthy filtering is applied.
     *
     * @param (callable(TValue, TKey): bool)|null $callback
     * @return static
     */
    public function filter(?callable $callback = null): static;

    /**
     * Reject items for which callback returns true.
     *
     * @param callable(TValue, TKey): bool $callback
     * @return static
     */
    public function reject(callable $callback): static;

    /**
     * Take first N items (N >= 0).
     *
     * @param int $limit
     * @return static
     */
    public function take(int $limit): static;

    /**
     * Take items while predicate holds.
     *
     * @param callable(TValue, TKey): bool $callback
     * @return static
     */
    public function takeWhile(callable $callback): static;

    /**
     * Take items until predicate holds (i.e., stop when callback returns true).
     *
     * @param callable(TValue, TKey): bool $callback
     * @return static
     */
    public function takeUntil(callable $callback): static;

    /**
     * Skip first N items (N >= 0).
     *
     * @param int $offset
     * @return static
     */
    public function skip(int $offset): static;

    /**
     * Skip while predicate holds, then yield the rest.
     *
     * @param callable(TValue, TKey): bool $callback
     * @return static
     */
    public function skipWhile(callable $callback): static;

    /**
     * Skip until predicate holds, then yield the rest.
     *
     * @param callable(TValue, TKey): bool $callback
     * @return static
     */
    public function skipUntil(callable $callback): static;

    /**
     * Unique items by id-resolver:
     *  - null => by item itself,
     *  - string => by array key/object property,
     *  - callable($item,$key) => custom id.
     *
     * @param string|callable(TValue, TKey): mixed|null $key
     * @return static
     */
    public function unique(string|callable|null $key = null): static;

    /**
     * Chunk stream into eager Collection chunks of fixed size (> 0).
     * Each yielded element is an eager Collection.
     *
     * @param int $size
     * @return static<array-key, Collection<array-key, TValue>>
     */
    public function chunk(int $size): static;

    /**
     * Flatten nested arrays/Collections up to $depth (INF for all).
     *
     * @param int|float $depth
     * @return static<array-key, mixed>
     */
    public function flatten(int|float $depth = INF): static;

    /**
     * Tap into the pipeline for side effects.
     *
     * @param callable(TValue, TKey): void $callback
     * @return static
     */
    public function tap(callable $callback): static;

    /**
     * Iterate and execute callback for each item (terminal).
     * Return self for fluent chaining (no-op).
     *
     * @param callable(TValue, TKey): (bool|void) $callback Return false to break early.
     * @return static
     */
    public function each(callable $callback): static;

    /**
     * Reduce items to a single value (terminal).
     *
     * @template TAcc
     * @param callable(TAcc, TValue, TKey): TAcc $callback
     * @param TAcc $initial
     * @return TAcc
     */
    public function reduce(callable $callback, mixed $initial = null): mixed;

    /**
     * True if any item satisfies predicate (terminal).
     *
     * @param callable(TValue, TKey): bool $callback
     * @return bool
     */
    public function some(callable $callback): bool;

    /**
     * True if all items satisfy predicate (terminal).
     *
     * @param callable(TValue, TKey): bool $callback
     * @return bool
     */
    public function every(callable $callback): bool;

    /**
     * Contains checks (terminal):
     *  - contains(fn($item)=>...),
     *  - contains($needle),
     *  - contains($key, $operator, $value) against arrays/objects.
     *
     * @param mixed $key
     * @param mixed $operator
     * @param mixed $value
     * @return bool
     */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool;

    /**
     * Get first item (optionally by predicate), else $default (terminal).
     *
     * @param callable(TValue, TKey): bool|null $callback
     * @param mixed $default
     * @return TValue|null
     */
    public function first(?callable $callback = null, mixed $default = null): mixed;

    /**
     * Materialize to array (terminal).
     *
     * @return array<TKey, TValue>
     */
    public function all(): array;

    /**
     * Values only (reindex).
     *
     * @return static<array-key, TValue>
     */
    public function values(): static;

    /**
     * Keys only.
     *
     * @return static<array-key, TKey>
     */
    public function keys(): static;

    /**
     * Collect into eager Collection (materialize).
     *
     * @return CollectionInterface<TKey, TValue>
     */
    public function collect(): CollectionInterface;

    /**
     * @inheritDoc
     * @return int
     */
    public function count(): int;

    /**
     * True if no items.
     *
     * @return bool
     */
    public function isEmpty(): bool;

    /**
     * True if there is at least one item.
     *
     * @return bool
     */
    public function isNotEmpty(): bool;

    /**
     * Zip with other iterables; stops when the shortest ends.
     * Each yielded item is an eager Collection row.
     *
     * @param mixed ...$arrays
     * @return static<array-key, Collection<array-key, mixed>>
     */
    public function zip(mixed ...$arrays): static;

    /**
     * Cache/remember sequence in memory for multi-pass.
     *
     * @return static
     */
    public function remember(): static;

    /**
     * Encode to JSON (materializes).
     *
     * @param int $options
     * @return string
     */
    public function toJson(int $options = 0): string;

    /**
     * Sum values (terminal).
     *  - null => sum items directly,
     *  - string => sum by key/property,
     *  - callable => sum by projection.
     *
     * @param callable(TValue): int|float|string|null|string|int|null|float $callback
     * @return int|float
     */
    public function sum(callable|string|null $callback = null): int|float;

    /**
     * Minimum by direct value / key / projection (terminal).
     *
     * @param callable(TValue): mixed|string|null $callback
     * @return mixed
     */
    public function min(callable|string|null $callback = null): mixed;

    /**
     * Maximum by direct value / key / projection (terminal).
     *
     * @param callable(TValue): mixed|string|null $callback
     * @return mixed
     */
    public function max(callable|string|null $callback = null): mixed;

    /**
     * Take every N-th item, skipping optional offset.
     *
     * @param int $step  Step > 0
     * @param int $offset
     * @return static
     */
    public function nth(int $step, int $offset = 0): static;

    /**
     * Pluck by simple path "a.b.c" or array of segments.
     *
     * @param string|array<int, string> $path
     * @return static<array-key, mixed>
     */
    public function pluck(string|array $path): static;

    /**
     * Reindex items by key selector.
     *
     * @param callable(TValue): array-key|string $key
     *               | string $key  Property/array key name
     * @return static
     */
    public function keyBy(callable|string $key): static;

    /**
     * Convert to eager Collection (alias for collect, if you differentiate).
     *
     * @return CollectionInterface<array-key, TValue>
     */
    public function toEager(): CollectionInterface;

    /**
     * Apply $fn per chunk (to reduce peak memory).
     * $fn receives array<TValue>, returns iterable of outputs (yielded one by one).
     *
     * @template TOut
     * @param int $size
     * @param callable(array<int, TValue>): iterable<mixed, TOut> $fn
     * @return static<array-key, TOut>
     */
    public function mapChunked(int $size, callable $fn): static;
}
