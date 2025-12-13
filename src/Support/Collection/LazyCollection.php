<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Collection;

use Toporia\Framework\Support\Contracts\CollectionInterface;
use ArrayAccess;
use Countable;
use Generator;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;


/**
 * Class LazyCollection
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
class LazyCollection implements IteratorAggregate, Countable, CollectionInterface
{
    /** @var callable():Generator */
    protected $producer;

    /**
     * @param callable|Generator|Traversable|array $source
     */
    public function __construct(protected mixed $source = [])
    {
        // Chuẩn hoá thành producer closure để đảm bảo multi-pass safe.
        $this->producer = $this->normalizeToProducer($source);
    }

    /**
     * Create new lazy collection.
     */
    public static function make(mixed $source = []): static
    {
        return new static($source);
    }

    /**
     * Create from range.
     */
    public static function range(int $start, int $end, int $step = 1): static
    {
        if ($step === 0) {
            throw new InvalidArgumentException('Step cannot be 0.');
        }

        return new static(function () use ($start, $end, $step) {
            if ($step > 0) {
                for ($i = $start; $i <= $end; $i += $step) {
                    yield $i;
                }
            } else {
                for ($i = $start; $i >= $end; $i += $step) {
                    yield $i;
                }
            }
        });
    }

    /**
     * Create from times.
     */
    public static function times(int $count, callable $callback): static
    {
        return new static(function () use ($count, $callback) {
            for ($i = 1; $i <= $count; $i++) {
                yield $callback($i);
            }
        });
    }

    /**
     * Create infinite sequence.
     */
    public static function infinite(callable $callback = null): static
    {
        return new static(function () use ($callback) {
            $i = 0;
            while (true) {
                yield $callback ? $callback($i++) : $i++;
            }
        });
    }

    /**
     * IteratorAggregate
     */
    public function getIterator(): Traversable
    {
        $p = $this->producer;
        return $p();
    }

    /**
     * Internal: chuẩn hoá $source thành producer closure tạo Generator mới mỗi lần.
     * - Nếu $source là Generator (one-shot), ta wrap thành closure "remember on first pass":
     *   + Lần đầu: consume generator -> cache -> yield
     *   + Lần sau: yield từ cache (multi-pass safe mà vẫn lazy ở pass đầu)
     */
    protected function normalizeToProducer(mixed $source): callable
    {
        // callable: gọi để lấy iterable/Generator mới mỗi lần
        if (is_callable($source) && !($source instanceof Traversable)) {
            return static function () use ($source): Generator {
                $it = $source();
                yield from self::iterToGenerator($it);
            };
        }

        // Generator: one-shot -> wrap với cache để pass sau vẫn chạy được
        if ($source instanceof Generator) {
            $cache = [];
            $consumed = false;

            return static function () use (&$cache, &$consumed, $source): Generator {
                if (!$consumed) {
                    foreach ($source as $k => $v) {
                        $cache[$k] = $v;
                        yield $k => $v;
                    }
                    $consumed = true;
                    return;
                }
                // Pass 2+: dùng cache
                foreach ($cache as $k => $v) {
                    yield $k => $v;
                }
            };
        }

        // Traversable (Iterator, ArrayIterator...): tạo mới nếu có thể, nếu không thì wrap với remember nhẹ
        if ($source instanceof Traversable) {
            return static function () use ($source): Generator {
                // Nếu Traversable không rewindable, foreach sẽ fail ở pass 2 — ta "best effort" convert sang ArrayIterator
                if (method_exists($source, 'rewind')) {
                    foreach ($source as $k => $v) {
                        yield $k => $v;
                    }
                    return;
                }
                // Fallback: copy sang array một lần (đánh đổi bộ nhớ) để multi-pass
                $arr = iterator_to_array($source, true);
                foreach ($arr as $k => $v) {
                    yield $k => $v;
                }
            };
        }

        if (is_array($source)) {
            return static function () use ($source): Generator {
                foreach ($source as $k => $v) {
                    yield $k => $v;
                }
            };
        }

        throw new InvalidArgumentException('Source must be iterable/generator/callable producing iterable.');
    }

    /**
     * Utility: convert iterable|mixed -> Generator
     */
    protected static function iterToGenerator(mixed $iter): Generator
    {
        if ($iter instanceof Generator) {
            yield from $iter;
            return;
        }
        if ($iter instanceof Traversable) {
            foreach ($iter as $k => $v) {
                yield $k => $v;
            }
            return;
        }
        if (is_array($iter)) {
            foreach ($iter as $k => $v) {
                yield $k => $v;
            }
            return;
        }
        // single value
        yield $iter;
    }

    /**
     * Map over items lazily.
     */
    public function map(callable $callback): static
    {
        return new static(function () use ($callback) {
            foreach ($this->getIterator() as $key => $value) {
                yield $key => $callback($value, $key);
            }
        });
    }

    /**
     * Flat map (map rồi mở phẳng 1 tầng).
     */
    public function flatMap(callable $callback): static
    {
        return new static(function () use ($callback) {
            foreach ($this->getIterator() as $key => $value) {
                $out = $callback($value, $key);
                if ($out instanceof Traversable) {
                    foreach ($out as $k => $v) yield $k => $v;
                } elseif (is_array($out)) {
                    foreach ($out as $k => $v) yield $k => $v;
                } elseif ($out !== null) {
                    yield $key => $out;
                }
            }
        });
    }

    /**
     * Concat các iterable khác vào sau.
     */
    public function concat(mixed ...$iters): static
    {
        return new static(function () use ($iters) {
            foreach ($this->getIterator() as $k => $v) yield $k => $v;
            foreach ($iters as $it) {
                foreach (self::iterToGenerator($it) as $k => $v) {
                    yield $k => $v;
                }
            }
        });
    }

    /**
     * Filter items lazily.
     */
    public function filter(callable $callback = null): static
    {
        return new static(function () use ($callback) {
            foreach ($this->getIterator() as $key => $value) {
                if ($callback === null) {
                    if ($value) yield $key => $value;
                } elseif ($callback($value, $key)) {
                    yield $key => $value;
                }
            }
        });
    }

    /**
     * Reject items lazily.
     */
    public function reject(callable $callback): static
    {
        return $this->filter(fn($value, $key) => !$callback($value, $key));
    }

    /**
     * Take first N items.
     */
    public function take(int $limit): static
    {
        if ($limit < 0) throw new InvalidArgumentException('take() requires limit >= 0');
        return new static(function () use ($limit) {
            if ($limit === 0) return;
            $count = 0;
            foreach ($this->getIterator() as $key => $value) {
                yield $key => $value;
                if (++$count >= $limit) break;
            }
        });
    }

    /**
     * Take while condition is true.
     */
    public function takeWhile(callable $callback): static
    {
        return new static(function () use ($callback) {
            foreach ($this->getIterator() as $key => $value) {
                if (!$callback($value, $key)) break;
                yield $key => $value;
            }
        });
    }

    /**
     * Take until condition is true.
     */
    public function takeUntil(callable $callback): static
    {
        return $this->takeWhile(fn($value, $key) => !$callback($value, $key));
    }

    /**
     * Skip first N items.
     */
    public function skip(int $offset): static
    {
        if ($offset < 0) throw new InvalidArgumentException('skip() requires offset >= 0');
        return new static(function () use ($offset) {
            $count = 0;
            foreach ($this->getIterator() as $key => $value) {
                if ($count++ < $offset) continue;
                yield $key => $value;
            }
        });
    }

    /**
     * Skip while condition is true.
     */
    public function skipWhile(callable $callback): static
    {
        return new static(function () use ($callback) {
            $taking = false;
            foreach ($this->getIterator() as $key => $value) {
                if (!$taking && !$callback($value, $key)) {
                    $taking = true;
                }
                if ($taking) yield $key => $value;
            }
        });
    }

    /**
     * Skip until condition is true.
     */
    public function skipUntil(callable $callback): static
    {
        return $this->skipWhile(fn($value, $key) => !$callback($value, $key));
    }

    /**
     * Unique items (O(1) lookup).
     * - $key: null => item chính nó; string => lấy theo key/prop; callable($item,$k) => id bất kỳ
     */
    public function unique(string|callable|null $key = null): static
    {
        return new static(function () use ($key) {
            $callback = is_callable($key)
                ? $key
                : function ($item) use ($key) {
                    if ($key === null) return $item;
                    // Lấy theo key hoặc property
                    if (is_array($item) && array_key_exists($key, $item)) return $item[$key];
                    if (is_object($item)) {
                        if (isset($item->{$key})) return $item->{$key};
                        $getter = 'get' . str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
                        if (method_exists($item, $getter)) return $item->{$getter}();
                    }
                    return null;
                };

            $seen = [];
            foreach ($this->getIterator() as $k => $item) {
                $id = self::normalizeKey($callback($item, $k));
                if (!array_key_exists($id, $seen)) {
                    $seen[$id] = true;
                    yield $k => $item;
                }
            }
        });
    }

    /**
     * Chunk into collections (eager chunk).
     */
    public function chunk(int $size): static
    {
        if ($size <= 0) throw new InvalidArgumentException('chunk() requires size > 0');
        return new static(function () use ($size) {
            $chunk = [];
            foreach ($this->getIterator() as $key => $value) {
                $chunk[$key] = $value;
                if (count($chunk) >= $size) {
                    yield new Collection($chunk);
                    $chunk = [];
                }
            }
            if (!empty($chunk)) {
                yield new Collection($chunk);
            }
        });
    }

    /**
     * Flatten lazily.
     */
    public function flatten(int|float $depth = INF): static
    {
        if ($depth === INF) {
            $depth = PHP_INT_MAX;
        }

        return new static(function () use ($depth) {
            foreach ($this->getIterator() as $item) {
                if (!is_array($item) && !$item instanceof Collection) {
                    yield $item;
                } elseif ($depth === 1) {
                    $values = $item instanceof Collection ? $item->all() : $item;
                    yield from array_values($values);
                } else {
                    $values = $item instanceof Collection ? $item->all() : $item;
                    yield from (new static($values))->flatten($depth - 1)->getIterator();
                }
            }
        });
    }

    /**
     * Tap into collection.
     */
    public function tap(callable $callback): static
    {
        return new static(function () use ($callback) {
            foreach ($this->getIterator() as $key => $value) {
                $callback($value, $key);
                yield $key => $value;
            }
        });
    }

    /**
     * Execute callback on each item (terminal).
     */
    public function each(callable $callback): static
    {
        foreach ($this->getIterator() as $key => $value) {
            if ($callback($value, $key) === false) break;
        }
        return $this;
    }

    /**
     * Reduce to single value (terminal).
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $acc = $initial;
        foreach ($this->getIterator() as $key => $value) {
            $acc = $callback($acc, $value, $key);
        }
        return $acc;
    }

    /**
     * Check if any item passes test (terminal).
     */
    public function some(callable $callback): bool
    {
        foreach ($this->getIterator() as $key => $value) {
            if ($callback($value, $key)) return true;
        }
        return false;
    }

    /**
     * Check if all items pass test (terminal).
     */
    public function every(callable $callback): bool
    {
        foreach ($this->getIterator() as $key => $value) {
            if (!$callback($value, $key)) return false;
        }
        return true;
    }

    /**
     * Check if collection contains item (terminal).
     * - contains(fn($item)=>...)
     * - contains($needle)
     * - contains($key, $op, $value) | supports array/object
     */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            if (is_callable($key)) {
                return $this->some($key);
            }
            foreach ($this->getIterator() as $item) {
                if ($item === $key) return true;
            }
            return false;
        }

        return $this->some(function ($item) use ($key, $operator, $value) {
            $left = self::getValueByKey($item, $key);
            return $this->compareValues($left, $operator, $value);
        });
    }

    /**
     * Get first item (terminal).
     */
    public function first(callable $callback = null, mixed $default = null): mixed
    {
        foreach ($this->getIterator() as $key => $value) {
            if ($callback === null || $callback($value, $key)) {
                return $value;
            }
        }
        return $default;
    }

    /**
     * Get all items as array (materializes the collection).
     */
    public function all(): array
    {
        // dùng iterator_to_array sẽ giữ key; true = preserve keys
        return iterator_to_array($this->getIterator(), true);
    }

    /**
     * Values only (reindex).
     */
    public function values(): static
    {
        return new static(function () {
            foreach ($this->getIterator() as $value) yield $value;
        });
    }

    /**
     * Keys only.
     */
    public function keys(): static
    {
        return new static(function () {
            foreach ($this->getIterator() as $key => $value) yield $key;
        });
    }

    /**
     * Collect into eager Collection.
     */
    public function collect(): Collection
    {
        return new Collection($this->all());
    }

    /**
     * Count items (terminal; cẩn trọng với stream vô hạn).
     */
    public function count(): int
    {
        $c = 0;
        foreach ($this->getIterator() as $_) $c++;
        return $c;
    }

    public function isEmpty(): bool
    {
        foreach ($this->getIterator() as $_) return false;
        return true;
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Zip with other iterables — dừng khi 1 bên hết (yield Collection)
     */
    public function zip(mixed ...$arrays): static
    {
        return new static(function () use ($arrays) {
            // Convert all sources to arrays first (simplest approach)
            $iterators = [];

            // First iterator from current collection
            $iterators[] = iterator_to_array($this->getIterator(), false);

            // Convert remaining arguments to arrays
            foreach ($arrays as $arr) {
                if ($arr instanceof Traversable) {
                    $iterators[] = iterator_to_array($arr, false);
                } elseif (is_array($arr)) {
                    $iterators[] = array_values($arr);
                } else {
                    // single value
                    $iterators[] = [$arr];
                }
            }

            // Find minimum length
            $minLength = min(array_map('count', $iterators));

            // Zip by index
            for ($i = 0; $i < $minLength; $i++) {
                $row = [];
                foreach ($iterators as $iter) {
                    $row[] = $iter[$i];
                }
                yield new Collection($row);
            }
        });
    }

    /**
     * Remember items (cache in memory for multi-pass).
     */
    public function remember(): static
    {
        $cache = [];
        $sourceProducer = $this->producer;

        return new static(function () use (&$cache, $sourceProducer) {
            // yield cache trước
            foreach ($cache as $k => $v) yield $k => $v;

            // sau đó consume source và fill cache
            foreach ($sourceProducer() as $k => $v) {
                $cache[$k] = $v;
                yield $k => $v;
            }
        });
    }

    /**
     * Convert to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->all(), $options);
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

    /**
     * Lấy giá trị theo key từ array/ArrayAccess/object (support getter).
     */
    protected static function getValueByKey(mixed $item, string|int $key): mixed
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }

        if ($item instanceof ArrayAccess) {
            return $item[$key] ?? null;
        }

        if (is_object($item)) {
            if (isset($item->{$key}) || property_exists($item, (string)$key)) {
                return $item->{$key};
            }
            $getter = 'get' . str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', (string)$key)));
            if (method_exists($item, $getter)) {
                return $item->{$getter}();
            }
        }

        return null;
    }

    /**
     * Chuẩn hoá key cho unique(): scalar => cast; array/object => json_encode stable.
     */
    protected static function normalizeKey(mixed $id): string
    {
        if (is_null($id)) return 'null';
        if (is_bool($id)) return $id ? 'true' : 'false';
        if (is_int($id) || is_float($id) || is_string($id)) return (string)$id;
        // array/object: stable json
        return json_encode($id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    // src/Framework/Support/LazyCollection.php

    public function sum(callable|string|null $callback = null): int|float
    {
        $total = 0;
        if ($callback === null) {
            foreach ($this as $v) $total += $v;
            return $total;
        }
        if (is_string($callback)) {
            foreach ($this as $v) {
                $total += is_array($v) ? ($v[$callback] ?? 0) : (is_object($v) ? ($v->{$callback} ?? 0) : 0);
            }
            return $total;
        }
        foreach ($this as $v) $total += $callback($v);
        return $total;
    }

    public function min(callable|string|null $callback = null): mixed
    {
        $set = false;
        $min = null;
        $extract = $this->valueExtractor($callback);
        foreach ($this as $v) {
            $val = $extract($v);
            if (!$set || $val < $min) {
                $min = $val;
                $set = true;
            }
        }
        return $min;
    }

    public function max(callable|string|null $callback = null): mixed
    {
        $set = false;
        $max = null;
        $extract = $this->valueExtractor($callback);
        foreach ($this as $v) {
            $val = $extract($v);
            if (!$set || $val > $max) {
                $max = $val;
                $set = true;
            }
        }
        return $max;
    }

    /**
     * Lấy mỗi phần tử thứ $step (bỏ qua $offset đầu).
     */
    public function nth(int $step, int $offset = 0): static
    {
        if ($step <= 0) {
            throw new InvalidArgumentException('Step must be > 0.');
        }
        return new static(function () use ($step, $offset) {
            $i = 0;
            foreach ($this as $v) {
                if ($i++ < $offset) continue;
                if ((($i - $offset - 1) % $step) === 0) yield $v;
            }
        });
    }

    /**
     * Pluck theo path đơn (key) hoặc chuỗi key con (a.b.c) nếu bạn muốn.
     */
    public function pluck(string|array $path): static
    {
        $segments = is_array($path) ? $path : explode('.', $path);
        return new static(function () use ($segments) {
            foreach ($this as $item) {
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
                yield $current;
            }
        });
    }

    public function keyBy(callable|string $key): static
    {
        return new static(function () use ($key) {
            $extract = is_string($key)
                ? fn($item) => is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null)
                : $key;

            $buffer = [];
            foreach ($this as $item) {
                $buffer[$extract($item)] = $item;
            }
            foreach ($buffer as $k => $v) yield $k => $v;
        });
    }

    public function toEager(): Collection
    {
        $arr = [];
        foreach ($this as $v) $arr[] = $v;
        return Collection::make($arr);
    }

    /**
     * Áp dụng $fn theo từng chunk để giảm peak memory.
     * $fn nhận array $chunk, trả về iterable|array kết quả (yield từng phần tử).
     */
    public function mapChunked(int $size, callable $fn): static
    {
        if ($size <= 0) throw new InvalidArgumentException('Chunk size must be > 0.');
        return new static(function () use ($size, $fn) {
            $chunk = [];
            foreach ($this as $item) {
                $chunk[] = $item;
                if (count($chunk) === $size) {
                    foreach ($fn($chunk) as $out) yield $out;
                    $chunk = [];
                }
            }
            if ($chunk) {
                foreach ($fn($chunk) as $out) yield $out;
            }
        });
    }

    /** @internal */
    private function valueExtractor(callable|string|null $callback): callable
    {
        if ($callback === null)   return fn($v) => $v;
        if (is_string($callback)) return fn($v) => is_array($v) ? ($v[$callback] ?? null) : (is_object($v) ? ($v->{$callback} ?? null) : null);
        return $callback;
    }

    /**
     * Group items by key or callback (terminal - returns eager Collection of Collections).
     *
     * @param string|callable $key
     * @return Collection<array-key, Collection>
     */
    public function groupBy(string|callable $key): Collection
    {
        $callback = is_callable($key) ? $key : fn($item) => is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);

        $groups = [];

        foreach ($this->getIterator() as $k => $item) {
            $groupKey = $callback($item, $k);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }

            $groups[$groupKey][$k] = $item;
        }

        return new Collection(array_map(fn($group) => new Collection($group), $groups));
    }

    /**
     * Partition into two collections based on callback (terminal).
     *
     * @param callable $callback
     * @return array{0: Collection, 1: Collection}
     */
    public function partition(callable $callback): array
    {
        $passed = [];
        $failed = [];

        foreach ($this->getIterator() as $key => $value) {
            if ($callback($value, $key)) {
                $passed[$key] = $value;
            } else {
                $failed[$key] = $value;
            }
        }

        return [new Collection($passed), new Collection($failed)];
    }

    /**
     * Sort collection (terminal - returns eager Collection).
     *
     * @param callable|null $callback
     * @return Collection
     */
    public function sort(callable $callback = null): Collection
    {
        $items = $this->all();

        if ($callback === null) {
            asort($items);
        } else {
            uasort($items, $callback);
        }

        return new Collection($items);
    }

    /**
     * Sort by key (terminal - returns eager Collection).
     *
     * @param string|callable $callback
     * @param bool $descending
     * @return Collection
     */
    public function sortBy(string|callable $callback, bool $descending = false): Collection
    {
        $extract = is_callable($callback)
            ? $callback
            : fn($item) => is_array($item) ? ($item[$callback] ?? null) : (is_object($item) ? ($item->{$callback} ?? null) : null);

        $decorated = [];
        foreach ($this->getIterator() as $k => $it) {
            $decorated[] = [$extract($it), $k, $it];
        }

        usort($decorated, static function ($a, $b) use ($descending) {
            $cmp = $a[0] <=> $b[0];
            return $descending ? -$cmp : $cmp;
        });

        $out = [];
        foreach ($decorated as $row) {
            [, $origKey, $val] = $row;
            $out[$origKey] = $val;
        }
        return new Collection($out);
    }

    /**
     * Reverse collection (terminal - returns eager Collection).
     *
     * @return Collection
     */
    public function reverse(): Collection
    {
        return new Collection(array_reverse($this->all(), true));
    }

    /**
     * Get last item (terminal).
     *
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    public function last(callable $callback = null, mixed $default = null): mixed
    {
        $result = $default;

        foreach ($this->getIterator() as $key => $value) {
            if ($callback === null || $callback($value, $key)) {
                $result = $value;
            }
        }

        return $result;
    }

    /**
     * Merge with other collections/arrays (lazy).
     *
     * @param mixed ...$arrays
     * @return static
     */
    public function merge(mixed ...$arrays): static
    {
        return new static(function () use ($arrays) {
            $seen = [];

            foreach ($this->getIterator() as $k => $v) {
                $seen[$k] = true;
                yield $k => $v;
            }

            foreach ($arrays as $items) {
                if ($items instanceof self || $items instanceof Collection) {
                    $items = $items->all();
                }
                foreach ((array)$items as $k => $v) {
                    yield $k => $v;
                }
            }
        });
    }

    /**
     * Get average value (terminal).
     *
     * @param callable|string|null $callback
     * @return int|float|null
     */
    public function avg(callable|string|null $callback = null): int|float|null
    {
        $count = 0;
        $total = 0;

        $extract = $this->valueExtractor($callback);

        foreach ($this->getIterator() as $v) {
            $total += $extract($v);
            $count++;
        }

        return $count === 0 ? null : $total / $count;
    }

    /**
     * Get only specified keys (lazy).
     *
     * @param array $keys
     * @return static
     */
    public function only(array $keys): static
    {
        $keySet = array_flip($keys);

        return new static(function () use ($keySet) {
            foreach ($this->getIterator() as $k => $v) {
                if (isset($keySet[$k])) {
                    yield $k => $v;
                }
            }
        });
    }

    /**
     * Get all except specified keys (lazy).
     *
     * @param array $keys
     * @return static
     */
    public function except(array $keys): static
    {
        $keySet = array_flip($keys);

        return new static(function () use ($keySet) {
            foreach ($this->getIterator() as $k => $v) {
                if (!isset($keySet[$k])) {
                    yield $k => $v;
                }
            }
        });
    }
}
