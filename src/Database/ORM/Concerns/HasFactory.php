<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

use Toporia\Framework\Database\Contracts\FactoryInterface;

/**
 * HasFactory Trait
 *
 * Provides factory method for models, enabling fluent factory usage:
 * ```php
 * UserModel::factory()->count(10)->create();
 * ProductModel::factory()->state('active')->create();
 * ```
 *
 * Performance:
 * - O(1) factory resolution (cached)
 * - Lazy factory instantiation
 * - No reflection overhead after first call
 *
 * Clean Architecture:
 * - Separation of concerns: Factory logic separated from model
 * - Dependency Inversion: Uses FactoryInterface abstraction
 *
 * SOLID Principles:
 * - Single Responsibility: Only handles factory resolution
 * - Open/Closed: Extensible via custom factory classes
 * - Dependency Inversion: Depends on FactoryInterface, not concrete factories
 *
 * Reusability:
 * - Convention-based factory resolution
 * - Custom factory class support
 * - Works with any model extending Model
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  ORM/Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasFactory
{
    /**
     * Factory class name cache.
     * Prevents repeated reflection/string operations.
     *
     * @var array<string, string>
     */
    private static array $factoryCache = [];

    /**
     * Get a new factory instance for the model.
     *
     * Usage:
     * ```php
     * // Create single model
     * UserModel::factory()->create();
     *
     * // Create multiple models
     * UserModel::factory()->count(10)->create();
     *
     * // With attributes
     * UserModel::factory()->create(['name' => 'John']);
     *
     * // With state
     * UserModel::factory()->state('admin')->create();
     *
     * // Make (not persisted)
     * UserModel::factory()->make();
     * ```
     *
     * Performance: O(1) after first call (cached)
     *
     * @return FactoryInterface Factory instance
     * @throws \RuntimeException If factory class not found
     */
    public static function factory(): FactoryInterface
    {
        $factoryClass = static::resolveFactoryClassName();

        if (!class_exists($factoryClass)) {
            throw new \RuntimeException(
                "Factory [{$factoryClass}] not found for model [" . static::class . "]. " .
                    "Create the factory class or specify a custom factory using \$factory property."
            );
        }

        return $factoryClass::new();
    }

    /**
     * Resolve factory class name from model.
     *
     * Convention: Database\Factories\{ModelName}Factory
     * Example: UserModel -> Database\Factories\UserFactory
     *          ProductModel -> Database\Factories\ProductFactory
     *
     * Custom factory: Override via $factory property in model
     *
     * Performance: O(1) after first call (cached)
     *
     * @return string Factory class name
     */
    protected static function resolveFactoryClassName(): string
    {
        $modelClass = static::class;

        // Check cache first
        if (isset(self::$factoryCache[$modelClass])) {
            return self::$factoryCache[$modelClass];
        }

        // Check for custom factory property
        if (property_exists(static::class, 'factory')) {
            $instance = new static();
            $reflectionProperty = new \ReflectionProperty($instance, 'factory');
            $customFactory = $reflectionProperty->getValue($instance);

            if (is_string($customFactory) && !empty($customFactory)) {
                self::$factoryCache[$modelClass] = $customFactory;
                return $customFactory;
            }
        }

        // Convention-based resolution
        $factoryName = static::getFactoryNameFromModel();
        $factoryClass = "Database\\Factories\\{$factoryName}Factory";

        // Cache result
        self::$factoryCache[$modelClass] = $factoryClass;

        return $factoryClass;
    }

    /**
     * Get factory name from model class name.
     *
     * Converts: UserModel -> User
     *          ProductModel -> Product
     *          OrderItemModel -> OrderItem
     *
     * Performance: O(1) string operations
     *
     * @return string Factory name (without Factory suffix)
     */
    protected static function getFactoryNameFromModel(): string
    {
        $className = static::class;
        $shortName = substr($className, strrpos($className, '\\') + 1);

        // Remove "Model" suffix if present
        if (str_ends_with($shortName, 'Model')) {
            return substr($shortName, 0, -5); // Remove "Model"
        }

        return $shortName;
    }

    /**
     * Clear factory cache.
     *
     * Useful for testing or when factory classes are dynamically loaded.
     *
     * @return void
     */
    public static function clearFactoryCache(): void
    {
        self::$factoryCache = [];
    }
}
