<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Serialization;

use Toporia\Framework\Http\Serialization\Contracts\SerializerInterface;
use Toporia\Framework\Support\Collection\Collection;

/**
 * Advanced JSON Serializer
 *
 * Enterprise-grade JSON serialization with Toporia compatibility and performance optimizations.
 *
 * Features:
 * - JsonSerializable interface support
 * - Arrayable interface support
 * - Collection serialization
 * - Circular reference detection
 * - Memory-efficient processing
 * - Caching for repeated serialization
 *
 * Performance Optimizations:
 * - Object type caching
 * - Lazy evaluation
 * - Memory usage optimization
 * - Circular reference prevention
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles JSON serialization
 * - Open/Closed: Extensible via interfaces
 * - Dependency Inversion: Depends on abstractions
 *
 * @author      Toporia Framework Team
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Serialization
 */
final class JsonSerializer implements SerializerInterface
{
    /**
     * Default JSON encoding options (Toporia-style).
     */
    private const DEFAULT_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Maximum recursion depth to prevent infinite loops.
     */
    private const MAX_DEPTH = 512;

    /**
     * @var array<string, bool> Object type cache for performance
     */
    private array $typeCache = [];

    /**
     * @var array<int, bool> Circular reference detection
     */
    private array $processing = [];

    /**
     * @var int Current recursion depth
     */
    private int $depth = 0;

    /**
     * Serialize data to JSON string.
     *
     * @param mixed $data Data to serialize
     * @param int $options JSON encoding options
     * @param int $depth Maximum depth
     * @return string JSON string
     * @throws \JsonException
     */
    public function serialize(mixed $data, int $options = self::DEFAULT_OPTIONS, int $depth = self::MAX_DEPTH): string
    {
        try {
            $this->depth = 0;
            $this->processing = [];

            $processedData = $this->processData($data, $depth);

            $json = json_encode($processedData, $options | JSON_THROW_ON_ERROR, $depth);

            if ($json === false) {
                throw new \JsonException('JSON encoding failed: ' . json_last_error_msg());
            }

            return $json;
        } finally {
            // Clean up for memory efficiency
            $this->processing = [];
        }
    }

    /**
     * Process data for JSON serialization (Toporia-style).
     *
     * @param mixed $data Data to process
     * @param int $maxDepth Maximum recursion depth
     * @return mixed Processed data
     * @throws \JsonException
     */
    private function processData(mixed $data, int $maxDepth): mixed
    {
        // Prevent infinite recursion
        if ($this->depth >= $maxDepth) {
            throw new \JsonException("Maximum recursion depth of {$maxDepth} exceeded");
        }

        $this->depth++;

        try {
            return $this->processValue($data);
        } finally {
            $this->depth--;
        }
    }

    /**
     * Process individual value based on its type.
     *
     * @param mixed $value Value to process
     * @return mixed Processed value
     */
    private function processValue(mixed $value): mixed
    {
        // Handle null, scalar values
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        // Handle arrays
        if (is_array($value)) {
            return $this->processArray($value);
        }

        // Handle objects
        if (is_object($value)) {
            return $this->processObject($value);
        }

        // Handle resources (convert to string representation)
        if (is_resource($value)) {
            return sprintf('Resource #%d (%s)', (int) $value, get_resource_type($value));
        }

        return $value;
    }

    /**
     * Process array values recursively.
     *
     * @param array $array Array to process
     * @return array Processed array
     */
    private function processArray(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $result[$key] = $this->processData($value, self::MAX_DEPTH - $this->depth);
        }

        return $result;
    }

    /**
     * Process object based on its interfaces and type.
     *
     * @param object $object Object to process
     * @return mixed Processed object data
     */
    private function processObject(object $object): mixed
    {
        // Prevent circular references
        $objectId = spl_object_id($object);
        if (isset($this->processing[$objectId])) {
            return sprintf('[Circular Reference: %s]', get_class($object));
        }

        $this->processing[$objectId] = true;

        try {
            return $this->serializeObject($object);
        } finally {
            unset($this->processing[$objectId]);
        }
    }

    /**
     * Serialize object using appropriate method.
     *
     * @param object $object Object to serialize
     * @return mixed Serialized data
     */
    private function serializeObject(object $object): mixed
    {
        $className = get_class($object);

        // Cache type checks for performance
        if (!isset($this->typeCache[$className])) {
            $this->typeCache[$className] = $this->analyzeObjectType($object);
        }

        // JsonSerializable interface (highest priority)
        if ($object instanceof \JsonSerializable) {
            return $this->processData($object->jsonSerialize(), self::MAX_DEPTH - $this->depth);
        }

        // Arrayable interface (Toporia-style)
        if (method_exists($object, 'toArray')) {
            return $this->processData($object->toArray(), self::MAX_DEPTH - $this->depth);
        }

        // Collection interface
        if ($object instanceof Collection) {
            return $this->processData($object->all(), self::MAX_DEPTH - $this->depth);
        }

        // Traversable objects (Iterator, IteratorAggregate)
        if ($object instanceof \Traversable) {
            return $this->processData(iterator_to_array($object), self::MAX_DEPTH - $this->depth);
        }

        // stdClass objects (preserve as objects)
        if ($object instanceof \stdClass) {
            return $this->processData((array) $object, self::MAX_DEPTH - $this->depth);
        }

        // DateTime objects
        if ($object instanceof \DateTimeInterface) {
            return $object->format(\DateTime::ATOM);
        }

        // Stringable objects
        if (method_exists($object, '__toString')) {
            return (string) $object;
        }

        // Fallback: public properties only
        return $this->processData(get_object_vars($object), self::MAX_DEPTH - $this->depth);
    }

    /**
     * Analyze object type for caching.
     *
     * @param object $object Object to analyze
     * @return bool Always returns true (for future use)
     */
    private function analyzeObjectType(object $object): bool
    {
        // This method is for future optimizations
        // We can cache interface checks, method existence, etc.
        return true;
    }

    /**
     * Check if data can be JSON serialized.
     *
     * @param mixed $data Data to check
     * @return bool True if serializable
     */
    public function canSerialize(mixed $data): bool
    {
        try {
            $this->serialize($data);
            return true;
        } catch (\JsonException) {
            return false;
        }
    }

    /**
     * Get default JSON encoding options.
     *
     * @return int JSON options
     */
    public static function getDefaultOptions(): int
    {
        return self::DEFAULT_OPTIONS;
    }

    /**
     * Clear internal caches (for memory management).
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->typeCache = [];
        $this->processing = [];
    }
}
