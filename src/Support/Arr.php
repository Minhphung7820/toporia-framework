<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;

/**
 * Class Arr
 *
 * Array helper utilities with dot notation support.
 * Provides safe, efficient operations on arrays.
 *
 * Features:
 * - Dot notation access (get, set, has, forget)
 * - Safe operations (no exceptions on missing keys)
 * - Transformation utilities (pluck, flatten, etc.)
 * - Query string building
 *
 * Performance:
 * - O(N) for dot notation where N = depth
 * - Caching for repeated operations
 * - Memory efficient (no unnecessary copies)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class Arr
{
    /**
     * Get an item from an array using dot notation.
     *
     * @param array $array
     * @param string|int|null $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(array $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }

        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        if (!is_string($key) || !str_contains($key, '.')) {
            return $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }

    /**
     * Set an array item using dot notation.
     *
     * @param array $array
     * @param string|int|null $key
     * @param mixed $value
     * @return array
     */
    public static function set(array &$array, string|int|null $key, mixed $value): array
    {
        if ($key === null) {
            return $array = $value;
        }

        $keys = is_string($key) ? explode('.', $key) : [$key];
        $current = &$array;

        foreach ($keys as $i => $segment) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$i]);

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current[array_shift($keys)] = $value;

        return $array;
    }

    /**
     * Check if an item exists using dot notation.
     *
     * @param array $array
     * @param string|int|array $keys
     * @return bool
     */
    public static function has(array $array, string|int|array $keys): bool
    {
        $keys = (array) $keys;

        if (empty($keys)) {
            return false;
        }

        foreach ($keys as $key) {
            $subArray = $array;

            if (array_key_exists($key, $array)) {
                continue;
            }

            if (!is_string($key)) {
                return false;
            }

            foreach (explode('.', $key) as $segment) {
                if (!is_array($subArray) || !array_key_exists($segment, $subArray)) {
                    return false;
                }
                $subArray = $subArray[$segment];
            }
        }

        return true;
    }

    /**
     * Remove one or many items from array using dot notation.
     *
     * @param array $array
     * @param string|int|array $keys
     * @return void
     */
    public static function forget(array &$array, string|int|array $keys): void
    {
        $keys = (array) $keys;

        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                unset($array[$key]);
                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            $parts = explode('.', $key);
            $current = &$array;

            while (count($parts) > 1) {
                $part = array_shift($parts);

                if (!isset($current[$part]) || !is_array($current[$part])) {
                    continue 2;
                }

                $current = &$current[$part];
            }

            unset($current[array_shift($parts)]);
        }
    }

    /**
     * Get first element of array.
     *
     * @param array $array
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    public static function first(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($array)) {
                return $default;
            }

            foreach ($array as $item) {
                return $item;
            }
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get last element of array.
     *
     * @param array $array
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    public static function last(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($array) ? $default : end($array);
        }

        return static::first(array_reverse($array, true), $callback, $default);
    }

    /**
     * Get a subset of the items from the given array.
     *
     * @param array $array
     * @param array|string $keys
     * @return array
     */
    public static function only(array $array, array|string $keys): array
    {
        return array_intersect_key($array, array_flip((array) $keys));
    }

    /**
     * Get all except the specified keys.
     *
     * @param array $array
     * @param array|string $keys
     * @return array
     */
    public static function except(array $array, array|string $keys): array
    {
        static::forget($array, $keys);
        return $array;
    }

    /**
     * Pluck an array of values from an array.
     *
     * @param iterable $array
     * @param string|array $value
     * @param string|null $key
     * @return array
     */
    public static function pluck(iterable $array, string|array $value, ?string $key = null): array
    {
        $results = [];
        $valuePath = is_array($value) ? $value : explode('.', $value);
        $keyPath = $key !== null ? explode('.', $key) : null;

        foreach ($array as $item) {
            $itemValue = static::dataGet($item, $valuePath);

            if ($keyPath === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = static::dataGet($item, $keyPath);
                $results[$itemKey] = $itemValue;
            }
        }

        return $results;
    }

    /**
     * Flatten a multi-dimensional array into a single level.
     *
     * @param iterable $array
     * @param int $depth
     * @return array
     */
    public static function flatten(iterable $array, int $depth = PHP_INT_MAX): array
    {
        $result = [];

        foreach ($array as $item) {
            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge($result, static::flatten($item, $depth - 1));
            }
        }

        return $result;
    }

    /**
     * Flatten array with keys using dot notation.
     *
     * @param iterable $array
     * @param string $prepend
     * @return array
     */
    public static function dot(iterable $array, string $prepend = ''): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $results = array_merge($results, static::dot($value, $prepend . $key . '.'));
            } else {
                $results[$prepend . $key] = $value;
            }
        }

        return $results;
    }

    /**
     * Convert a flatten "dot" notation array into an expanded array.
     *
     * @param iterable $array
     * @return array
     */
    public static function undot(iterable $array): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            static::set($results, $key, $value);
        }

        return $results;
    }

    /**
     * Wrap the given value in an array if it's not already one.
     *
     * @param mixed $value
     * @return array
     */
    public static function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    /**
     * If the given value is not an array, wrap it in one.
     * Return the first element if only one.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function unwrap(mixed $value): mixed
    {
        if (is_array($value)) {
            return count($value) === 1 ? reset($value) : $value;
        }

        return $value;
    }

    /**
     * Filter array by callback.
     *
     * @param array $array
     * @param callable $callback
     * @return array
     */
    public static function where(array $array, callable $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Filter items where the value is not null.
     *
     * @param array $array
     * @return array
     */
    public static function whereNotNull(array $array): array
    {
        return static::where($array, fn($value) => $value !== null);
    }

    /**
     * Get a value from object/array using path.
     *
     * @param mixed $target
     * @param string|array $path
     * @param mixed $default
     * @return mixed
     */
    public static function dataGet(mixed $target, string|array $path, mixed $default = null): mixed
    {
        if ($path === null) {
            return $target;
        }

        $path = is_array($path) ? $path : explode('.', $path);

        foreach ($path as $segment) {
            if (is_array($target)) {
                if (!array_key_exists($segment, $target)) {
                    return $default;
                }
                $target = $target[$segment];
            } elseif (is_object($target)) {
                if (!isset($target->{$segment})) {
                    return $default;
                }
                $target = $target->{$segment};
            } else {
                return $default;
            }
        }

        return $target;
    }

    /**
     * Set a value on object/array using path.
     *
     * @param mixed $target
     * @param string|array $path
     * @param mixed $value
     * @return mixed
     */
    public static function dataSet(mixed &$target, string|array $path, mixed $value): mixed
    {
        $path = is_array($path) ? $path : explode('.', $path);

        foreach ($path as $i => $segment) {
            unset($path[$i]);

            if (empty($path)) {
                if (is_array($target)) {
                    $target[$segment] = $value;
                } elseif (is_object($target)) {
                    $target->{$segment} = $value;
                }
                break;
            }

            if (is_array($target)) {
                if (!isset($target[$segment]) || !is_array($target[$segment])) {
                    $target[$segment] = [];
                }
                $target = &$target[$segment];
            } elseif (is_object($target)) {
                if (!isset($target->{$segment})) {
                    $target->{$segment} = [];
                }
                $target = &$target->{$segment};
            }
        }

        return $target;
    }

    /**
     * Build a query string from array.
     *
     * @param array $array
     * @return string
     */
    public static function query(array $array): string
    {
        return http_build_query($array, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Convert the array into a query string (alias of query).
     *
     * @param array $array
     * @return string
     */
    public static function toQueryString(array $array): string
    {
        return static::query($array);
    }

    /**
     * Sort array recursively.
     *
     * @param array $array
     * @param int $options
     * @param bool $descending
     * @return array
     */
    public static function sortRecursive(array $array, int $options = SORT_REGULAR, bool $descending = false): array
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $value = static::sortRecursive($value, $options, $descending);
            }
        }

        if (static::isAssoc($array)) {
            $descending ? krsort($array, $options) : ksort($array, $options);
        } else {
            $descending ? rsort($array, $options) : sort($array, $options);
        }

        return $array;
    }

    /**
     * Check if array is associative.
     *
     * @param array $array
     * @return bool
     */
    public static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Check if array is a list (sequential integer keys starting from 0).
     *
     * @param array $array
     * @return bool
     */
    public static function isList(array $array): bool
    {
        return array_is_list($array);
    }

    /**
     * Join array elements with a string.
     *
     * @param array $array
     * @param string $glue
     * @param string $finalGlue
     * @return string
     */
    public static function join(array $array, string $glue, string $finalGlue = ''): string
    {
        if ($finalGlue === '') {
            return implode($glue, $array);
        }

        if (count($array) === 0) {
            return '';
        }

        if (count($array) === 1) {
            return end($array);
        }

        $lastItem = array_pop($array);

        return implode($glue, $array) . $finalGlue . $lastItem;
    }

    /**
     * Key an array by a field or callback.
     *
     * @param array $array
     * @param string|callable $keyBy
     * @return array
     */
    public static function keyBy(array $array, string|callable $keyBy): array
    {
        $results = [];

        foreach ($array as $item) {
            $key = is_callable($keyBy) ? $keyBy($item) : static::dataGet($item, $keyBy);
            $results[$key] = $item;
        }

        return $results;
    }

    /**
     * Prepend an item to the beginning of array.
     *
     * @param array $array
     * @param mixed $value
     * @param mixed $key
     * @return array
     */
    public static function prepend(array $array, mixed $value, mixed $key = null): array
    {
        if ($key === null) {
            array_unshift($array, $value);
        } else {
            $array = [$key => $value] + $array;
        }

        return $array;
    }

    /**
     * Push an item onto the end of array.
     *
     * @param array $array
     * @param mixed ...$values
     * @return array
     */
    public static function push(array $array, mixed ...$values): array
    {
        foreach ($values as $value) {
            $array[] = $value;
        }

        return $array;
    }

    /**
     * Pull a value from the array and remove it.
     *
     * @param array $array
     * @param string|int $key
     * @param mixed $default
     * @return mixed
     */
    public static function pull(array &$array, string|int $key, mixed $default = null): mixed
    {
        $value = static::get($array, $key, $default);
        static::forget($array, $key);
        return $value;
    }

    /**
     * Get a random value from array.
     *
     * @param array $array
     * @param int|null $number
     * @return mixed
     */
    public static function random(array $array, ?int $number = null): mixed
    {
        $requested = $number ?? 1;
        $count = count($array);

        if ($requested > $count) {
            throw new \InvalidArgumentException(
                "Cannot get {$requested} items from array with {$count} items."
            );
        }

        if ($number === null) {
            return $array[array_rand($array)];
        }

        if ($number === 0) {
            return [];
        }

        $keys = array_rand($array, $number);

        $results = [];

        foreach ((array) $keys as $key) {
            $results[$key] = $array[$key];
        }

        return $results;
    }

    /**
     * Shuffle array.
     *
     * @param array $array
     * @return array
     */
    public static function shuffle(array $array): array
    {
        shuffle($array);
        return $array;
    }

    /**
     * Divide array into keys and values.
     *
     * @param array $array
     * @return array
     */
    public static function divide(array $array): array
    {
        return [array_keys($array), array_values($array)];
    }

    /**
     * Cross join arrays.
     *
     * @param iterable ...$arrays
     * @return array
     */
    public static function crossJoin(iterable ...$arrays): array
    {
        $results = [[]];

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

        return $results;
    }

    /**
     * Collapse array of arrays into single array.
     *
     * @param iterable $array
     * @return array
     */
    public static function collapse(iterable $array): array
    {
        $results = [];

        foreach ($array as $values) {
            if (!is_array($values)) {
                continue;
            }

            $results[] = $values;
        }

        return array_merge([], ...$results);
    }

    /**
     * Add an element if it doesn't exist.
     *
     * @param array $array
     * @param string|int $key
     * @param mixed $value
     * @return array
     */
    public static function add(array $array, string|int $key, mixed $value): array
    {
        if (static::get($array, $key) === null) {
            static::set($array, $key, $value);
        }

        return $array;
    }

    /**
     * Determine if all items pass the callback test.
     *
     * @param iterable $array
     * @param callable $callback
     * @return bool
     */
    public static function every(iterable $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if any item passes the callback test.
     *
     * @param iterable $array
     * @param callable $callback
     * @return bool
     */
    public static function some(iterable $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an array is accessible.
     *
     * @param mixed $value
     * @return bool
     */
    public static function accessible(mixed $value): bool
    {
        return is_array($value) || $value instanceof \ArrayAccess;
    }

    /**
     * Check if a key exists in array.
     *
     * @param \ArrayAccess|array $array
     * @param string|int $key
     * @return bool
     */
    public static function exists(\ArrayAccess|array $array, string|int $key): bool
    {
        if ($array instanceof \ArrayAccess) {
            return $array->offsetExists($key);
        }

        return array_key_exists($key, $array);
    }

    /**
     * Map array values with keys preserved.
     *
     * @param array $array
     * @param callable $callback
     * @return array
     */
    public static function map(array $array, callable $callback): array
    {
        $keys = array_keys($array);
        $values = array_map($callback, $array, $keys);

        return array_combine($keys, $values);
    }

    /**
     * Map array and flatten results.
     *
     * @param array $array
     * @param callable $callback
     * @return array
     */
    public static function flatMap(array $array, callable $callback): array
    {
        return static::collapse(static::map($array, $callback));
    }

    /**
     * Get unique values.
     *
     * @param array $array
     * @return array
     */
    public static function unique(array $array): array
    {
        return array_unique($array, SORT_REGULAR);
    }

    /**
     * Get values where callback returns true.
     *
     * @param array $array
     * @param callable $callback
     * @return array
     */
    public static function filter(array $array, callable $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Get count of array.
     *
     * @param array $array
     * @return int
     */
    public static function count(array $array): int
    {
        return count($array);
    }

    /**
     * Check if array is empty.
     *
     * @param array $array
     * @return bool
     */
    public static function isEmpty(array $array): bool
    {
        return empty($array);
    }

    /**
     * Sort array by a specific key.
     *
     * @param array $array
     * @param string|callable $key
     * @param bool $descending
     * @return array
     */
    public static function sortBy(array $array, string|callable $key, bool $descending = false): array
    {
        $callback = is_callable($key) ? $key : fn($item) => static::dataGet($item, $key);

        usort($array, function ($a, $b) use ($callback, $descending) {
            $aVal = $callback($a);
            $bVal = $callback($b);
            $result = $aVal <=> $bVal;
            return $descending ? -$result : $result;
        });

        return $array;
    }

    /**
     * Group array by a key or callback.
     *
     * @param array $array
     * @param string|callable $groupBy
     * @return array
     */
    public static function groupBy(array $array, string|callable $groupBy): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            $groupKey = is_callable($groupBy) ? $groupBy($value, $key) : static::dataGet($value, $groupBy);

            if (!isset($results[$groupKey])) {
                $results[$groupKey] = [];
            }

            $results[$groupKey][] = $value;
        }

        return $results;
    }
}
