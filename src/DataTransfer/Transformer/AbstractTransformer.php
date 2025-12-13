<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Transformer;

use Toporia\Framework\DataTransfer\Contracts\TransformerInterface;
use Toporia\Framework\DataTransfer\Resource\JsonResource;
use Toporia\Framework\DataTransfer\Exceptions\TransformationException;

/**
 * Class AbstractTransformer
 *
 * Base class for entity transformers.
 * Provides common transformation functionality with includes support.
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
abstract class AbstractTransformer implements TransformerInterface
{
    /**
     * Available includes.
     *
     * @var array<string>
     */
    protected array $availableIncludes = [];

    /**
     * Default includes.
     *
     * @var array<string>
     */
    protected array $defaultIncludes = [];

    /**
     * Current includes to process.
     *
     * @var array<string>
     */
    protected array $currentIncludes = [];

    /**
     * Transformation cache.
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * Cache enabled flag.
     *
     * @var bool
     */
    private bool $cacheEnabled = false;

    /**
     * {@inheritDoc}
     */
    abstract public function transform(mixed $entity, array $context = []): mixed;

    /**
     * {@inheritDoc}
     */
    public function transformCollection(iterable $entities, array $context = []): array
    {
        $results = [];

        foreach ($entities as $entity) {
            $result = $this->transformWithIncludes($entity, $context);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function supports(mixed $entity): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableIncludes(): array
    {
        return $this->availableIncludes;
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultIncludes(): array
    {
        return $this->defaultIncludes;
    }

    /**
     * Set includes to process.
     *
     * @param array<string> $includes
     * @return static
     */
    public function setIncludes(array $includes): static
    {
        $this->currentIncludes = array_intersect($includes, $this->availableIncludes);
        return $this;
    }

    /**
     * Add include.
     *
     * @param string $include
     * @return static
     */
    public function include(string $include): static
    {
        if (in_array($include, $this->availableIncludes, true)) {
            $this->currentIncludes[] = $include;
        }
        return $this;
    }

    /**
     * Enable caching.
     *
     * @param bool $enabled
     * @return static
     */
    public function withCache(bool $enabled = true): static
    {
        $this->cacheEnabled = $enabled;
        return $this;
    }

    /**
     * Clear transformation cache.
     *
     * @return static
     */
    public function clearCache(): static
    {
        $this->cache = [];
        return $this;
    }

    /**
     * Transform with includes processing.
     *
     * @param mixed $entity
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function transformWithIncludes(mixed $entity, array $context = []): array
    {
        // Check cache
        if ($this->cacheEnabled) {
            $cacheKey = $this->getCacheKey($entity, $context);
            if (isset($this->cache[$cacheKey])) {
                return $this->cache[$cacheKey];
            }
        }

        // Transform base entity
        $result = $this->transform($entity, $context);

        // Ensure result is array
        if (!is_array($result)) {
            if ($result instanceof JsonResource) {
                $result = $result->toArray();
            } else {
                $result = (array) $result;
            }
        }

        // Process includes
        $includes = $this->getIncludesToProcess($context);

        foreach ($includes as $include) {
            $includeData = $this->processInclude($entity, $include, $context);
            if ($includeData !== null) {
                $result[$include] = $includeData;
            }
        }

        // Cache result
        if ($this->cacheEnabled) {
            $this->cache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * Get includes to process.
     *
     * @param array<string, mixed> $context
     * @return array<string>
     */
    protected function getIncludesToProcess(array $context): array
    {
        // Check for context includes
        if (isset($context['include'])) {
            $requestedIncludes = is_string($context['include'])
                ? explode(',', $context['include'])
                : (array) $context['include'];

            return array_intersect($requestedIncludes, $this->availableIncludes);
        }

        // Use current includes if set
        if (!empty($this->currentIncludes)) {
            return $this->currentIncludes;
        }

        // Fall back to default includes
        return $this->defaultIncludes;
    }

    /**
     * Process a single include.
     *
     * @param mixed $entity
     * @param string $include
     * @param array<string, mixed> $context
     * @return mixed
     */
    protected function processInclude(mixed $entity, string $include, array $context): mixed
    {
        $method = 'include' . ucfirst($include);

        if (method_exists($this, $method)) {
            return $this->{$method}($entity, $context);
        }

        // Try to get attribute directly from entity
        if (is_object($entity) && property_exists($entity, $include)) {
            return $entity->{$include};
        }

        if (is_array($entity) && isset($entity[$include])) {
            return $entity[$include];
        }

        return null;
    }

    /**
     * Generate cache key for entity.
     *
     * @param mixed $entity
     * @param array<string, mixed> $context
     * @return string
     */
    protected function getCacheKey(mixed $entity, array $context): string
    {
        $entityKey = is_object($entity) ? spl_object_hash($entity) : md5(serialize($entity));
        $contextKey = md5(json_encode($context));
        $includesKey = md5(implode(',', $this->getIncludesToProcess($context)));

        return "{$entityKey}:{$contextKey}:{$includesKey}";
    }

    /**
     * Transform null safely.
     *
     * @param mixed $value
     * @param callable $transformer
     * @return mixed
     */
    protected function transformNullable(mixed $value, callable $transformer): mixed
    {
        return $value !== null ? $transformer($value) : null;
    }

    /**
     * Transform date to string.
     *
     * @param \DateTimeInterface|null $date
     * @param string $format
     * @return string|null
     */
    protected function transformDate(?\DateTimeInterface $date, string $format = 'Y-m-d H:i:s'): ?string
    {
        return $date?->format($format);
    }

    /**
     * Transform date to ISO 8601.
     *
     * @param \DateTimeInterface|null $date
     * @return string|null
     */
    protected function transformDateISO(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DateTimeInterface::ATOM);
    }
}
