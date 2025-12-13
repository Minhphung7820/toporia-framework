<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

/**
 * Mass Assignment Protection Trait
 *
 * Provides mass assignment protection features:
 * - forceFill() - Bypass fillable/guarded checks
 * - unguard() / reguard() - Globally disable/enable mass assignment protection
 * - isUnguarded() - Check if mass assignment is disabled
 * - preventAccessingMissingAttributes() / preventSilentlyDiscardingAttributes()
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 */
trait HasMassAssignmentProtection
{
    /**
     * Whether mass assignment protection is globally disabled.
     */
    protected static bool $unguarded = false;

    /**
     * Whether to throw exception when accessing missing attributes.
     */
    protected static bool $preventAccessingMissingAttributes = false;

    /**
     * Whether to throw exception when trying to set non-fillable attributes.
     */
    protected static bool $preventSilentlyDiscardingAttributes = false;

    /**
     * Fill model with array of attributes without mass assignment protection.
     *
     * @param array<string, mixed> $attributes
     * @return $this
     */
    public function forceFill(array $attributes): static
    {
        return static::unguarded(function () use ($attributes) {
            return $this->fill($attributes);
        });
    }

    /**
     * Disable mass assignment protection globally.
     *
     * @param bool $state
     */
    public static function unguard(bool $state = true): void
    {
        static::$unguarded = $state;
    }

    /**
     * Enable mass assignment protection globally.
     */
    public static function reguard(): void
    {
        static::$unguarded = false;
    }

    /**
     * Check if mass assignment protection is disabled.
     */
    public static function isUnguarded(): bool
    {
        return static::$unguarded;
    }

    /**
     * Run callback with mass assignment protection disabled.
     *
     * @param callable $callback
     * @return mixed
     */
    public static function unguarded(callable $callback): mixed
    {
        $previousValue = static::$unguarded;
        static::$unguarded = true;

        try {
            return $callback();
        } finally {
            static::$unguarded = $previousValue;
        }
    }

    /**
     * Prevent accessing missing attributes (throw exception instead of returning null).
     *
     * @param bool $shouldPrevent
     */
    public static function preventAccessingMissingAttributes(bool $shouldPrevent = true): void
    {
        static::$preventAccessingMissingAttributes = $shouldPrevent;
    }

    /**
     * Prevent silently discarding non-fillable attributes (throw exception instead).
     *
     * @param bool $shouldPrevent
     */
    public static function preventSilentlyDiscardingAttributes(bool $shouldPrevent = true): void
    {
        static::$preventSilentlyDiscardingAttributes = $shouldPrevent;
    }

    /**
     * Check if given attribute is fillable, considering unguarded state.
     *
     * @param string $key
     * @return bool
     */
    protected function isFillableWithProtection(string $key): bool
    {
        // If unguarded, everything is fillable
        if (static::$unguarded) {
            return true;
        }

        // Use existing isFillable logic
        return method_exists($this, 'isFillable')
            ? $this->isFillable($key)
            : true;
    }

    /**
     * Handle attempting to fill non-fillable attribute.
     *
     * @param string $key
     * @throws \RuntimeException
     */
    protected function handleNonFillableAttribute(string $key): void
    {
        if (static::$preventSilentlyDiscardingAttributes) {
            throw new \RuntimeException(
                sprintf(
                    "Add [%s] to fillable property to allow mass assignment on [%s].",
                    $key,
                    static::class
                )
            );
        }
    }

    /**
     * Handle attempting to access missing attribute.
     *
     * @param string $key
     * @throws \RuntimeException
     */
    protected function handleMissingAttribute(string $key): void
    {
        if (static::$preventAccessingMissingAttributes) {
            throw new \RuntimeException(
                sprintf(
                    "Attribute [%s] does not exist on model [%s].",
                    $key,
                    static::class
                )
            );
        }
    }
}
