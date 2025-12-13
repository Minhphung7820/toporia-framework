<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;

/**
 * Class HigherOrderWhenProxy
 *
 * Proxy class for higher-order conditional method calls - Enables fluent syntax like: $collection->when($condition)->map(...)
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
 * This allows method calls to be conditionally applied without
 * wrapping them in closures.
 *
 * Performance:
 * - O(1) method forwarding
 * - No reflection overhead
 * - Lazy execution (only when condition is true)
 *
 * Example:
 * ```php
 * $query->when($sortBy)->orderBy($sortBy);
 * // Equivalent to:
 * $query->when($sortBy, fn($q) => $q->orderBy($sortBy));
 * ```
 *
 * @template TTarget
 */
class HigherOrderWhenProxy
{
    /**
     * @param TTarget $target The target object
     * @param bool $condition The condition result
     */
    public function __construct(
        protected readonly object $target,
        protected readonly bool $condition
    ) {}

    /**
     * Proxy method calls to the target.
     *
     * @param string $method
     * @param array $parameters
     * @return TTarget
     */
    public function __call(string $method, array $parameters): mixed
    {
        if ($this->condition) {
            return $this->target->{$method}(...$parameters);
        }

        return $this->target;
    }

    /**
     * Proxy property access to the target.
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        if ($this->condition) {
            return $this->target->{$name};
        }

        return $this->target;
    }
}
