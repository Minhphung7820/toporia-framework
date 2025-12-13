<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

/**
 * Trait HasAccessorsAndMutators
 *
 * Provides Modern ORM accessor and mutator support for Model attributes.
 * Allows defining custom getters and setters using magic methods.
 *
 * Features:
 * - Accessor methods: get{Attribute}Attribute()
 * - Mutator methods: set{Attribute}Attribute()
 * - Automatic snake_case to StudlyCase conversion
 * - Backward compatible with existing getAttribute/setAttribute
 *
 * Performance:
 * - O(1) method lookup using method_exists
 * - Cached method checks for repeated access
 * - No reflection overhead
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
trait HasAccessorsAndMutators
{
    /**
     * Cache for accessor/mutator method existence checks.
     *
     * @var array<string, bool>
     */
    private static array $accessorCache = [];

    /**
     * Cache for mutator method existence checks.
     *
     * @var array<string, bool>
     */
    private static array $mutatorCache = [];

    /**
     * Get an attribute value with accessor support.
     *
     * Checks for accessor method (get{Attribute}Attribute) and calls it if exists.
     * Otherwise falls back to default getAttribute behavior.
     *
     * Example:
     * ```php
     * class User extends Model {
     *     // Accessor for 'full_name' attribute
     *     protected function getFullNameAttribute(): string {
     *         return $this->attributes['first_name'] . ' ' . $this->attributes['last_name'];
     *     }
     * }
     *
     * $user = User::find(1);
     * echo $user->full_name; // Calls getFullNameAttribute()
     * ```
     *
     * @param string $key Attribute key (snake_case)
     * @return mixed
     */
    protected function getAttributeValue(string $key): mixed
    {
        // Check if accessor exists
        if ($this->hasGetAccessor($key)) {
            return $this->getAccessorValue($key);
        }

        // Fall back to parent implementation
        if (method_exists($this, 'parentGetAttribute')) {
            return $this->parentGetAttribute($key);
        }

        // Direct attribute access with casting
        if (!array_key_exists($key, $this->attributes)) {
            // Handle missing attribute if prevention is enabled
            if (method_exists($this, 'handleMissingAttribute')) {
                $this->handleMissingAttribute($key);
            }
            return null;
        }
        
        $value = $this->attributes[$key];
        return $this->castAttribute($key, $value);
    }

    /**
     * Set an attribute value with mutator support.
     *
     * Checks for mutator method (set{Attribute}Attribute) and calls it if exists.
     * Otherwise falls back to default setAttribute behavior.
     *
     * Example:
     * ```php
     * class User extends Model {
     *     // Mutator for 'password' attribute
     *     protected function setPasswordAttribute(string $value): void {
     *         $this->attributes['password'] = password_hash($value, PASSWORD_BCRYPT);
     *     }
     * }
     *
     * $user = new User();
     * $user->password = 'secret123'; // Calls setPasswordAttribute()
     * ```
     *
     * @param string $key Attribute key (snake_case)
     * @param mixed $value Value to set
     * @return void
     */
    protected function setAttributeValue(string $key, mixed $value): void
    {
        // Check if mutator exists
        if ($this->hasSetMutator($key)) {
            $this->setMutatorValue($key, $value);
            return;
        }

        // Fall back to parent implementation
        if (method_exists($this, 'parentSetAttribute')) {
            $this->parentSetAttribute($key, $value);
            return;
        }

        // Direct attribute assignment
        $this->attributes[$key] = $value;
    }

    /**
     * Check if an accessor exists for the given attribute.
     *
     * Accessor method naming: get{StudlyCase}Attribute
     * Example: first_name -> getFirstNameAttribute
     *
     * @param string $key Attribute key (snake_case)
     * @return bool
     */
    protected function hasGetAccessor(string $key): bool
    {
        $cacheKey = static::class . '::' . $key;

        if (!isset(self::$accessorCache[$cacheKey])) {
            $method = 'get' . $this->studlyCase($key) . 'Attribute';
            self::$accessorCache[$cacheKey] = method_exists($this, $method);
        }

        return self::$accessorCache[$cacheKey];
    }

    /**
     * Check if a mutator exists for the given attribute.
     *
     * Mutator method naming: set{StudlyCase}Attribute
     * Example: first_name -> setFirstNameAttribute
     *
     * @param string $key Attribute key (snake_case)
     * @return bool
     */
    protected function hasSetMutator(string $key): bool
    {
        $cacheKey = static::class . '::' . $key;

        if (!isset(self::$mutatorCache[$cacheKey])) {
            $method = 'set' . $this->studlyCase($key) . 'Attribute';
            self::$mutatorCache[$cacheKey] = method_exists($this, $method);
        }

        return self::$mutatorCache[$cacheKey];
    }

    /**
     * Get the value of an attribute using its accessor.
     *
     * @param string $key Attribute key
     * @return mixed
     */
    protected function getAccessorValue(string $key): mixed
    {
        $method = 'get' . $this->studlyCase($key) . 'Attribute';
        return $this->$method();
    }

    /**
     * Set the value of an attribute using its mutator.
     *
     * @param string $key Attribute key
     * @param mixed $value Value to set
     * @return void
     */
    protected function setMutatorValue(string $key, mixed $value): void
    {
        $method = 'set' . $this->studlyCase($key) . 'Attribute';
        $this->$method($value);
    }

    /**
     * Convert snake_case to StudlyCase.
     *
     * Example: first_name -> FirstName
     *
     * @param string $value String to convert
     * @return string
     */
    protected function studlyCase(string $value): string
    {
        // Replace underscores and hyphens with spaces
        $value = str_replace(['_', '-'], ' ', $value);

        // Uppercase first letter of each word
        $value = ucwords($value);

        // Remove spaces
        return str_replace(' ', '', $value);
    }

    /**
     * Get all attribute accessor keys.
     *
     * Returns list of attributes that have accessor methods defined.
     *
     * @return array<string>
     */
    protected function getAccessorKeys(): array
    {
        $methods = get_class_methods($this);
        $accessors = [];

        foreach ($methods as $method) {
            if (preg_match('/^get(.+)Attribute$/', $method, $matches)) {
                $accessors[] = $this->snakeCase($matches[1]);
            }
        }

        return $accessors;
    }

    /**
     * Get all attribute mutator keys.
     *
     * Returns list of attributes that have mutator methods defined.
     *
     * @return array<string>
     */
    protected function getMutatorKeys(): array
    {
        $methods = get_class_methods($this);
        $mutators = [];

        foreach ($methods as $method) {
            if (preg_match('/^set(.+)Attribute$/', $method, $matches)) {
                $mutators[] = $this->snakeCase($matches[1]);
            }
        }

        return $mutators;
    }

    /**
     * Convert StudlyCase to snake_case.
     *
     * Example: FirstName -> first_name
     *
     * @param string $value String to convert
     * @return string
     */
    protected function snakeCase(string $value): string
    {
        // Insert underscore before uppercase letters (except first)
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        // Convert to lowercase
        return strtolower($value);
    }
}
