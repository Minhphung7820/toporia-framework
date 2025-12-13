<?php

declare(strict_types=1);

namespace Toporia\Framework\View;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Stringable;
use Traversable;

/**
 * Class ComponentAttributeBag
 *
 * Manages HTML attributes for view components with fluent interface.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  View
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class ComponentAttributeBag implements ArrayAccess, Countable, IteratorAggregate, Stringable
{
    /**
     * The raw array of attributes.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * Create a new component attribute bag instance.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Get a specific attribute value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Set an attribute value.
     *
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function set(string $key, mixed $value): static
    {
        $new = clone $this;
        $new->attributes[$key] = $value;

        return $new;
    }

    /**
     * Check if an attribute exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Remove an attribute.
     *
     * @param string ...$keys
     * @return static
     */
    public function except(string ...$keys): static
    {
        $new = clone $this;

        foreach ($keys as $key) {
            unset($new->attributes[$key]);
        }

        return $new;
    }

    /**
     * Only get specific attributes.
     *
     * @param string ...$keys
     * @return static
     */
    public function only(string ...$keys): static
    {
        return new static(
            array_intersect_key($this->attributes, array_flip($keys))
        );
    }

    /**
     * Merge attributes with the existing attributes.
     *
     * @param array<string, mixed> $attributes
     * @param bool $escape
     * @return static
     */
    public function merge(array $attributes, bool $escape = true): static
    {
        $merged = $this->attributes;

        foreach ($attributes as $key => $value) {
            if ($key === 'class') {
                $merged['class'] = $this->mergeClasses($merged['class'] ?? '', $value);
            } elseif ($key === 'style') {
                $merged['style'] = $this->mergeStyles($merged['style'] ?? '', $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return new static($merged);
    }

    /**
     * Merge class attributes.
     *
     * @param string|array $existing
     * @param string|array $new
     * @return string
     */
    protected function mergeClasses(string|array $existing, string|array $new): string
    {
        $existingClasses = is_array($existing) ? $existing : explode(' ', trim($existing));
        $newClasses = is_array($new) ? $new : explode(' ', trim($new));

        $merged = array_unique(array_filter(array_merge($existingClasses, $newClasses)));

        return implode(' ', $merged);
    }

    /**
     * Merge style attributes.
     *
     * @param string $existing
     * @param string $new
     * @return string
     */
    protected function mergeStyles(string $existing, string $new): string
    {
        $existing = rtrim(trim($existing), ';');
        $new = rtrim(trim($new), ';');

        if ($existing === '') {
            return $new;
        }

        if ($new === '') {
            return $existing;
        }

        return $existing . '; ' . $new;
    }

    /**
     * Conditionally merge classes into the attribute bag.
     *
     * @param array<string, bool|callable> $classes
     * @return static
     */
    public function class(array $classes): static
    {
        $classList = [];

        foreach ($classes as $class => $condition) {
            if (is_int($class)) {
                $classList[] = $condition;
            } elseif (is_callable($condition) ? $condition() : $condition) {
                $classList[] = $class;
            }
        }

        return $this->merge(['class' => implode(' ', array_filter($classList))]);
    }

    /**
     * Conditionally merge styles into the attribute bag.
     *
     * @param array<string, bool|callable> $styles
     * @return static
     */
    public function style(array $styles): static
    {
        $styleList = [];

        foreach ($styles as $style => $condition) {
            if (is_int($style)) {
                $styleList[] = $condition;
            } elseif (is_callable($condition) ? $condition() : $condition) {
                $styleList[] = $style;
            }
        }

        return $this->merge(['style' => implode('; ', array_filter($styleList))]);
    }

    /**
     * Get all attributes.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->attributes;
    }

    /**
     * Filter attributes where keys start with the given value.
     *
     * @param string $prefix
     * @return static
     */
    public function whereStartsWith(string $prefix): static
    {
        return new static(
            array_filter(
                $this->attributes,
                fn($key) => str_starts_with($key, $prefix),
                ARRAY_FILTER_USE_KEY
            )
        );
    }

    /**
     * Filter attributes where keys don't start with the given value.
     *
     * @param string $prefix
     * @return static
     */
    public function whereDoesNotStartWith(string $prefix): static
    {
        return new static(
            array_filter(
                $this->attributes,
                fn($key) => !str_starts_with($key, $prefix),
                ARRAY_FILTER_USE_KEY
            )
        );
    }

    /**
     * Filter attributes to those that start with 'x-' (Alpine.js directives).
     *
     * @return static
     */
    public function thatAreAlpine(): static
    {
        return $this->whereStartsWith('x-');
    }

    /**
     * Get attributes with wire: prefix for reactive frameworks.
     *
     * @return static
     */
    public function wire(): static
    {
        return $this->whereStartsWith('wire:');
    }

    /**
     * Convert the attribute bag to an HTML string.
     *
     * @return string
     */
    public function toHtml(): string
    {
        $result = [];

        foreach ($this->attributes as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $result[] = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
                continue;
            }

            if (is_array($value)) {
                $value = implode(' ', array_filter($value));
            }

            $result[] = sprintf(
                '%s="%s"',
                htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
            );
        }

        return implode(' ', $result);
    }

    /**
     * Get the string representation of the attribute bag.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toHtml();
    }

    /**
     * Determine if the given offset exists.
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    /**
     * Get the value at the given offset.
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    /**
     * Set the value at the given offset.
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    /**
     * Remove the value at the given offset.
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    /**
     * Get the number of attributes.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->attributes);
    }

    /**
     * Get an iterator for the attributes.
     *
     * @return Traversable
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->attributes);
    }

    /**
     * Determine if the attribute bag is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->attributes);
    }

    /**
     * Determine if the attribute bag is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }
}
