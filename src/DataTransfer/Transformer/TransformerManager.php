<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Transformer;

use Toporia\Framework\DataTransfer\Contracts\TransformerInterface;
use Toporia\Framework\DataTransfer\Exceptions\TransformationException;
use Toporia\Framework\DataTransfer\Resource\JsonResource;
use Toporia\Framework\DataTransfer\Resource\ResourceCollection;

/**
 * Class TransformerManager
 *
 * Central manager for entity transformers.
 * Provides automatic transformer resolution and registration.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\Transformer
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class TransformerManager
{
    /**
     * Registered transformers.
     *
     * @var array<class-string, TransformerInterface>
     */
    private array $transformers = [];

    /**
     * Transformer factories.
     *
     * @var array<class-string, callable>
     */
    private array $factories = [];

    /**
     * Global includes to apply.
     *
     * @var array<string>
     */
    private array $globalIncludes = [];

    /**
     * Global excludes.
     *
     * @var array<string>
     */
    private array $globalExcludes = [];

    /**
     * Register a transformer for entity type.
     *
     * @param class-string $entityClass
     * @param TransformerInterface $transformer
     * @return static
     */
    public function register(string $entityClass, TransformerInterface $transformer): static
    {
        $this->transformers[$entityClass] = $transformer;
        return $this;
    }

    /**
     * Register a transformer factory.
     *
     * @param class-string $entityClass
     * @param callable $factory
     * @return static
     */
    public function registerFactory(string $entityClass, callable $factory): static
    {
        $this->factories[$entityClass] = $factory;
        return $this;
    }

    /**
     * Get transformer for entity.
     *
     * @param mixed $entity
     * @return TransformerInterface
     * @throws TransformationException
     */
    public function getTransformer(mixed $entity): TransformerInterface
    {
        $entityClass = is_object($entity) ? $entity::class : (string) $entity;

        // Check registered transformers
        if (isset($this->transformers[$entityClass])) {
            return $this->transformers[$entityClass];
        }

        // Check factories
        if (isset($this->factories[$entityClass])) {
            $this->transformers[$entityClass] = ($this->factories[$entityClass])();
            unset($this->factories[$entityClass]);
            return $this->transformers[$entityClass];
        }

        // Try parent classes
        foreach ($this->transformers as $class => $transformer) {
            if (is_a($entityClass, $class, true)) {
                return $transformer;
            }
        }

        // Try interfaces
        if (class_exists($entityClass)) {
            foreach (class_implements($entityClass) ?: [] as $interface) {
                if (isset($this->transformers[$interface])) {
                    return $this->transformers[$interface];
                }
            }
        }

        throw TransformationException::noTransformer($entityClass);
    }

    /**
     * Check if transformer exists for entity.
     *
     * @param mixed $entity
     * @return bool
     */
    public function hasTransformer(mixed $entity): bool
    {
        try {
            $this->getTransformer($entity);
            return true;
        } catch (TransformationException) {
            return false;
        }
    }

    /**
     * Transform entity.
     *
     * @param mixed $entity
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function transform(mixed $entity, array $context = []): array
    {
        $context = $this->mergeGlobalContext($context);
        $transformer = $this->getTransformer($entity);

        $result = $transformer->transform($entity, $context);

        return is_array($result) ? $result : (array) $result;
    }

    /**
     * Transform collection of entities.
     *
     * @param iterable $entities
     * @param array<string, mixed> $context
     * @return array<int, array>
     */
    public function collection(iterable $entities, array $context = []): array
    {
        $context = $this->mergeGlobalContext($context);
        $results = [];
        $transformer = null;

        foreach ($entities as $entity) {
            if ($transformer === null) {
                $transformer = $this->getTransformer($entity);
            }

            $result = $transformer->transform($entity, $context);
            $results[] = is_array($result) ? $result : (array) $result;
        }

        return $results;
    }

    /**
     * Create JSON resource from entity.
     *
     * @param mixed $entity
     * @param class-string<JsonResource>|null $resourceClass
     * @return JsonResource
     */
    public function resource(mixed $entity, ?string $resourceClass = null): JsonResource
    {
        if ($resourceClass !== null) {
            return new $resourceClass($entity);
        }

        // Try to find registered resource class
        $data = $this->hasTransformer($entity)
            ? $this->transform($entity)
            : $entity;

        return new JsonResource($data);
    }

    /**
     * Create resource collection.
     *
     * @param iterable $entities
     * @param class-string<JsonResource>|null $resourceClass
     * @return ResourceCollection
     */
    public function resourceCollection(iterable $entities, ?string $resourceClass = null): ResourceCollection
    {
        if ($resourceClass !== null) {
            return new ResourceCollection($entities, $resourceClass);
        }

        // Transform if transformer available
        $items = [];
        foreach ($entities as $entity) {
            $items[] = $this->hasTransformer($entity)
                ? $this->transform($entity)
                : $entity;
        }

        return new ResourceCollection($items, JsonResource::class);
    }

    /**
     * Set global includes.
     *
     * @param array<string> $includes
     * @return static
     */
    public function setGlobalIncludes(array $includes): static
    {
        $this->globalIncludes = $includes;
        return $this;
    }

    /**
     * Add global include.
     *
     * @param string $include
     * @return static
     */
    public function addGlobalInclude(string $include): static
    {
        $this->globalIncludes[] = $include;
        return $this;
    }

    /**
     * Set global excludes.
     *
     * @param array<string> $excludes
     * @return static
     */
    public function setGlobalExcludes(array $excludes): static
    {
        $this->globalExcludes = $excludes;
        return $this;
    }

    /**
     * Get all registered transformers.
     *
     * @return array<class-string, TransformerInterface>
     */
    public function getTransformers(): array
    {
        return $this->transformers;
    }

    /**
     * Clear all transformers.
     *
     * @return static
     */
    public function clear(): static
    {
        $this->transformers = [];
        $this->factories = [];
        return $this;
    }

    /**
     * Merge global context with provided context.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function mergeGlobalContext(array $context): array
    {
        if (!empty($this->globalIncludes) && !isset($context['include'])) {
            $context['include'] = $this->globalIncludes;
        }

        if (!empty($this->globalExcludes) && !isset($context['exclude'])) {
            $context['exclude'] = $this->globalExcludes;
        }

        return $context;
    }
}
