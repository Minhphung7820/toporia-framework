<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;

/**
 * Class HigherOrderTapProxy
 *
 * Proxy class for higher-order tap calls - Enables fluent syntax like: tap($object)->method()
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
 * This allows method calls on an object while still returning
 * the original object, useful for chaining side effects.
 *
 * Performance:
 * - O(1) method forwarding
 * - No reflection overhead
 *
 * Example:
 * ```php
 * return tap($user)->update(['name' => 'John']);
 * // Calls $user->update() then returns $user
 * ```
 *
 * @template TTarget of object
 */
class HigherOrderTapProxy
{
    /**
     * @param TTarget $target The target being tapped
     */
    public function __construct(
        public readonly object $target
    ) {}

    /**
     * Proxy method calls to the target and return the target.
     *
     * @param string $method
     * @param array $parameters
     * @return TTarget
     */
    public function __call(string $method, array $parameters): object
    {
        $this->target->{$method}(...$parameters);

        return $this->target;
    }
}
