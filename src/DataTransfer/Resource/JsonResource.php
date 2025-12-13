<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Resource;

use JsonSerializable;
use Toporia\Framework\DataTransfer\Contracts\ResourceInterface;
use Toporia\Framework\Database\ORM\Model;

/**
 * Class JsonResource
 *
 * Toporia-style JSON Resource for API responses.
 * Wraps entities/data for consistent JSON serialization.
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
class JsonResource implements ResourceInterface
{
    /**
     * The resource instance.
     *
     * @var mixed
     */
    public mixed $resource;

    /**
     * Additional data to merge with resource.
     *
     * @var array<string, mixed>
     */
    protected array $additional = [];

    /**
     * The wrapper key for the resource.
     *
     * @var string|null
     */
    protected ?string $wrapper = 'data';

    /**
     * Default wrapper for all resources.
     *
     * @var string|null
     */
    public static ?string $wrap = 'data';

    /**
     * Indicates if the resource should be preserved when "whenLoaded" is applied.
     *
     * @var bool
     */
    protected bool $preserveKeys = false;

    /**
     * Create a new resource instance.
     *
     * @param mixed $resource
     */
    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
        $this->wrapper = static::$wrap;
    }

    /**
     * Create a new resource instance.
     *
     * @param mixed ...$parameters
     * @return static
     */
    public static function make(mixed ...$parameters): static
    {
        return new static(...$parameters);
    }

    /**
     * Create a collection of resources.
     *
     * @param iterable $resource
     * @return ResourceCollection
     */
    public static function collection(iterable $resource): ResourceCollection
    {
        return new ResourceCollection($resource, static::class);
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        if ($this->resource === null) {
            return [];
        }

        if (is_array($this->resource)) {
            return $this->resource;
        }

        if ($this->resource instanceof JsonSerializable) {
            return $this->resource->jsonSerialize();
        }

        if ($this->resource instanceof Model) {
            return $this->resource->toArray();
        }

        if (is_object($this->resource)) {
            return get_object_vars($this->resource);
        }

        return (array) $this->resource;
    }

    /**
     * {@inheritDoc}
     */
    public function getData(): mixed
    {
        return $this->resource;
    }

    /**
     * {@inheritDoc}
     */
    public function additional(array $additional): static
    {
        $this->additional = array_merge($this->additional, $additional);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getAdditional(): array
    {
        return $this->additional;
    }

    /**
     * {@inheritDoc}
     */
    public function wrap(?string $wrapper): static
    {
        $this->wrapper = $wrapper;
        return $this;
    }

    /**
     * Disable wrapping for this resource.
     *
     * @return static
     */
    public function withoutWrapping(): static
    {
        return $this->wrap(null);
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(): array
    {
        $data = $this->toArray();

        if ($this->wrapper !== null) {
            $data = [$this->wrapper => $data];
        }

        return array_merge($data, $this->additional);
    }

    /**
     * {@inheritDoc}
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * Return a conditional attribute.
     *
     * @param bool $condition
     * @param mixed $value
     * @param mixed $default
     * @return mixed
     */
    protected function when(bool $condition, mixed $value, mixed $default = null): mixed
    {
        if ($condition) {
            return value($value);
        }

        return func_num_args() === 3 ? value($default) : new MissingValue();
    }

    /**
     * Return a conditional attribute based on truthiness.
     *
     * @param mixed $value
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    protected function whenNotNull(mixed $value, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($value !== null) {
            return $callback !== null ? $callback($value) : $value;
        }

        return func_num_args() === 3 ? value($default) : new MissingValue();
    }

    /**
     * Return attribute when relationship is loaded.
     *
     * @param string $relationship
     * @param mixed $value
     * @param mixed $default
     * @return mixed
     */
    protected function whenLoaded(string $relationship, mixed $value = null, mixed $default = null): mixed
    {
        if (!$this->resource instanceof Model) {
            return new MissingValue();
        }

        if (!method_exists($this->resource, 'relationLoaded')) {
            return new MissingValue();
        }

        if (!$this->resource->relationLoaded($relationship)) {
            return func_num_args() === 3 ? value($default) : new MissingValue();
        }

        if ($value === null) {
            return $this->resource->{$relationship};
        }

        return value($value);
    }

    /**
     * Merge values when condition is true.
     *
     * @param bool $condition
     * @param array|callable $value
     * @return MergeValue|MissingValue
     */
    protected function mergeWhen(bool $condition, array|callable $value): MergeValue|MissingValue
    {
        if ($condition) {
            return new MergeValue(value($value));
        }

        return new MissingValue();
    }

    /**
     * Merge the given attributes.
     *
     * @param array $attributes
     * @return MergeValue
     */
    protected function merge(array $attributes): MergeValue
    {
        return new MergeValue($attributes);
    }

    /**
     * Dynamically get properties from the resource.
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource[$key] ?? null;
        }

        if (is_object($this->resource)) {
            return $this->resource->{$key} ?? null;
        }

        return null;
    }

    /**
     * Dynamically check if property exists.
     *
     * @param string $key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        if (is_array($this->resource)) {
            return isset($this->resource[$key]);
        }

        if (is_object($this->resource)) {
            return isset($this->resource->{$key});
        }

        return false;
    }

    /**
     * Convert resource to response array.
     *
     * @return array
     */
    public function toResponse(): array
    {
        return $this->resolve();
    }
}

/**
 * Helper function to resolve value.
 */
if (!function_exists('value')) {
    function value(mixed $value): mixed
    {
        return $value instanceof \Closure ? $value() : $value;
    }
}
