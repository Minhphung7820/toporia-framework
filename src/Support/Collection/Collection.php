<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Collection;

use Toporia\Framework\Support\Collection\LazyCollection;
use Toporia\Framework\Support\Contracts\CollectionInterface;
use Toporia\Framework\Support\Macroable;
use Toporia\Framework\Support\Pagination\Paginator;


/**
 * Class Collection
 *
 * Core class for the Collection layer providing essential functionality
 * for the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Collection
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class Collection implements CollectionInterface, \JsonSerializable
{
    use Macroable;
    /**
     * @param array<TKey, TValue> $items
     */
    public function __construct(
        protected array $items = []
    ) {}

    /**
     * Create new collection from items.
     */
    public static function make(mixed $items = []): static
    {
        if ($items instanceof self) {
            return new static($items->all());
        }

        if ($items instanceof \Traversable) {
            return new static(iterator_to_array($items));
        }

        return new static((array) $items);
    }

    /**
     * Create collection from range.
     */
    public static function range(int $start, int $end, int $step = 1): static
    {
        return new static(range($start, $end, $step));
    }

    /**
     * Create collection by repeating value.
     */
    public static function times(int $count, callable $callback): static
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = $callback($i);
        }
        return new static($items);
    }

    /**
     * Wrap value in collection if not already.
     */
    public static function wrap(mixed $value): static
    {
        if ($value instanceof self) {
            return $value;
        }

        return new static(is_array($value) ? $value : [$value]);
    }

    /**
     * Get all items as array.
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Convert the collection to a plain array.
     *
     * Alias of all() for compatibility with Paginator and other components.
     *
     * @return array<int|string, TValue>
     */
    public function toArray(): array
    {
        return $this->all();
    }

    public function collect(): Collection
    {
        return $this;
    }

    /**
     * Get first item matching callback or default.
     */
    public function first(callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? $default : reset($this->items);
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get last item matching callback or default.
     */
    public function last(callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? $default : end($this->items);
        }

        $items = $this->items;
        end($items);
        while (($key = key($items)) !== null) {
            $value = current($items);
            if ($callback($value, $key)) {
                return $value;
            }
            prev($items);
        }
        return $default;
    }

    /**
     * Get item at index or default.
     */
    public function at(int $index, mixed $default = null): mixed
    {
        if ($index < 0) return $default;
        $i = 0;
        foreach ($this->items as $v) {
            if ($i === $index) return $v;
            $i++;
        }
        return $default;
    }

    public function nth(int $step, int $offset = 0): static
    {
        if ($step <= 0) {
            throw new \InvalidArgumentException('Step must be > 0.');
        }
        $out = [];
        $i = 0;
        foreach ($this->items as $k => $v) {
            if ($i++ < $offset) continue;
            if ((($i - $offset - 1) % $step) === 0) {
                $out[$k] = $v;
            }
        }
        return new static($out);
    }

    /**
     * Map over items.
     */
    public function map(callable $callback): static
    {
        $out = [];
        foreach ($this->items as $k => $v) {
            $out[$k] = $callback($v, $k);
        }
        return new static($out);
    }

    /**
     * Map with keys preserved and callback receives value and key.
     */
    public function mapWithKeys(callable $callback): static
    {
        $result = [];

        foreach ($this->items as $key => $value) {
            $assoc = $callback($value, $key);

            foreach ($assoc as $mapKey => $mapValue) {
                $result[$mapKey] = $mapValue;
            }
        }

        return new static($result);
    }

    /**
     * Flat map over items.
     */
    public function flatMap(callable $callback): static
    {
        return $this->map($callback)->flatten(1);
    }

    /**
     * Filter items using callback.
     */
    public function filter(callable $callback = null): static
    {
        if ($callback === null) {
            return new static(array_filter($this->items));
        }

        return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Filter and reject items.
     */
    public function reject(callable $callback): static
    {
        return $this->filter(fn($value, $key) => !$callback($value, $key));
    }

    /**
     * Reduce collection to single value.
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Check if any item passes test.
     */
    public function some(callable $callback): bool
    {
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all items pass test.
     */
    public function every(callable $callback): bool
    {
        foreach ($this->items as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if collection contains item.
     */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            if (is_callable($key)) {
                return $this->some($key);
            }

            return in_array($key, $this->items, true);
        }

        return $this->some(fn($item) => $this->compareValues($item[$key] ?? null, $operator, $value));
    }

    /**
     * Flatten multi-dimensional collection.
     */
    public function flatten(int|float $depth = INF): static
    {
        if ($depth === INF) {
            $depth = PHP_INT_MAX;
        }

        if ($depth <= 0) {
            return new static($this->items);
        }

        $out = [];
        $push = function ($value, int $d) use (&$out, &$push) {
            if ($d > 0 && (is_array($value) || $value instanceof self)) {
                $iter = $value instanceof self ? $value->all() : $value;
                foreach ($iter as $v) {
                    $push($v, $d - 1);
                }
            } else {
                $out[] = $value;
            }
        };

        foreach ($this->items as $v) {
            $push($v, (int) $depth);
        }

        return new static($out);
    }

    /**
     * Get unique items.
     */
    public function unique(callable|string|null $by = null): static
    {
        $keyer = is_string($by)
            ? fn($v) => is_array($v) ? ($v[$by] ?? null) : (is_object($v) ? ($v->{$by} ?? null) : null)
            : ($by ?? fn($v) => $v);

        $seen = [];
        $out  = [];
        foreach ($this->items as $v) {
            $k = $keyer($v);
            $kk = is_scalar($k) ? $k : md5(serialize($k));
            if (!isset($seen[$kk])) {
                $seen[$kk] = 1;
                $out[] = $v;
            }
        }
        return new static($out);
    }

    /**
     * Sort collection.
     */
    public function sort(callable $callback = null): static
    {
        $items = $this->items;

        if ($callback === null) {
            asort($items);
        } else {
            uasort($items, $callback);
        }

        return new static($items);
    }

    /**
     * Sort by key.
     */
    public function sortBy(string|callable $callback, bool $descending = false): static
    {
        $extract = is_callable($callback)
            ? $callback
            : fn($item) => is_array($item) ? ($item[$callback] ?? null) : (is_object($item) ? ($item->{$callback} ?? null) : null);

        $decorated = [];
        foreach ($this->items as $k => $it) {
            $decorated[] = [$extract($it), $k, $it];
        }

        usort($decorated, static function ($a, $b) use ($descending) {
            $cmp = $a[0] <=> $b[0];
            return $descending ? -$cmp : $cmp;
        });

        $out = [];
        foreach ($decorated as $row) {
            [$key, $origKey, $val] = $row;
            $out[$origKey] = $val;
        }
        return new static($out);
    }

    /**
     * Sort in descending order.
     */
    public function sortDesc(): static
    {
        return $this->sort(fn($a, $b) => $b <=> $a);
    }

    /**
     * Reverse collection order.
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    /**
     * Take first N items.
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    /**
     * Skip first N items.
     */
    public function skip(int $offset): static
    {
        return $this->slice($offset);
    }

    /**
     * Slice collection.
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    /**
     * Chunk collection into smaller collections.
     */
    public function chunk(int $size): static
    {
        $chunks = [];

        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    /**
     * Split collection into N groups.
     */
    public function split(int $numberOfGroups): static
    {
        if ($this->isEmpty()) {
            return new static();
        }

        $groupSize = (int) ceil($this->count() / $numberOfGroups);

        return $this->chunk($groupSize);
    }

    /**
     * Group items by key or callback.
     */
    public function groupBy(string|callable $key): static
    {
        $callback = is_callable($key) ? $key : fn($item) => $item[$key] ?? null;

        $groups = [];

        foreach ($this->items as $k => $item) {
            $groupKey = $callback($item, $k);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }

            $groups[$groupKey][$k] = $item;
        }

        return new static(array_map(fn($group) => new static($group), $groups));
    }

    /**
     * Partition into two collections based on callback.
     */
    public function partition(callable $callback): array
    {
        $passed = [];
        $failed = [];

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                $passed[$key] = $value;
            } else {
                $failed[$key] = $value;
            }
        }

        return [new static($passed), new static($failed)];
    }

    /**
     * Zip with other collections.
     */
    public function zip(mixed ...$arrays): static
    {
        $arrayableItems = array_map(function ($items) {
            return $items instanceof self ? $items->all() : $items;
        }, $arrays);

        $params = array_merge([array_values($this->items)], array_map(fn($items) => array_values($items), $arrayableItems));

        return new static(array_map(fn(...$items) => new static($items), ...$params));
    }

    /**
     * Merge with other collections.
     */
    public function merge(mixed ...$arrays): static
    {
        $result = $this->items;

        foreach ($arrays as $items) {
            if ($items instanceof self) $items = $items->all();
            foreach ($items as $k => $v) {
                $result[$k] = $v;
            }
        }
        return new static($result);
    }

    /**
     * Combine with values.
     */
    public function combine(mixed $values): static
    {
        $values = $values instanceof self ? $values->all() : $values;

        return new static(array_combine($this->items, $values));
    }

    /**
     * Get values only (reset keys).
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Get keys only.
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /**
     * Pluck values by key.
     */
    public function pluckAssoc(string $value, ?string $key = null): static
    {
        $results = [];
        foreach ($this->items as $item) {
            $itemValue = is_array($item) ? ($item[$value] ?? null) : ($item->$value ?? null);
            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
                $results[$itemKey] = $itemValue;
            }
        }
        return new static($results);
    }

    public function pluck(string|array $path): static
    {
        $segments = is_array($path) ? $path : explode('.', $path);
        $results = [];
        foreach ($this->items as $item) {
            $current = $item;
            foreach ($segments as $seg) {
                if (is_array($current)) {
                    $current = $current[$seg] ?? null;
                } elseif (is_object($current)) {
                    $current = $current->{$seg} ?? null;
                } else {
                    $current = null;
                    break;
                }
            }
            $results[] = $current;
        }
        return new static($results);
    }

    public function concat(mixed ...$iters): static
    {
        $result = $this->items;
        foreach ($iters as $it) {
            if ($it instanceof self) {
                $it = $it->all();
            }
            foreach ((array) $it as $k => $v) {
                $result[] = $v;
            }
        }
        return new static($result);
    }

    public function remember(): static
    {
        return $this;
    }

    public function toEager(): Collection
    {
        return $this;
    }
    /**
     * Get min value.
     *
     * Returns null for empty collections instead of throwing an error.
     *
     * @param string|callable|null $callback Key name or callback to extract value
     * @return mixed Minimum value or null if empty
     */
    public function min(string|callable|null $callback = null): mixed
    {
        if ($this->isEmpty()) {
            return null;
        }

        if ($callback === null) {
            $filtered = array_filter($this->items, fn($v) => $v !== null);
            return empty($filtered) ? null : min($filtered);
        }

        $extract = is_callable($callback)
            ? $callback
            : fn($item) => is_array($item) ? ($item[$callback] ?? null) : (is_object($item) ? ($item->{$callback} ?? null) : null);

        $values = [];
        foreach ($this->items as $k => $v) {
            $val = $extract($v, $k);
            if ($val !== null) {
                $values[] = $val;
            }
        }

        return empty($values) ? null : min($values);
    }

    /**
     * Get max value.
     *
     * Returns null for empty collections instead of throwing an error.
     *
     * @param string|callable|null $callback Key name or callback to extract value
     * @return mixed Maximum value or null if empty
     */
    public function max(string|callable|null $callback = null): mixed
    {
        if ($this->isEmpty()) {
            return null;
        }

        $extract = $callback === null
            ? fn($v) => $v
            : (is_callable($callback)
                ? $callback
                : fn($item) => is_array($item) ? ($item[$callback] ?? null) : (is_object($item) ? ($item->{$callback} ?? null) : null));

        $has = false;
        $max = null;

        foreach ($this->items as $k => $v) {
            $val = $extract($v, $k);
            if ($val === null) {
                continue;
            }
            if (!$has || $val > $max) {
                $max = $val;
                $has = true;
            }
        }

        return $max;
    }

    /**
     * Sum values.
     */
    public function sum(string|callable|null $callback = null): int|float
    {
        $total = 0;
        if ($callback === null) {
            foreach ($this->items as $v) {
                $total += is_numeric($v) ? $v : 0;
            }
            return $total;
        }

        // Check string first, then callable (since string can be callable too)
        if (is_string($callback)) {
            $extract = fn($item) => is_array($item) ? ($item[$callback] ?? 0) : (is_object($item) ? ($item->{$callback} ?? 0) : 0);
        } else {
            $extract = $callback;
        }

        foreach ($this->items as $k => $v) {
            $val = $extract($v, $k);
            $total += is_numeric($val) ? $val : 0;
        }
        return $total;
    }

    /**
     * Average values.
     */
    public function avg(string|callable|null $callback = null): int|float|null
    {
        $count = $this->count();

        if ($count === 0) {
            return null;
        }

        return $this->sum($callback) / $count;
    }

    /**
     * Median value.
     */
    public function median(string|callable|null $callback = null): int|float|null
    {
        $values = $callback === null
            ? $this->filter(fn($item) => is_numeric($item))
            : $this->map($callback)->filter(fn($item) => is_numeric($item));

        $count = $values->count();
        if ($count === 0) {
            return null;
        }

        $sorted = $values->sort()->values();
        $middle = (int) ($count / 2);

        if ($count % 2 === 0) {
            return ((float)$sorted->at($middle - 1) + (float)$sorted->at($middle)) / 2;
        }

        return (float)$sorted->at($middle);
    }


    /**
     * Mode (most frequent value).
     *
     * Returns an array of the most frequently occurring values.
     * Supports non-scalar values by hashing them for comparison.
     *
     * @param string|callable|null $callback Key name or callback to extract value
     * @return array Most frequent values
     */
    public function mode(string|callable|null $callback = null): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $extract = $callback === null
            ? fn($v) => $v
            : (is_callable($callback)
                ? $callback
                : fn($item) => is_array($item) ? ($item[$callback] ?? null) : (is_object($item) ? ($item->{$callback} ?? null) : null));

        $counts = [];
        $valueMap = []; // Map hash -> original value for non-scalars

        foreach ($this->items as $k => $v) {
            $value = $extract($v, $k);

            // Handle non-scalar values by hashing
            if (is_scalar($value) || is_null($value)) {
                $key = $value === null ? '__null__' : (string)$value;
                $valueMap[$key] = $value;
            } else {
                $key = md5(serialize($value));
                $valueMap[$key] = $value;
            }

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        if (empty($counts)) {
            return [];
        }

        $maxCount = max($counts);

        $modes = [];
        foreach ($counts as $key => $count) {
            if ($count === $maxCount) {
                $modes[] = $valueMap[$key];
            }
        }

        return $modes;
    }

    /**
     * Get item by key or default.
     */
    public function get(mixed $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    /**
     * Check if key exists.
     */
    public function has(mixed $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Get only specified keys.
     */
    public function only(array $keys): static
    {
        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    /**
     * Get all except specified keys.
     */
    public function except(array $keys): static
    {
        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    /**
     * Count items.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Check if collection is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Check if collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Execute callback on each item.
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Pipe collection through callback.
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * Tap into collection without modifying it.
     */
    public function tap(callable $callback): static
    {
        $callback(clone $this);

        return $this;
    }

    /**
     * Apply callback when condition is true.
     */
    public function when(bool $condition, callable $callback, ?callable $default = null): static
    {
        if ($condition) {
            return $callback($this);
        }

        if ($default !== null) {
            return $default($this);
        }

        return $this;
    }

    /**
     * Apply callback unless condition is true.
     */
    public function unless(bool $condition, callable $callback, ?callable $default = null): static
    {
        return $this->when(!$condition, $callback, $default);
    }

    /**
     * Get items as JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->items, $options);
    }

    /**
     * Get iterator.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * Check if offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Get item at offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * Set item at offset (throws exception - immutable).
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('Collection is immutable');
    }

    /**
     * Unset item at offset (throws exception - immutable).
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('Collection is immutable');
    }

    /**
     * Convert to string.
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Compare values with operator.
     */
    protected function compareValues(mixed $a, mixed $operator, mixed $b): bool
    {
        return match ($operator) {
            '=', '==' => $a == $b,
            '===' => $a === $b,
            '!=', '<>' => $a != $b,
            '!==' => $a !== $b,
            '<' => $a < $b,
            '>' => $a > $b,
            '<=' => $a <= $b,
            '>=' => $a >= $b,
            default => false,
        };
    }

    // ========================================
    // ADVANCED METHODS
    // ========================================

    /**
     * Create sliding windows of items.
     *
     * Example: [1,2,3,4]->window(2) = [[1,2], [2,3], [3,4]]
     */
    public function window(int $size, int $step = 1): static
    {
        if ($size <= 0) {
            return new static();
        }

        $windows = [];
        $values = array_values($this->items);
        $count = count($values);

        for ($i = 0; $i <= $count - $size; $i += $step) {
            $windows[] = new static(array_slice($values, $i, $size));
        }

        return new static($windows);
    }

    /**
     * Get pairs of consecutive items.
     *
     * Example: [1,2,3,4]->pairs() = [[1,2], [2,3], [3,4]]
     */
    public function pairs(): static
    {
        return $this->window(2, 1);
    }

    /**
     * Transpose matrix (2D collection).
     *
     * Example: [[1,2], [3,4]]->transpose() = [[1,3], [2,4]]
     */
    public function transpose(): static
    {
        if ($this->isEmpty()) {
            return new static();
        }

        $items = array_map(function ($items) {
            return $items instanceof self ? $items->all() : $items;
        }, $this->items);

        $result = [];
        $maxLength = max(array_map('count', $items));

        for ($i = 0; $i < $maxLength; $i++) {
            $result[] = new static(array_column($items, $i));
        }

        return new static($result);
    }

    /**
     * Get cartesian product with other collections.
     *
     * Example: [1,2]->crossJoin([3,4]) = [[1,3], [1,4], [2,3], [2,4]]
     */
    public function crossJoin(mixed ...$arrays): static
    {
        $results = [[]];

        $arrays = array_merge([$this->all()], array_map(function ($items) {
            return $items instanceof self ? $items->all() : $items;
        }, $arrays));

        foreach ($arrays as $index => $array) {
            $append = [];

            foreach ($results as $product) {
                foreach ($array as $item) {
                    $product[$index] = $item;
                    $append[] = $product;
                }
            }

            $results = $append;
        }

        return new static(array_map(fn($result) => new static($result), $results));
    }

    /**
     * Diff with other collection.
     */
    public function diff(mixed $items): static
    {
        $other = $items instanceof self ? $items->all() : (array)$items;
        $set = [];
        foreach ($other as $x) {
            $k = is_scalar($x) ? $x : md5(serialize($x));
            $set[$k] = true;
        }
        $out = [];
        foreach ($this->items as $v) {
            $k = is_scalar($v) ? $v : md5(serialize($v));
            if (!isset($set[$k])) $out[] = $v;
        }
        return new static($out);
    }

    /**
     * Diff keys with other collection.
     */
    public function diffKeys(mixed $items): static
    {
        $items = $items instanceof self ? $items->all() : $items;

        return new static(array_diff_key($this->items, $items));
    }

    /**
     * Intersect with other collection.
     */
    public function intersect(mixed $items): static
    {
        $other = $items instanceof self ? $items->all() : (array)$items;
        $set = [];
        foreach ($other as $x) {
            $k = is_scalar($x) ? $x : md5(serialize($x));
            $set[$k] = true;
        }
        $out = [];
        foreach ($this->items as $v) {
            $k = is_scalar($v) ? $v : md5(serialize($v));
            if (isset($set[$k])) $out[] = $v;
        }
        return new static($out);
    }
    /**
     * Compute the set difference against another collection/array using a derived key.
     *
     * Keeps the original keys from the current collection. A value from $this
     * is kept only if its projected key (via $keySelector) does NOT appear in $items.
     *
     * Time complexity: O(n + m) where n = size of $this and m = size of $items.
     *
     * @param mixed                $items        A Collection or array (optionally Traversable) to compare against.
     * @param callable(mixed):mixed $keySelector A function that takes an item and returns a comparable key.
     *
     * @return static A new collection containing items unique to $this by the projected key.
     *
     * @example
     *  $a = Collection::make([['id' => 1], ['id' => 2], ['id' => 3]]);
     *  $b = [['id' => 2]];
     *  $diff = $a->diffBy($b, fn($x) => $x['id']); // => items with id 1 and 3
     */
    public function diffBy(mixed $items, callable $keySelector): static
    {
        $other = $items instanceof self ? $items->all() : (array)$items;
        $set = [];
        foreach ($other as $x) $set[self::keyOf($x, $keySelector)] = true;

        $out = [];
        foreach ($this->items as $idx => $v) {
            $k = self::keyOf($v, $keySelector);
            if (!isset($set[$k])) $out[$idx] = $v;
        }
        return new static($out);
    }
    /**
     * Compute the set intersection with another collection/array using a derived key.
     *
     * Keeps the original keys from the current collection. A value from $this
     * is kept only if its projected key (via $keySelector) DOES appear in $items.
     *
     * Time complexity: O(n + m) where n = size of $this and m = size of $items.
     *
     * @param mixed                $items        A Collection or array (optionally Traversable) to intersect with.
     * @param callable(mixed):mixed $keySelector A function that takes an item and returns a comparable key.
     *
     * @return static A new collection containing items common by the projected key.
     *
     * @example
     *  $a = Collection::make([['id' => 1], ['id' => 2], ['id' => 3]]);
     *  $b = [['id' => 2], ['id' => 4]];
     *  $inter = $a->intersectBy($b, fn($x) => $x['id']); // => item with id 2
     */
    public function intersectBy(mixed $items, callable $keySelector): static
    {
        $other = $items instanceof self ? $items->all() : (array)$items;
        $set = [];
        foreach ($other as $x) $set[self::keyOf($x, $keySelector)] = true;

        $out = [];
        foreach ($this->items as $idx => $v) {
            $k = self::keyOf($v, $keySelector);
            if (isset($set[$k])) $out[$idx] = $v;
        }
        return new static($out);
    }
    /**
     * Normalize any projected key to a stable string representation.
     *
     * Scalars are string-cast directly. Non-scalars are hashed (see hashAny()) to
     * produce a deterministic string key suitable for set membership checks.
     *
     * @internal
     *
     * @param mixed                  $v   The value to project.
     * @param callable(mixed):mixed  $sel The selector that extracts a comparison key from $v.
     *
     * @return string A stable string key for use in hash sets/maps.
     */
    private static function keyOf(mixed $v, callable $sel): string
    {
        $k = $sel($v);
        return is_scalar($k) ? (string)$k : self::hashAny($k);
    }

    /**
     * Hash any value to a stable string representation.
     *
     * Uses JSON encoding for arrays/objects to produce deterministic hashes.
     *
     * @internal
     *
     * @param mixed $value The value to hash.
     *
     * @return string A stable hash string.
     */
    private static function hashAny(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        // For arrays and objects, use stable JSON encoding
        return md5(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Intersect keys with other collection.
     */
    public function intersectKeys(mixed $items): static
    {
        $items = $items instanceof self ? $items->all() : $items;

        return new static(array_intersect_key($this->items, $items));
    }

    /**
     * Union with other collection.
     */
    public function union(mixed $items): static
    {
        $items = $items instanceof self ? $items->all() : $items;

        return new static($this->items + $items);
    }

    /**
     * Pad collection to specified size.
     */
    public function pad(int $size, mixed $value): static
    {
        return new static(array_pad($this->items, $size, $value));
    }

    /**
     * Get random item(s).
     */
    public function random(int $number = 1): mixed
    {
        $count = $this->count();

        if ($number > $count) {
            throw new \InvalidArgumentException(
                "Cannot get {$number} random items from collection with {$count} items"
            );
        }

        if ($number === 1) {
            return $this->items[array_rand($this->items)];
        }

        $keys = array_rand($this->items, $number);
        $keys = is_array($keys) ? $keys : [$keys];

        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    /**
     * Shuffle items.
     */
    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);

        return new static($items);
    }

    /**
     * Apply callback N times.
     */
    public function pipe_times(int $times, callable $callback): static
    {
        $result = $this;

        for ($i = 0; $i < $times; $i++) {
            $result = $callback($result, $i);
        }

        return $result;
    }

    /**
     * Sliding reduce (apply reduce with sliding window).
     */
    public function scanl(callable $callback, mixed $initial = null): static
    {
        $results = [$initial];
        $accumulator = $initial;

        foreach ($this->items as $key => $value) {
            $accumulator = $callback($accumulator, $value, $key);
            $results[] = $accumulator;
        }

        return new static($results);
    }

    /**
     * Take items while callback returns true.
     */
    public function takeWhile(callable $callback): static
    {
        $result = [];

        foreach ($this->items as $key => $value) {
            if (!$callback($value, $key)) {
                break;
            }

            $result[$key] = $value;
        }

        return new static($result);
    }

    /**
     * Take items until callback returns true.
     */
    public function takeUntil(callable $callback): static
    {
        return $this->takeWhile(fn($value, $key) => !$callback($value, $key));
    }

    /**
     * Skip items while callback returns true.
     */
    public function skipWhile(callable $callback): static
    {
        $shouldTake = false;
        $result = [];

        foreach ($this->items as $key => $value) {
            if (!$shouldTake && !$callback($value, $key)) {
                $shouldTake = true;
            }

            if ($shouldTake) {
                $result[$key] = $value;
            }
        }

        return new static($result);
    }

    /**
     * Skip items until callback returns true.
     */
    public function skipUntil(callable $callback): static
    {
        return $this->skipWhile(fn($value, $key) => !$callback($value, $key));
    }

    /**
     * Get duplicates.
     *
     * Returns the first occurrence of each duplicate item.
     * Supports non-scalar values by hashing them for comparison.
     *
     * @param string|callable|null $key Key name or callback to extract comparison value
     * @return static Collection containing duplicate items
     */
    public function duplicates(string|callable|null $key = null): static
    {
        $extract = is_callable($key)
            ? $key
            : fn($item) => $key === null
                ? $item
                : (is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null));

        $counts = [];
        $duplicates = [];

        foreach ($this->items as $k => $item) {
            $value = $extract($item, $k);

            // Hash non-scalar values for comparison
            $hashKey = is_scalar($value) || is_null($value)
                ? ($value === null ? '__null__' : (is_bool($value) ? ($value ? '__true__' : '__false__') : (string)$value))
                : md5(serialize($value));

            if (!isset($counts[$hashKey])) {
                $counts[$hashKey] = 0;
            }

            $counts[$hashKey]++;

            if ($counts[$hashKey] === 2) {
                $duplicates[$k] = $item;
            }
        }

        return new static($duplicates);
    }

    /**
     * Ensure all items are unique (throw exception if duplicates).
     */
    public function ensureUnique(string|callable|null $key = null): static
    {
        $duplicates = $this->duplicates($key);

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException('Collection contains duplicate items');
        }

        return $this;
    }

    /**
     * Count occurrences of each value.
     */
    public function countBy(string|callable|null $callback = null): static
    {
        if ($callback === null) {
            return new static(array_count_values($this->items));
        }

        $callback = is_callable($callback) ? $callback : fn($item) => $item[$callback] ?? null;

        $counts = [];

        foreach ($this->items as $key => $value) {
            $group = $callback($value, $key);

            if (!isset($counts[$group])) {
                $counts[$group] = 0;
            }

            $counts[$group]++;
        }

        return new static($counts);
    }

    /**
     * Frequency analysis (get most/least common items).
     */
    public function frequencies(): static
    {
        $counts = $this->countBy();

        return $counts->map(fn($count, $value) => [
            'value' => $value,
            'count' => $count,
            'percentage' => ($count / $this->count()) * 100
        ])->sortBy('count', true);
    }

    /**
     * Sliding aggregate (like moving average).
     */
    public function sliding(int $windowSize, callable $aggregator): static
    {
        return $this->window($windowSize)->map($aggregator);
    }

    /**
     * Moving average.
     */
    public function movingAverage(int $windowSize): static
    {
        return $this->sliding($windowSize, fn($window) => $window->avg());
    }

    /**
     * Create paginator result.
     */
    /**
     * Paginate the collection.
     *
     * Returns a Paginator value object for consistent pagination across the framework.
     *
     * Clean Architecture & SOLID:
     * - Reuses Paginator class (DRY principle)
     * - Single source of truth for pagination logic
     * - Consistent API with ModelQueryBuilder::paginate()
     *
     * @param int $perPage Number of items per page
     * @param int $page Current page number (1-indexed)
     * @param string|null $path Base URL path for pagination links
     * @return \Toporia\Framework\Support\Pagination\Paginator<TValue>
     */
    public function paginate(int $perPage = 15, int $page = 1, ?string $path = null): Paginator
    {
        // Validate parameters
        if ($perPage < 1) {
            throw new \InvalidArgumentException('Per page must be at least 1');
        }
        if ($page < 1) {
            throw new \InvalidArgumentException('Page must be at least 1');
        }

        $offset = ($page - 1) * $perPage;
        $items = $this->slice($offset, $perPage);
        $total = $this->count();

        return new Paginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            path: $path
        );
    }

    /**
     * Recursive map (for nested arrays/collections).
     */
    public function mapRecursive(callable $callback): static
    {
        $map = function ($items) use ($callback, &$map) {
            $result = [];

            foreach ($items as $key => $value) {
                if (is_array($value) || $value instanceof self) {
                    $value = $map($value instanceof self ? $value->all() : $value);
                    $result[$key] = new static($value);
                } else {
                    $result[$key] = $callback($value, $key);
                }
            }

            return $result;
        };

        return new static($map($this->items));
    }

    /**
     * Deep flatten (recursive).
     */
    public function flattenDeep(): static
    {
        return $this->flatten(PHP_INT_MAX);
    }

    /**
     * Search for value and return key.
     */
    public function search(mixed $value, bool $strict = false): mixed
    {
        if (is_callable($value)) {
            foreach ($this->items as $key => $item) {
                if ($value($item, $key)) {
                    return $key;
                }
            }

            return false;
        }

        return array_search($value, $this->items, $strict);
    }

    /**
     * Replace items by key.
     */
    public function replace(mixed $items): static
    {
        $items = $items instanceof self ? $items->all() : $items;

        return new static(array_replace($this->items, $items));
    }

    /**
     * Replace recursively.
     */
    public function replaceRecursive(mixed $items): static
    {
        $items = $items instanceof self ? $items->all() : $items;

        return new static(array_replace_recursive($this->items, $items));
    }

    /**
     * Sole item (get single item or throw exception).
     */
    public function sole(callable $callback = null): mixed
    {
        $items = $callback === null ? $this : $this->filter($callback);

        $count = $items->count();

        if ($count === 0) {
            throw new \RuntimeException('No items found');
        }

        if ($count > 1) {
            throw new \RuntimeException('Multiple items found');
        }

        return $items->first();
    }

    /**
     * First or throw exception.
     *
     * Unlike first(), this properly checks if the collection is empty
     * rather than checking if the result is null (which could be a valid value).
     *
     * @param callable|null $callback Optional filter callback
     * @return mixed First item matching the callback
     * @throws \RuntimeException When no items are found
     */
    public function firstOrFail(callable $callback = null): mixed
    {
        $items = $callback === null ? $this : $this->filter($callback);

        if ($items->isEmpty()) {
            throw new \RuntimeException('No items found');
        }

        return $items->first();
    }

    public function keyBy(callable|string $key): static
    {
        $items = [];
        if (is_string($key)) {
            foreach ($this->items as $item) {
                $k = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
                $items[$k] = $item;
            }
        } else {
            foreach ($this->items as $item) {
                $items[$key($item)] = $item;
            }
        }
        return new static($items);
    }

    public function firstWhere(string|callable $key, mixed $operator = null, mixed $value = null): mixed
    {
        if (is_callable($key)) {
            foreach ($this->items as $item) {
                if ($key($item)) return $item;
            }
            return null;
        }

        // Chuẩn hoá toán tử
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        foreach ($this->items as $item) {
            $current = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
            $ok = match ($operator) {
                '=', '==' => $current ==  $value,
                '===', 'is' => $current === $value,
                '!=', '<>' => $current !=  $value,
                '!=='      => $current !== $value,
                '>'        => $current >   $value,
                '>='       => $current >=  $value,
                '<'        => $current <   $value,
                '<='       => $current <=  $value,
                'in'       => is_array($value) && in_array($current, $value, true),
                'not in'   => is_array($value) && !in_array($current, $value, true),
                default    => false,
            };
            if ($ok) return $item;
        }
        return null;
    }

    public function sortKeys(int $flags = SORT_REGULAR): static
    {
        $items = $this->items;
        ksort($items, $flags);
        return new static($items);
    }

    public function implode(string|callable $value, string $glue = ''): string
    {
        if (is_string($value)) {
            $arr = [];
            foreach ($this->items as $item) {
                $arr[] = is_array($item) ? ($item[$value] ?? '') : (is_object($item) ? ($item->{$value} ?? '') : (string)$item);
            }
            return implode($glue, $arr);
        }

        $arr = [];
        foreach ($this->items as $item) $arr[] = (string)$value($item);
        return implode($glue, $arr);
    }

    /**
     * Join chuỗi kiểu "a, b and c" (hữu ích cho UI copywriting)
     */
    public function join(string $glue = ', ', string $finalGlue = ' and '): string
    {
        $arr = array_values($this->items);
        $count = count($arr);
        if ($count === 0) return '';
        if ($count === 1) return (string)$arr[0];
        return implode($glue, array_slice($arr, 0, -1)) . $finalGlue . $arr[$count - 1];
    }

    public function mapInto(string $class): static
    {
        $items = [];
        foreach ($this->items as $item) {
            $items[] = new $class($item);
        }
        return new static($items);
    }

    /**
     * callback($item, $key) => ['groupKey' => value]
     */
    public function mapToGroups(callable $callback): static
    {
        $groups = [];
        foreach ($this->items as $k => $v) {
            $pair = $callback($v, $k);
            foreach ($pair as $gk => $gv) {
                $groups[$gk][] = $gv;
            }
        }
        // Trả về Collection<groupKey => Collection>
        foreach ($groups as $gk => $list) {
            $groups[$gk] = new static($list);
        }
        return new static($groups);
    }

    public function toLazy(): LazyCollection
    {
        return LazyCollection::make(function () {
            foreach ($this->items as $item) {
                yield $item;
            }
        });
    }

    public function mapChunked(int $size, callable $fn): static
    {
        if ($size <= 0) throw new \InvalidArgumentException('Chunk size must be > 0.');
        $out = [];
        $chunk = [];
        foreach ($this->items as $item) {
            $chunk[] = $item;
            if (count($chunk) === $size) {
                foreach ($fn($chunk) as $mapped) $out[] = $mapped;
                $chunk = [];
            }
        }
        if ($chunk) {
            foreach ($fn($chunk) as $mapped) $out[] = $mapped;
        }
        return new static($out);
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * This method is called automatically by json_encode().
     * For ModelCollection, this will call toArray() which converts models to arrays.
     * For regular Collection, this returns the raw items array.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        // Check if this collection has a custom toArray implementation
        // ModelCollection overrides toArray() to convert models to arrays
        if (method_exists($this, 'toArray') && get_class($this) !== self::class) {
            return $this->toArray();
        }

        // For base Collection, return items as-is
        return $this->items;
    }

    // ========================================
    // WHERE METHODS (Laravel-compatible)
    // ========================================

    /**
     * Filter items where key equals value (strict comparison).
     *
     * @param string $key Key to check
     * @param mixed $value Value to match (strict ===)
     * @return static
     */
    public function whereStrict(string $key, mixed $value): static
    {
        return $this->filter(function ($item) use ($key, $value) {
            $actual = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
            return $actual === $value;
        });
    }

    /**
     * Filter items where key value is between min and max.
     *
     * @param string $key Key to check
     * @param mixed $min Minimum value (inclusive)
     * @param mixed $max Maximum value (inclusive)
     * @return static
     */
    public function whereBetween(string $key, mixed $min, mixed $max): static
    {
        return $this->filter(function ($item) use ($key, $min, $max) {
            $value = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
            return $value !== null && $value >= $min && $value <= $max;
        });
    }

    /**
     * Filter items where key value is not between min and max.
     *
     * @param string $key Key to check
     * @param mixed $min Minimum value
     * @param mixed $max Maximum value
     * @return static
     */
    public function whereNotBetween(string $key, mixed $min, mixed $max): static
    {
        return $this->filter(function ($item) use ($key, $min, $max) {
            $value = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
            return $value === null || $value < $min || $value > $max;
        });
    }

    /**
     * Filter items where key value is in the given array.
     *
     * @param string $key Key to check
     * @param array $values Allowed values
     * @param bool $strict Use strict comparison
     * @return static
     */
    public function whereIn(string $key, array $values, bool $strict = false): static
    {
        return $this->filter(function ($item) use ($key, $values, $strict) {
            $value = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
            return in_array($value, $values, $strict);
        });
    }

    /**
     * Filter items where key value is not in the given array.
     *
     * @param string $key Key to check
     * @param array $values Disallowed values
     * @param bool $strict Use strict comparison
     * @return static
     */
    public function whereNotIn(string $key, array $values, bool $strict = false): static
    {
        return $this->filter(function ($item) use ($key, $values, $strict) {
            $value = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
            return !in_array($value, $values, $strict);
        });
    }

    /**
     * Filter items where key value is null.
     *
     * @param string $key Key to check
     * @return static
     */
    public function whereNull(string $key): static
    {
        return $this->filter(function ($item) use ($key) {
            $value = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
            return $value === null;
        });
    }

    /**
     * Filter items where key value is not null.
     *
     * @param string $key Key to check
     * @return static
     */
    public function whereNotNull(string $key): static
    {
        return $this->filter(function ($item) use ($key) {
            $value = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
            return $value !== null;
        });
    }

    /**
     * Filter items that are instances of the given class.
     *
     * @param string $class Class name
     * @return static
     */
    public function whereInstanceOf(string $class): static
    {
        return $this->filter(fn($item) => $item instanceof $class);
    }

    /**
     * Filter items using a where clause (supports operators).
     *
     * @param string $key Key to check
     * @param mixed $operator Operator or value (if value is omitted)
     * @param mixed $value Value to compare (optional)
     * @return static
     */
    public function where(string $key, mixed $operator = null, mixed $value = null): static
    {
        // If only 2 args, treat as where($key, $value) with '=' operator
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->filter(function ($item) use ($key, $operator, $value) {
            $actual = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
            return $this->compareValues($actual, $operator, $value);
        });
    }

    // ========================================
    // SORT METHODS
    // ========================================

    /**
     * Sort by key in descending order.
     *
     * @param string|callable $callback Key name or callback
     * @return static
     */
    public function sortByDesc(string|callable $callback): static
    {
        return $this->sortBy($callback, true);
    }

    /**
     * Sort keys in descending order.
     *
     * @param int $flags Sort flags (SORT_REGULAR, SORT_NUMERIC, etc.)
     * @return static
     */
    public function sortKeysDesc(int $flags = SORT_REGULAR): static
    {
        $items = $this->items;
        krsort($items, $flags);
        return new static($items);
    }

    // ========================================
    // DOT NOTATION METHODS
    // ========================================

    /**
     * Flatten a multi-dimensional associative array with dots.
     *
     * Example:
     * ```php
     * $collection = collect(['products' => ['desk' => ['price' => 100]]]);
     * $flattened = $collection->dot();
     * // ['products.desk.price' => 100]
     * ```
     *
     * @param string $prepend Prefix for keys
     * @return static
     */
    public function dot(string $prepend = ''): static
    {
        $results = [];

        $flatten = function (array $array, string $prepend) use (&$flatten, &$results) {
            foreach ($array as $key => $value) {
                $newKey = $prepend === '' ? $key : $prepend . '.' . $key;

                if (is_array($value) && !empty($value)) {
                    $flatten($value, $newKey);
                } else {
                    $results[$newKey] = $value;
                }
            }
        };

        $flatten($this->items, $prepend);

        return new static($results);
    }

    /**
     * Convert a flattened "dot" notated array into an expanded array.
     *
     * Example:
     * ```php
     * $collection = collect(['products.desk.price' => 100]);
     * $expanded = $collection->undot();
     * // ['products' => ['desk' => ['price' => 100]]]
     * ```
     *
     * @return static
     */
    public function undot(): static
    {
        $results = [];

        foreach ($this->items as $key => $value) {
            $keys = explode('.', (string)$key);
            $current = &$results;

            foreach ($keys as $i => $segment) {
                if ($i === count($keys) - 1) {
                    $current[$segment] = $value;
                } else {
                    if (!isset($current[$segment]) || !is_array($current[$segment])) {
                        $current[$segment] = [];
                    }
                    $current = &$current[$segment];
                }
            }
        }

        return new static($results);
    }

    /**
     * Get a value from a nested array using dot notation.
     *
     * @param string $key Dot notation key (e.g., 'user.profile.name')
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function getDot(string $key, mixed $default = null): mixed
    {
        $array = $this->items;
        $keys = explode('.', $key);

        foreach ($keys as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }

    /**
     * Check if a key exists using dot notation.
     *
     * @param string $key Dot notation key
     * @return bool
     */
    public function hasDot(string $key): bool
    {
        $array = $this->items;
        $keys = explode('.', $key);

        foreach ($keys as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return false;
            }
            $array = $array[$segment];
        }

        return true;
    }

    // ========================================
    // ADDITIONAL UTILITY METHODS
    // ========================================

    /**
     * Collapse an array of arrays into a single array.
     *
     * Example:
     * ```php
     * $collection = collect([[1, 2], [3, 4], [5]]);
     * $collapsed = $collection->collapse();
     * // [1, 2, 3, 4, 5]
     * ```
     *
     * @return static
     */
    public function collapse(): static
    {
        $results = [];

        foreach ($this->items as $item) {
            if ($item instanceof self) {
                $item = $item->all();
            }

            if (is_array($item)) {
                $results = array_merge($results, $item);
            }
        }

        return new static($results);
    }

    /**
     * Get the underlying items from the given collection if applicable.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function unwrap(mixed $value): mixed
    {
        return $value instanceof self ? $value->all() : $value;
    }

    /**
     * Pipe the collection into the given class.
     *
     * @param string $class Class name to instantiate
     * @return mixed
     */
    public function pipeInto(string $class): mixed
    {
        return new $class($this);
    }

    /**
     * Pass the collection through a series of callable pipes.
     *
     * @param array<callable> $callbacks Array of callables
     * @return mixed
     */
    public function pipeThrough(array $callbacks): mixed
    {
        return array_reduce($callbacks, fn($carry, $callback) => $callback($carry), $this);
    }

    /**
     * Map a collection and flatten the result by a single level, passing keys.
     *
     * @param callable $callback Callback receiving ($value, $key) and returning array
     * @return static
     */
    public function mapSpread(callable $callback): static
    {
        return $this->map(function ($item, $key) use ($callback) {
            if (is_array($item)) {
                return $callback(...array_values($item));
            }
            return $callback($item);
        });
    }

    /**
     * Execute a callback over each item with spread parameters.
     *
     * @param callable $callback Callback receiving spread array values
     * @return static
     */
    public function eachSpread(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if (is_array($item)) {
                if ($callback(...array_values($item)) === false) {
                    break;
                }
            } else {
                if ($callback($item) === false) {
                    break;
                }
            }
        }

        return $this;
    }

    /**
     * Split the collection into the given number of groups.
     *
     * Unlike split(), this ensures each group has roughly equal size.
     *
     * @param int $numberOfGroups Number of groups
     * @return static
     */
    public function splitIn(int $numberOfGroups): static
    {
        if ($this->isEmpty() || $numberOfGroups <= 0) {
            return new static();
        }

        $count = $this->count();
        $groupSize = (int) ceil($count / $numberOfGroups);

        return $this->chunk($groupSize);
    }

    /**
     * Skip the last N items.
     *
     * @param int $count Number of items to skip from end
     * @return static
     */
    public function skipLast(int $count): static
    {
        if ($count <= 0) {
            return new static($this->items);
        }

        $total = $this->count();
        if ($count >= $total) {
            return new static();
        }

        return $this->take($total - $count);
    }

    /**
     * Get a lazy collection for the items in this collection.
     *
     * Alias for toLazy() for Laravel compatibility.
     *
     * @return LazyCollection
     */
    public function lazy(): LazyCollection
    {
        return $this->toLazy();
    }
}
