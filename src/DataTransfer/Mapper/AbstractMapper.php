<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Mapper;

use Toporia\Framework\DataTransfer\Contracts\MapperInterface;
use Toporia\Framework\DataTransfer\Exceptions\TransformationException;

/**
 * Class AbstractMapper
 *
 * Base class for object-to-object mappers.
 * Provides common mapping functionality with caching and batch support.
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
 *
 * @template TSource
 * @template TTarget
 * @implements MapperInterface<TSource, TTarget>
 */
abstract class AbstractMapper implements MapperInterface
{
    /**
     * Source type class name.
     *
     * @var class-string<TSource>
     */
    protected string $sourceType;

    /**
     * Target type class name.
     *
     * @var class-string<TTarget>
     */
    protected string $targetType;

    /**
     * Enable/disable caching.
     *
     * @var bool
     */
    protected bool $cacheEnabled = false;

    /**
     * Cached mapping results.
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * {@inheritDoc}
     */
    public function map(mixed $source, array $context = []): mixed
    {
        $this->validateSource($source);

        $cacheKey = $this->cacheEnabled ? $this->getCacheKey($source, $context) : null;

        if ($cacheKey !== null && isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $result = $this->doMap($source, $context);

        if ($cacheKey !== null) {
            $this->cache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function mapCollection(iterable $sources, array $context = []): array
    {
        $results = [];

        foreach ($sources as $source) {
            $results[] = $this->map($source, $context);
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    /**
     * {@inheritDoc}
     */
    public function getTargetType(): string
    {
        return $this->targetType;
    }

    /**
     * Perform the actual mapping.
     *
     * @param TSource $source
     * @param array<string, mixed> $context
     * @return TTarget
     */
    abstract protected function doMap(mixed $source, array $context): mixed;

    /**
     * Enable caching.
     *
     * @param bool $enabled
     * @return static
     */
    public function withCache(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->cacheEnabled = $enabled;
        return $clone;
    }

    /**
     * Clear cache.
     *
     * @return static
     */
    public function clearCache(): static
    {
        $this->cache = [];
        return $this;
    }

    /**
     * Validate source object.
     *
     * @param mixed $source
     * @throws TransformationException
     */
    protected function validateSource(mixed $source): void
    {
        if (!$source instanceof $this->sourceType) {
            $actualType = is_object($source) ? $source::class : gettype($source);
            throw new TransformationException(
                "Expected source of type {$this->sourceType}, got {$actualType}",
                $actualType,
                $this->targetType
            );
        }
    }

    /**
     * Generate cache key for source object.
     *
     * @param mixed $source
     * @param array<string, mixed> $context
     * @return string
     */
    protected function getCacheKey(mixed $source, array $context): string
    {
        $sourceKey = spl_object_hash($source);
        $contextKey = md5(json_encode($context));
        return "{$sourceKey}:{$contextKey}";
    }
}
