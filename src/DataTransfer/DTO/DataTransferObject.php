<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\DTO;

use ArrayAccess;
use JsonSerializable;
use Toporia\Framework\DataTransfer\Contracts\DTOInterface;
use Toporia\Framework\DataTransfer\Exceptions\ImmutableException;
use ReflectionClass;
use ReflectionProperty;

/**
 * Class DataTransferObject
 *
 * Abstract base class for all DTOs.
 * Provides immutable, type-safe data containers.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\DTO
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class DataTransferObject implements DTOInterface, ArrayAccess, JsonSerializable
{
    /**
     * Cached reflection data per class.
     *
     * @var array<class-string, array<string, ReflectionProperty>>
     */
    private static array $reflectionCache = [];

    /**
     * Properties that should be hidden from serialization.
     *
     * @var array<string>
     */
    protected array $hidden = [];

    /**
     * Properties that should be visible in serialization.
     * If set, only these properties will be included.
     *
     * @var array<string>|null
     */
    protected ?array $visible = null;

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $properties = static::getProperties();
        $args = [];

        foreach ($properties as $name => $property) {
            $value = $data[$name] ?? null;

            // Handle type coercion
            if ($value !== null && $property->hasType()) {
                $type = $property->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    // Handle nested DTOs
                    if (is_subclass_of($typeName, self::class) && is_array($value)) {
                        $value = $typeName::fromArray($value);
                    }
                }
            }

            $args[$name] = $value;
        }

        return new static(...$args);
    }

    /**
     * Convert DTO to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $properties = static::getProperties();
        $result = [];

        foreach ($properties as $name => $property) {
            // Skip hidden properties
            if (in_array($name, $this->hidden, true)) {
                continue;
            }

            // If visible is set, only include those
            if ($this->visible !== null && !in_array($name, $this->visible, true)) {
                continue;
            }

            $value = $property->getValue($this);

            // Handle nested DTOs
            if ($value instanceof DTOInterface) {
                $value = $value->toArray();
            } elseif (is_array($value)) {
                $value = array_map(
                    fn($item) => $item instanceof DTOInterface ? $item->toArray() : $item,
                    $value
                );
            }

            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Check if property exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, static::getProperties());
    }

    /**
     * Get property value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return static::getProperties()[$key]->getValue($this) ?? $default;
    }

    /**
     * Create new instance with modified values.
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public function with(array $data): static
    {
        return static::fromArray(array_merge($this->toArray(), $data));
    }

    /**
     * Create new instance without specified keys.
     *
     * @param string ...$keys
     * @return static
     */
    public function except(string ...$keys): static
    {
        $data = $this->toArray();
        foreach ($keys as $key) {
            unset($data[$key]);
        }
        return static::fromArray($data);
    }

    /**
     * Create new instance with only specified keys.
     *
     * @param string ...$keys
     * @return static
     */
    public function only(string ...$keys): static
    {
        $data = $this->toArray();
        return static::fromArray(array_intersect_key($data, array_flip($keys)));
    }

    /**
     * Check if all required properties have non-null values.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        $properties = static::getProperties();

        foreach ($properties as $property) {
            if (!$property->hasType()) {
                continue;
            }

            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType && !$type->allowsNull()) {
                if ($property->getValue($this) === null) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get properties that are null.
     *
     * @return array<string>
     */
    public function getNullProperties(): array
    {
        $nullProps = [];

        foreach (static::getProperties() as $name => $property) {
            if ($property->getValue($this) === null) {
                $nullProps[] = $name;
            }
        }

        return $nullProps;
    }

    /**
     * Get cached reflection properties.
     *
     * @return array<string, ReflectionProperty>
     */
    protected static function getProperties(): array
    {
        $class = static::class;

        if (!isset(self::$reflectionCache[$class])) {
            $reflection = new ReflectionClass($class);
            $properties = [];

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                // Skip static properties
                if ($property->isStatic()) {
                    continue;
                }

                $properties[$property->getName()] = $property;
            }

            self::$reflectionCache[$class] = $properties;
        }

        return self::$reflectionCache[$class];
    }

    /**
     * {@inheritDoc}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * {@inheritDoc}
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    /**
     * {@inheritDoc}
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    /**
     * {@inheritDoc}
     * @throws ImmutableException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new ImmutableException('DTOs are immutable. Use with() to create a new instance.');
    }

    /**
     * {@inheritDoc}
     * @throws ImmutableException
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new ImmutableException('DTOs are immutable. Use except() to create a new instance.');
    }

    /**
     * Clone with deep copy.
     *
     * @return static
     */
    public function clone(): static
    {
        return static::fromArray($this->toArray());
    }

    /**
     * Check if DTO equals another.
     *
     * @param DTOInterface $other
     * @return bool
     */
    public function equals(DTOInterface $other): bool
    {
        if (!$other instanceof static) {
            return false;
        }

        return $this->toArray() === $other->toArray();
    }

    /**
     * Get hash of DTO for comparison.
     *
     * @return string
     */
    public function hash(): string
    {
        return md5(json_encode($this->toArray()));
    }
}
