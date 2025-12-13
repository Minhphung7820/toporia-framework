<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Hydrator;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Toporia\Framework\DataTransfer\Contracts\HydratorInterface;
use Toporia\Framework\DataTransfer\Exceptions\HydrationException;

/**
 * Class ObjectHydrator
 *
 * Hydrates objects from arrays using reflection.
 * Supports nested object hydration, type coercion, and property mapping.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\Hydrator
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class ObjectHydrator implements HydratorInterface
{
    /**
     * Cached reflection data.
     *
     * @var array<class-string, array{reflection: ReflectionClass, properties: array<string, ReflectionProperty>}>
     */
    private array $cache = [];

    /**
     * Property name mapping strategies.
     *
     * @var array<string, callable>
     */
    private array $namingStrategies = [];

    /**
     * Type handlers for custom types.
     *
     * @var array<string, callable>
     */
    private array $typeHandlers = [];

    /**
     * Whether to use strict mode (throw on unknown properties).
     *
     * @var bool
     */
    private bool $strict = false;

    /**
     * Create new hydrator instance.
     *
     * @param bool $strict Whether to use strict mode
     */
    public function __construct(bool $strict = false)
    {
        $this->strict = $strict;
        $this->registerDefaultTypeHandlers();
    }

    /**
     * {@inheritDoc}
     */
    public function hydrate(array $data, object|string $target): object
    {
        $className = is_string($target) ? $target : $target::class;
        $isNewInstance = is_string($target);

        $metadata = $this->getClassMetadata($className);
        $reflection = $metadata['reflection'];
        $properties = $metadata['properties'];

        if (!$reflection->isInstantiable()) {
            throw HydrationException::notInstantiable($className);
        }

        // Create or use existing instance
        $instance = $isNewInstance
            ? $reflection->newInstanceWithoutConstructor()
            : $target;

        // Map data to properties
        foreach ($data as $key => $value) {
            $propertyName = $this->resolvePropertyName($key);

            if (!isset($properties[$propertyName])) {
                if ($this->strict) {
                    throw new HydrationException("Unknown property '{$key}' in class '{$className}'");
                }
                continue;
            }

            $property = $properties[$propertyName];
            $value = $this->coerceValue($property, $value, $className);

            $property->setAccessible(true);
            $property->setValue($instance, $value);
        }

        return $instance;
    }

    /**
     * {@inheritDoc}
     */
    public function extract(object $object): array
    {
        $className = $object::class;
        $metadata = $this->getClassMetadata($className);
        $properties = $metadata['properties'];

        $data = [];

        foreach ($properties as $name => $property) {
            $property->setAccessible(true);

            if (!$property->isInitialized($object)) {
                continue;
            }

            $value = $property->getValue($object);

            // Handle nested objects
            if (is_object($value) && !($value instanceof \DateTimeInterface)) {
                $value = $this->extract($value);
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            $data[$name] = $value;
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function supports(string $class): bool
    {
        return class_exists($class);
    }

    /**
     * Register a custom type handler.
     *
     * @param string $type Type name
     * @param callable $handler Handler function: fn(mixed $value): mixed
     * @return static
     */
    public function registerTypeHandler(string $type, callable $handler): static
    {
        $this->typeHandlers[$type] = $handler;
        return $this;
    }

    /**
     * Register a naming strategy.
     *
     * @param string $name Strategy name
     * @param callable $strategy Strategy function: fn(string $key): string
     * @return static
     */
    public function registerNamingStrategy(string $name, callable $strategy): static
    {
        $this->namingStrategies[$name] = $strategy;
        return $this;
    }

    /**
     * Enable/disable strict mode.
     *
     * @param bool $strict
     * @return static
     */
    public function strict(bool $strict = true): static
    {
        $this->strict = $strict;
        return $this;
    }

    /**
     * Get class metadata from cache.
     *
     * @param class-string $className
     * @return array{reflection: ReflectionClass, properties: array<string, ReflectionProperty>}
     */
    private function getClassMetadata(string $className): array
    {
        if (!isset($this->cache[$className])) {
            $reflection = new ReflectionClass($className);
            $properties = [];

            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                $properties[$property->getName()] = $property;
            }

            $this->cache[$className] = [
                'reflection' => $reflection,
                'properties' => $properties,
            ];
        }

        return $this->cache[$className];
    }

    /**
     * Resolve property name using naming strategies.
     *
     * @param string $key
     * @return string
     */
    private function resolvePropertyName(string $key): string
    {
        // Apply snake_case to camelCase by default
        if (str_contains($key, '_')) {
            return lcfirst(str_replace('_', '', ucwords($key, '_')));
        }

        return $key;
    }

    /**
     * Coerce value to property type.
     *
     * @param ReflectionProperty $property
     * @param mixed $value
     * @param string $className
     * @return mixed
     */
    private function coerceValue(ReflectionProperty $property, mixed $value, string $className): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $property->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        // Check custom type handlers
        if (isset($this->typeHandlers[$typeName])) {
            return $this->typeHandlers[$typeName]($value);
        }

        // Handle built-in types
        if ($type->isBuiltin()) {
            return $this->coerceBuiltinType($typeName, $value, $property->getName(), $className);
        }

        // Handle classes
        if (class_exists($typeName)) {
            // DateTime handling
            if (is_subclass_of($typeName, \DateTimeInterface::class) || $typeName === \DateTime::class) {
                if (is_string($value)) {
                    return new \DateTime($value);
                }
                if ($value instanceof \DateTimeInterface) {
                    return $value;
                }
            }

            // DateTimeImmutable handling
            if ($typeName === \DateTimeImmutable::class) {
                if (is_string($value)) {
                    return new \DateTimeImmutable($value);
                }
                if ($value instanceof \DateTimeInterface) {
                    return \DateTimeImmutable::createFromInterface($value);
                }
            }

            // Nested object hydration
            if (is_array($value)) {
                return $this->hydrate($value, $typeName);
            }
        }

        return $value;
    }

    /**
     * Coerce value to built-in type.
     *
     * @param string $type
     * @param mixed $value
     * @param string $propertyName
     * @param string $className
     * @return mixed
     */
    private function coerceBuiltinType(string $type, mixed $value, string $propertyName, string $className): mixed
    {
        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'array' => is_array($value) ? $value : [$value],
            'object' => is_object($value) ? $value : (object) $value,
            'mixed' => $value,
            default => $value,
        };
    }

    /**
     * Register default type handlers.
     *
     * @return void
     */
    private function registerDefaultTypeHandlers(): void
    {
        // Carbon/DateTime handling
        $this->typeHandlers[\DateTime::class] = fn($value) => $value instanceof \DateTime
            ? $value
            : new \DateTime($value);

        $this->typeHandlers[\DateTimeImmutable::class] = fn($value) => $value instanceof \DateTimeImmutable
            ? $value
            : new \DateTimeImmutable($value);
    }
}
