<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;

use ArrayAccess;

/**
 * Class Optional
 *
 * Provides null-safe access to object properties and methods.
 * Returns null instead of throwing errors when the underlying value is null.
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
 *
 * Performance:
 * - O(1) property/method access
 * - No reflection overhead
 * - Minimal memory footprint
 *
 * Example:
 * ```php
 * // Without Optional - throws error if $user is null
 * $name = $user->profile->name;
 *
 * // With Optional - returns null safely
 * $name = optional($user)->profile->name;
 *
 * // With callback
 * $name = optional($user, fn($u) => $u->getFullName());
 * ```
 *
 * @template TValue
 */
class Optional implements ArrayAccess
{
    /**
     * @param TValue $value The underlying value
     */
    public function __construct(
        protected mixed $value
    ) {}

    /**
     * Dynamically access a property on the underlying object.
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        if (is_object($this->value)) {
            return $this->value->{$key} ?? null;
        }

        return null;
    }

    /**
     * Dynamically set a property on the underlying object.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function __set(string $key, mixed $value): void
    {
        if (is_object($this->value)) {
            $this->value->{$key} = $value;
        }
    }

    /**
     * Dynamically check if a property is set on the underlying object.
     *
     * @param string $key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        if (is_object($this->value)) {
            return isset($this->value->{$key});
        }

        return false;
    }

    /**
     * Dynamically unset a property on the underlying object.
     *
     * @param string $key
     * @return void
     */
    public function __unset(string $key): void
    {
        if (is_object($this->value)) {
            unset($this->value->{$key});
        }
    }

    /**
     * Dynamically call a method on the underlying object.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (is_object($this->value)) {
            return $this->value->{$method}(...$parameters);
        }

        return null;
    }

    /**
     * Determine if an item exists at an offset.
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        if (is_array($this->value)) {
            return array_key_exists($offset, $this->value);
        }

        if ($this->value instanceof ArrayAccess) {
            return $this->value->offsetExists($offset);
        }

        return false;
    }

    /**
     * Get an item at a given offset.
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (is_array($this->value)) {
            return $this->value[$offset] ?? null;
        }

        if ($this->value instanceof ArrayAccess) {
            return $this->value->offsetGet($offset);
        }

        return null;
    }

    /**
     * Set an item at a given offset.
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_array($this->value)) {
            if ($offset === null) {
                $this->value[] = $value;
            } else {
                $this->value[$offset] = $value;
            }
            return;
        }

        if ($this->value instanceof ArrayAccess) {
            $this->value->offsetSet($offset, $value);
        }
    }

    /**
     * Unset an item at a given offset.
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        if (is_array($this->value)) {
            unset($this->value[$offset]);
            return;
        }

        if ($this->value instanceof ArrayAccess) {
            $this->value->offsetUnset($offset);
        }
    }

    /**
     * Get the underlying value.
     *
     * @return TValue
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * Get the underlying value or a default.
     *
     * @template TDefault
     * @param TDefault $default
     * @return TValue|TDefault
     */
    public function or(mixed $default): mixed
    {
        return $this->value ?? $default;
    }

    /**
     * Apply a callback if the value is not null.
     *
     * @template TResult
     * @param callable(TValue): TResult $callback
     * @return TResult|null
     */
    public function map(callable $callback): mixed
    {
        if ($this->value !== null) {
            return $callback($this->value);
        }

        return null;
    }

    /**
     * Apply a callback if the value is not null, returning new Optional.
     *
     * @template TResult
     * @param callable(TValue): TResult $callback
     * @return Optional<TResult|null>
     */
    public function flatMap(callable $callback): Optional
    {
        if ($this->value !== null) {
            return new Optional($callback($this->value));
        }

        return new Optional(null);
    }

    /**
     * Execute callback if value exists.
     *
     * @param callable(TValue): void $callback
     * @return static
     */
    public function whenPresent(callable $callback): static
    {
        if ($this->value !== null) {
            $callback($this->value);
        }

        return $this;
    }

    /**
     * Execute callback if value is null.
     *
     * @param callable(): void $callback
     * @return static
     */
    public function whenAbsent(callable $callback): static
    {
        if ($this->value === null) {
            $callback();
        }

        return $this;
    }

    /**
     * Check if value is present (not null).
     *
     * @return bool
     */
    public function isPresent(): bool
    {
        return $this->value !== null;
    }

    /**
     * Check if value is absent (null).
     *
     * @return bool
     */
    public function isAbsent(): bool
    {
        return $this->value === null;
    }

    /**
     * Throw exception if value is absent.
     *
     * @param \Throwable|string $exception
     * @return TValue
     * @throws \Throwable
     */
    public function orThrow(\Throwable|string $exception = 'Value is absent'): mixed
    {
        if ($this->value === null) {
            if (is_string($exception)) {
                throw new \RuntimeException($exception);
            }
            throw $exception;
        }

        return $this->value;
    }

    /**
     * Get value or execute callback for default.
     *
     * @template TDefault
     * @param callable(): TDefault $callback
     * @return TValue|TDefault
     */
    public function orElse(callable $callback): mixed
    {
        if ($this->value !== null) {
            return $this->value;
        }

        return $callback();
    }
}
