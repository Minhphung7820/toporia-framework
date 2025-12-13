<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;

/**
 * Trait Conditionable
 *
 * Provides fluent conditional method chaining.
 * Apply callbacks conditionally without breaking method chains.
 *
 * Features:
 * - when() - Execute callback when condition is true
 * - unless() - Execute callback when condition is false
 * - whenEmpty()/whenNotEmpty() - Based on emptiness
 * - whenNull()/whenNotNull() - Based on nullability
 * - tap() - Execute callback and return self
 * - pipe() - Transform value through callback
 *
 * Performance:
 * - O(1) condition evaluation
 * - No reflection or magic methods
 * - Lazy evaluation of closures
 * - Zero overhead when condition is false
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
trait Conditionable
{
    /**
     * Apply the callback if the given condition is truthy.
     *
     * @template TReturn
     * @param mixed $condition Condition to evaluate (can be value or callable)
     * @param callable(static, mixed): (TReturn|static) $callback Callback when true
     * @param callable(static, mixed): (TReturn|static)|null $default Callback when false
     * @return static|TReturn
     */
    public function when(mixed $condition, callable $callback, ?callable $default = null): mixed
    {
        // Evaluate condition if it's callable (lazy evaluation)
        $value = $condition instanceof \Closure ? $condition($this) : $condition;

        if ($value) {
            return $callback($this, $value) ?? $this;
        }

        if ($default !== null) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    /**
     * Apply the callback if the given condition is falsy.
     *
     * @template TReturn
     * @param mixed $condition Condition to evaluate (can be value or callable)
     * @param callable(static, mixed): (TReturn|static) $callback Callback when false
     * @param callable(static, mixed): (TReturn|static)|null $default Callback when true
     * @return static|TReturn
     */
    public function unless(mixed $condition, callable $callback, ?callable $default = null): mixed
    {
        // Evaluate condition if it's callable (lazy evaluation)
        $value = $condition instanceof \Closure ? $condition($this) : $condition;

        if (!$value) {
            return $callback($this, $value) ?? $this;
        }

        if ($default !== null) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    /**
     * Apply the callback if the value is empty.
     *
     * @template TReturn
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenEmpty(callable $callback, ?callable $default = null): mixed
    {
        return $this->when(
            fn() => $this->isEmpty(),
            $callback,
            $default
        );
    }

    /**
     * Apply the callback if the value is not empty.
     *
     * @template TReturn
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenNotEmpty(callable $callback, ?callable $default = null): mixed
    {
        return $this->when(
            fn() => $this->isNotEmpty(),
            $callback,
            $default
        );
    }

    /**
     * Apply the callback if the value is null.
     *
     * @template TReturn
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenNull(callable $callback, ?callable $default = null): mixed
    {
        return $this->when(
            fn() => $this->isNull(),
            $callback,
            $default
        );
    }

    /**
     * Apply the callback if the value is not null.
     *
     * @template TReturn
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenNotNull(callable $callback, ?callable $default = null): mixed
    {
        return $this->when(
            fn() => !$this->isNull(),
            $callback,
            $default
        );
    }

    /**
     * Apply the callback if condition matches value (strict comparison).
     *
     * @template TReturn
     * @param mixed $value Value to compare
     * @param mixed $comparison Value to compare against
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenIs(mixed $value, mixed $comparison, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($value === $comparison, $callback, $default);
    }

    /**
     * Apply the callback if condition does not match value (strict comparison).
     *
     * @template TReturn
     * @param mixed $value Value to compare
     * @param mixed $comparison Value to compare against
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenIsNot(mixed $value, mixed $comparison, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($value !== $comparison, $callback, $default);
    }

    /**
     * Apply the callback if value is in array.
     *
     * @template TReturn
     * @param mixed $value Value to check
     * @param array $values Array to check against
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenIn(mixed $value, array $values, callable $callback, ?callable $default = null): mixed
    {
        return $this->when(in_array($value, $values, true), $callback, $default);
    }

    /**
     * Apply the callback if value is not in array.
     *
     * @template TReturn
     * @param mixed $value Value to check
     * @param array $values Array to check against
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenNotIn(mixed $value, array $values, callable $callback, ?callable $default = null): mixed
    {
        return $this->when(!in_array($value, $values, true), $callback, $default);
    }

    /**
     * Apply the callback if value passes the given test.
     *
     * @template TReturn
     * @param mixed $value Value to test
     * @param callable(mixed): bool $test Test callback
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenPasses(mixed $value, callable $test, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($test($value), $callback, $default);
    }

    /**
     * Apply the callback if value fails the given test.
     *
     * @template TReturn
     * @param mixed $value Value to test
     * @param callable(mixed): bool $test Test callback
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenFails(mixed $value, callable $test, callable $callback, ?callable $default = null): mixed
    {
        return $this->when(!$test($value), $callback, $default);
    }

    /**
     * Apply the callback if all conditions are true.
     *
     * @template TReturn
     * @param array<mixed|callable> $conditions Array of conditions
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenAll(array $conditions, callable $callback, ?callable $default = null): mixed
    {
        foreach ($conditions as $condition) {
            $value = $condition instanceof \Closure ? $condition($this) : $condition;
            if (!$value) {
                return $default !== null ? ($default($this) ?? $this) : $this;
            }
        }

        return $callback($this) ?? $this;
    }

    /**
     * Apply the callback if any condition is true.
     *
     * @template TReturn
     * @param array<mixed|callable> $conditions Array of conditions
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenAny(array $conditions, callable $callback, ?callable $default = null): mixed
    {
        foreach ($conditions as $condition) {
            $value = $condition instanceof \Closure ? $condition($this) : $condition;
            if ($value) {
                return $callback($this) ?? $this;
            }
        }

        return $default !== null ? ($default($this) ?? $this) : $this;
    }

    /**
     * Apply the callback if none of the conditions are true.
     *
     * @template TReturn
     * @param array<mixed|callable> $conditions Array of conditions
     * @param callable(static): (TReturn|static) $callback
     * @param callable(static): (TReturn|static)|null $default
     * @return static|TReturn
     */
    public function whenNone(array $conditions, callable $callback, ?callable $default = null): mixed
    {
        foreach ($conditions as $condition) {
            $value = $condition instanceof \Closure ? $condition($this) : $condition;
            if ($value) {
                return $default !== null ? ($default($this) ?? $this) : $this;
            }
        }

        return $callback($this) ?? $this;
    }

    /**
     * Execute callback and return self (for side effects).
     *
     * @param callable(static): void $callback
     * @return static
     */
    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * Transform the value through callback.
     *
     * @template TReturn
     * @param callable(static): TReturn $callback
     * @return TReturn
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * Execute callback and return its result, or return self if result is null.
     *
     * @template TReturn
     * @param callable(static): (TReturn|null) $callback
     * @return static|TReturn
     */
    public function transform(callable $callback): mixed
    {
        return $callback($this) ?? $this;
    }

    /**
     * Check if the value is empty.
     * Override this method in classes that use this trait.
     *
     * @return bool
     */
    protected function isEmpty(): bool
    {
        // Default implementation - can be overridden
        if (method_exists($this, 'count')) {
            return $this->count() === 0;
        }

        if (property_exists($this, 'items')) {
            return empty($this->items);
        }

        if (property_exists($this, 'value')) {
            return empty($this->value);
        }

        return false;
    }

    /**
     * Check if the value is not empty.
     *
     * @return bool
     */
    protected function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Check if the value is null.
     * Override this method in classes that use this trait.
     *
     * @return bool
     */
    protected function isNull(): bool
    {
        // Default implementation - can be overridden
        if (property_exists($this, 'value')) {
            return $this->value === null;
        }

        return false;
    }
}
