<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Mapper;

use Toporia\Framework\DataTransfer\Contracts\MapperInterface;
use Toporia\Framework\DataTransfer\Exceptions\TransformationException;

/**
 * Class MapperRegistry
 *
 * Central registry for object mappers.
 * Provides automatic mapper resolution based on source/target types.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\Mapper
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class MapperRegistry
{
    /**
     * Registered mappers indexed by "source:target" key.
     *
     * @var array<string, MapperInterface>
     */
    private array $mappers = [];

    /**
     * Mapper factories for lazy initialization.
     *
     * @var array<string, callable>
     */
    private array $factories = [];

    /**
     * Register a mapper.
     *
     * @param MapperInterface $mapper
     * @return static
     */
    public function register(MapperInterface $mapper): static
    {
        $key = $this->makeKey($mapper->getSourceType(), $mapper->getTargetType());
        $this->mappers[$key] = $mapper;
        return $this;
    }

    /**
     * Register a mapper factory for lazy initialization.
     *
     * @param string $sourceType
     * @param string $targetType
     * @param callable $factory Factory: fn(): MapperInterface
     * @return static
     */
    public function registerFactory(string $sourceType, string $targetType, callable $factory): static
    {
        $key = $this->makeKey($sourceType, $targetType);
        $this->factories[$key] = $factory;
        return $this;
    }

    /**
     * Get mapper for source/target pair.
     *
     * @param string $sourceType
     * @param string $targetType
     * @return MapperInterface
     * @throws TransformationException
     */
    public function get(string $sourceType, string $targetType): MapperInterface
    {
        $key = $this->makeKey($sourceType, $targetType);

        // Check registered mappers
        if (isset($this->mappers[$key])) {
            return $this->mappers[$key];
        }

        // Check factories
        if (isset($this->factories[$key])) {
            $this->mappers[$key] = ($this->factories[$key])();
            unset($this->factories[$key]);
            return $this->mappers[$key];
        }

        // Try to find mapper for parent classes/interfaces
        $mapper = $this->findMapperByInheritance($sourceType, $targetType);
        if ($mapper !== null) {
            return $mapper;
        }

        throw TransformationException::noTransformer("{$sourceType} -> {$targetType}");
    }

    /**
     * Check if mapper exists for source/target pair.
     *
     * @param string $sourceType
     * @param string $targetType
     * @return bool
     */
    public function has(string $sourceType, string $targetType): bool
    {
        $key = $this->makeKey($sourceType, $targetType);
        return isset($this->mappers[$key]) || isset($this->factories[$key]);
    }

    /**
     * Map source object to target type.
     *
     * @param mixed $source
     * @param string $targetType
     * @param array<string, mixed> $context
     * @return mixed
     */
    public function map(mixed $source, string $targetType, array $context = []): mixed
    {
        $sourceType = is_object($source) ? $source::class : gettype($source);
        $mapper = $this->get($sourceType, $targetType);
        return $mapper->map($source, $context);
    }

    /**
     * Map collection to target type.
     *
     * @param iterable $sources
     * @param string $targetType
     * @param array<string, mixed> $context
     * @return array
     */
    public function mapCollection(iterable $sources, string $targetType, array $context = []): array
    {
        $results = [];
        $mapper = null;

        foreach ($sources as $source) {
            if ($mapper === null) {
                $sourceType = is_object($source) ? $source::class : gettype($source);
                $mapper = $this->get($sourceType, $targetType);
            }

            $results[] = $mapper->map($source, $context);
        }

        return $results;
    }

    /**
     * Get all registered mappers.
     *
     * @return array<string, MapperInterface>
     */
    public function all(): array
    {
        return $this->mappers;
    }

    /**
     * Clear all registered mappers.
     *
     * @return static
     */
    public function clear(): static
    {
        $this->mappers = [];
        $this->factories = [];
        return $this;
    }

    /**
     * Create mapper key from source and target types.
     *
     * @param string $sourceType
     * @param string $targetType
     * @return string
     */
    private function makeKey(string $sourceType, string $targetType): string
    {
        return "{$sourceType}:{$targetType}";
    }

    /**
     * Find mapper by checking parent classes and interfaces.
     *
     * @param string $sourceType
     * @param string $targetType
     * @return MapperInterface|null
     */
    private function findMapperByInheritance(string $sourceType, string $targetType): ?MapperInterface
    {
        if (!class_exists($sourceType)) {
            return null;
        }

        // Check parent classes
        $parent = get_parent_class($sourceType);
        while ($parent !== false) {
            $key = $this->makeKey($parent, $targetType);
            if (isset($this->mappers[$key])) {
                return $this->mappers[$key];
            }
            $parent = get_parent_class($parent);
        }

        // Check interfaces
        foreach (class_implements($sourceType) ?: [] as $interface) {
            $key = $this->makeKey($interface, $targetType);
            if (isset($this->mappers[$key])) {
                return $this->mappers[$key];
            }
        }

        return null;
    }
}
