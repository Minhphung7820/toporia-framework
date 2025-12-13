<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

/**
 * Trait HasSerialization
 *
 * Enhanced serialization support for Model with hidden/visible attributes,
 * appended accessors, and custom formatting.
 *
 * Features:
 * - Hide sensitive attributes (hidden)
 * - Show only specific attributes (visible)
 * - Append computed attributes (appends)
 * - Dynamic hidden/visible control
 * - Nested relationship serialization
 *
 * Performance:
 * - O(N) where N = number of attributes
 * - Efficient array filtering
 * - Minimal memory overhead
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\ORM\Concerns
 * @since       2025-01-22
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasSerialization
{
    /**
     * Temporarily appended attributes (instance-level).
     *
     * These are merged with static::$appends for dynamic append control.
     *
     * @var array<string>
     */
    protected array $tempAppends = [];

    /**
     * Temporarily hidden attributes (instance-level).
     *
     * @var array<string>
     */
    protected array $tempHidden = [];

    /**
     * Temporarily visible attributes (instance-level).
     *
     * @var array<string>
     */
    protected array $tempVisible = [];

    /**
     * Convert model to array with hidden/visible/appends support.
     *
     * Example:
     * ```php
     * class User extends Model {
     *     protected static array $hidden = ['password'];
     *     protected array $appends = ['full_name'];
     *
     *     protected function getFullNameAttribute(): string {
     *         return $this->first_name . ' ' . $this->last_name;
     *     }
     * }
     *
     * $user->toArray();
     * // Returns: ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe', 'full_name' => 'John Doe']
     * // 'password' is hidden
     * ```
     *
     * @return array<string, mixed>
     */
    public function toArrayWithOptions(): array
    {
        $attributes = $this->getArrayableAttributes();

        // Add relationships
        if (property_exists($this, 'relations') && !empty($this->relations)) {
            foreach ($this->relations as $key => $value) {
                if (is_object($value) && method_exists($value, 'toArray')) {
                    $attributes[$key] = $value->toArray();
                } elseif (is_array($value)) {
                    $attributes[$key] = $value;
                } else {
                    $attributes[$key] = $value;
                }
            }
        }

        // Append accessor attributes
        foreach ($this->getArrayableAppends() as $key) {
            if (method_exists($this, 'getAttributeValue')) {
                $attributes[$key] = $this->getAttributeValue($key);
            } elseif (method_exists($this, 'getAttribute')) {
                $attributes[$key] = $this->getAttribute($key);
            }
        }

        return $attributes;
    }

    /**
     * Get attributes that should be converted to array.
     *
     * Applies hidden/visible filtering.
     * When $visible is set, it takes precedence over $hidden.
     *
     * @return array<string, mixed>
     */
    protected function getArrayableAttributes(): array
    {
        $attributes = method_exists($this, 'getAllAttributes')
            ? $this->getAllAttributes()
            : [];

        // Apply visible filter (if set)
        $visible = $this->getVisible();
        if (!empty($visible)) {
            // When visible is set, only show visible attributes (ignore hidden)
            $attributes = array_intersect_key($attributes, array_flip($visible));
            return $attributes;
        }

        // Apply hidden filter (only if visible is not set)
        $hidden = $this->getHidden();
        if (!empty($hidden)) {
            $attributes = array_diff_key($attributes, array_flip($hidden));
        }

        return $attributes;
    }

    /**
     * Get appended attributes for array conversion.
     *
     * Combines static::$appends with instance-level tempAppends.
     *
     * @return array<string>
     */
    protected function getArrayableAppends(): array
    {
        $staticAppends = static::$appends ?? [];
        $allAppends = array_unique(array_merge($staticAppends, $this->tempAppends));

        if (empty($allAppends)) {
            return [];
        }

        // Filter out appends that are in hidden list
        return array_diff($allAppends, $this->getHidden());
    }

    /**
     * Get the hidden attributes for the model.
     *
     * Combines static hidden with temporary hidden.
     *
     * @return array<string>
     */
    protected function getHidden(): array
    {
        $hidden = static::$hidden ?? [];

        return array_merge($hidden, $this->tempHidden);
    }

    /**
     * Get the visible attributes for the model.
     *
     * @return array<string>
     */
    protected function getVisible(): array
    {
        if (!empty($this->tempVisible)) {
            return $this->tempVisible;
        }

        return static::$visible ?? [];
    }

    /**
     * Make the given attributes visible.
     *
     * Temporarily shows hidden attributes for this instance.
     * This overrides both static::$hidden and $tempHidden.
     *
     * Example:
     * ```php
     * $user->makeVisible('password')->toArray();
     * ```
     *
     * @param string|array<string> $attributes Attributes to show
     * @return $this
     */
    public function makeVisible(string|array $attributes): self
    {
        $attributes = is_array($attributes) ? $attributes : [$attributes];

        // Remove from temp hidden
        $this->tempHidden = array_diff($this->tempHidden, $attributes);

        // Add to temp visible to override static hidden
        // We need to build a merged visible list if tempVisible is already set
        if (!empty($this->tempVisible)) {
            $this->tempVisible = array_unique(array_merge($this->tempVisible, $attributes));
        } else {
            // If tempVisible is empty, we need to add all current visible attributes plus the new ones
            // Get all attribute keys
            $allKeys = array_keys($this->getAllAttributes());
            $staticHidden = static::$hidden ?? [];

            // Start with all attributes minus static hidden, then add back the makeVisible attributes
            $this->tempVisible = array_unique(array_merge(
                array_diff($allKeys, array_diff($staticHidden, $attributes)),
                $attributes
            ));
        }

        return $this;
    }

    /**
     * Make the given attributes hidden.
     *
     * Temporarily hides attributes for this instance.
     *
     * Example:
     * ```php
     * $user->makeHidden('email')->toArray();
     * ```
     *
     * @param string|array<string> $attributes Attributes to hide
     * @return $this
     */
    public function makeHidden(string|array $attributes): self
    {
        $attributes = is_array($attributes) ? $attributes : [$attributes];

        $this->tempHidden = array_unique(array_merge($this->tempHidden, $attributes));

        return $this;
    }

    /**
     * Set the visible attributes for the model.
     *
     * When set, only these attributes will be included in array/JSON.
     *
     * Example:
     * ```php
     * $user->setVisible(['id', 'name'])->toArray();
     * ```
     *
     * @param array<string> $visible Attributes to show (exclusively)
     * @return $this
     */
    public function setVisible(array $visible): self
    {
        $this->tempVisible = $visible;

        return $this;
    }

    /**
     * Set the hidden attributes for the model.
     *
     * Example:
     * ```php
     * $user->setHidden(['password', 'token'])->toArray();
     * ```
     *
     * @param array<string> $hidden Attributes to hide
     * @return $this
     */
    public function setHidden(array $hidden): self
    {
        $this->tempHidden = $hidden;

        return $this;
    }

    /**
     * Append attributes to array/JSON output.
     *
     * Adds to instance-level temporary appends (merged with static::$appends).
     *
     * Example:
     * ```php
     * $user->append('full_name')->toArray();
     * ```
     *
     * @param string|array<string> $attributes Attributes to append
     * @return $this
     */
    public function append(string|array $attributes): self
    {
        $attributes = is_array($attributes) ? $attributes : [$attributes];

        $this->tempAppends = array_unique(array_merge($this->tempAppends, $attributes));

        return $this;
    }

    /**
     * Set the appended attributes.
     *
     * Replaces instance-level temporary appends.
     *
     * @param array<string> $appends Attributes to append
     * @return $this
     */
    public function setAppends(array $appends): self
    {
        $this->tempAppends = $appends;

        return $this;
    }

    /**
     * Get the appended attributes.
     *
     * Returns combined static and instance-level appends.
     *
     * @return array<string>
     */
    public function getAppends(): array
    {
        $staticAppends = static::$appends ?? [];
        return array_unique(array_merge($staticAppends, $this->tempAppends));
    }
}
