<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Factories;

use Faker\Generator;
use Closure;
use Toporia\Framework\Database\Contracts\FactoryInterface;
use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Database\Faker\ToportaFakerProvider;

/**
 * Abstract Factory Base Class
 *
 * Professional, enterprise-grade factory system with fluent features:
 * - State modifiers (state())
 * - Sequences for unique values
 * - After making/creating callbacks
 * - Relationships (has, for)
 * - Lazy attributes (Closure evaluation)
 * - Recycle existing models
 * - Batch creation with performance optimization
 * - Configure factory behavior
 *
 * Performance Optimizations:
 * - Lazy attribute evaluation (only when needed)
 * - Batch insert optimization
 * - Model recycling to reduce database queries
 * - Memory-efficient processing
 *
 * Clean Architecture:
 * - Separation of concerns: Factory logic separated from model
 * - Dependency Inversion: Uses FactoryInterface abstraction
 *
 * SOLID Principles:
 * - Single Responsibility: Only handles model creation
 * - Open/Closed: Extensible via states, callbacks, relationships
 * - Dependency Inversion: Depends on FactoryInterface
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Factories
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class Factory implements FactoryInterface
{
    /**
     * Faker instance.
     */
    protected Generator $faker;

    /**
     * Model class name.
     */
    protected string $model;

    /**
     * Default attributes.
     */
    protected array $defaultAttributes = [];

    /**
     * State modifiers.
     * @var array<string, Closure|array>
     */
    protected array $states = [];

    /**
     * Sequence definitions.
     * @var array<string, Closure|array>
     */
    protected array $sequences = [];

    /**
     * Current sequence index.
     */
    protected int $sequenceIndex = 0;

    /**
     * After making callbacks.
     * @var array<Closure>
     */
    protected array $afterMaking = [];

    /**
     * After creating callbacks.
     * @var array<Closure>
     */
    protected array $afterCreating = [];

    /**
     * Number of times to create.
     */
    protected int $count = 1;

    /**
     * Models to recycle (reuse existing models).
     * @var array<int, \Toporia\Framework\Database\ORM\Model>
     */
    protected array $recycle = [];

    /**
     * Relationship factories to create.
     * @var array<string, array{0: FactoryInterface, 1: int, 2: array}>
     */
    protected array $has = [];

    /**
     * Parent relationships to set (belongs-to).
     * @var array<string, array{0: FactoryInterface|Model, 1: string}>
     */
    protected array $for = [];

    /**
     * Many-to-many relationships with pivot data.
     * @var array<string, array{0: FactoryInterface|array, 1: int, 2: array}>
     */
    protected array $hasAttached = [];

    /**
     * Factory configuration.
     * @var array<string, mixed>
     */
    protected array $config = [];

    public function __construct()
    {
        $this->faker = \Faker\Factory::create();

        // Register Toporia custom formatters
        $toportaProvider = new ToportaFakerProvider($this->faker);
        $toportaProvider->register($this->faker);
    }

    /**
     * Define the model's default state.
     *
     * Override in child classes.
     * Supports lazy attributes via Closures.
     *
     * @return array<string, mixed>|Closure
     */
    abstract public function definition(): array;

    /**
     * Create a new factory instance.
     *
     * Performance: O(1)
     */
    public static function new(): static
    {
        return new static();
    }

    /**
     * Set the number of models to create.
     *
     * @param int $count Number of models.
     * @return static
     */
    public function count(int $count): static
    {
        $this->count = $count;
        return $this;
    }

    /**
     * Apply state modifier.
     *
     * @param string|array|callable $state State name, array of attributes, or closure.
     * @return static
     */
    public function state(string|array|callable $state): static
    {
        if (is_string($state)) {
            // Named state
            if (isset($this->states[$state])) {
                $stateDefinition = $this->states[$state];
                if (is_callable($stateDefinition)) {
                    $attributes = $stateDefinition($this->faker);
                    if (is_array($attributes)) {
                        $this->defaultAttributes = array_merge($this->defaultAttributes, $attributes);
                    }
                } elseif (is_array($stateDefinition)) {
                    $this->defaultAttributes = array_merge($this->defaultAttributes, $stateDefinition);
                }
            }
        } elseif (is_array($state)) {
            // Direct attributes
            $this->defaultAttributes = array_merge($this->defaultAttributes, $state);
        } elseif (is_callable($state)) {
            // Closure state
            $attributes = $state($this->faker);
            if (is_array($attributes)) {
                $this->defaultAttributes = array_merge($this->defaultAttributes, $attributes);
            }
        }

        return $this;
    }

    /**
     * Define a sequence for generating unique values.
     *
     * @param string $key Attribute key.
     * @param Closure|array $sequence Sequence closure or array.
     * @return static
     */
    public function sequence(string $key, Closure|array $sequence): static
    {
        $this->sequences[$key] = $sequence;
        return $this;
    }

    /**
     * Register an after making callback.
     *
     * @param Closure $callback
     * @return static
     */
    public function afterMaking(Closure $callback): static
    {
        $this->afterMaking[] = $callback;
        return $this;
    }

    /**
     * Register an after creating callback.
     *
     * @param Closure $callback
     * @return static
     */
    public function afterCreating(Closure $callback): static
    {
        $this->afterCreating[] = $callback;
        return $this;
    }

    /**
     * Define a relationship to create.
     *
     * Usage:
     * ```php
     * UserFactory::new()
     *     ->has(PostFactory::new()->count(3), 'posts')
     *     ->create();
     * ```
     *
     * @param FactoryInterface $factory Related factory
     * @param string $relationship Relationship name
     * @param int $count Number of related models
     * @param array $attributes Additional attributes
     * @return static
     */
    public function has(FactoryInterface $factory, string $relationship, int $count = 1, array $attributes = []): static
    {
        $this->has[$relationship] = [$factory, $count, $attributes];
        return $this;
    }

    /**
     * Define a parent relationship (belongs-to).
     *
     * Sets up the foreign key for a belongs-to relationship.
     * Automatically creates the parent model if a factory is provided,
     * or uses an existing model instance.
     *
     * Usage:
     * ```php
     * PostFactory::new()
     *     ->for(UserFactory::new(), 'user')
     *     ->create();
     * // Or with existing model:
     * PostFactory::new()
     *     ->for($existingUser, 'user')
     *     ->create();
     * ```
     *
     * Laravel-compatible syntax with automatic foreign key detection:
     * ```php
     * PostFactory::new()
     *     ->for(UserFactory::new()) // Assumes 'user' relationship
     *     ->create();
     * ```
     *
     * @param FactoryInterface|Model $factory Parent factory or model instance
     * @param string|null $relationship Relationship name (defaults to parent model name)
     * @return static
     */
    public function for(FactoryInterface|Model $factory, ?string $relationship = null): static
    {
        // Auto-detect relationship name from factory if not provided
        if ($relationship === null && $factory instanceof FactoryInterface) {
            // Extract model name from factory's model property
            $modelClass = $factory instanceof Factory ? $this->getModelFromFactory($factory) : null;
            if ($modelClass) {
                $relationship = strtolower(class_basename($modelClass));
            }
        }

        if ($relationship === null) {
            throw new \InvalidArgumentException('Relationship name is required when providing a model instance.');
        }

        $this->for[$relationship] = [$factory, $relationship];
        return $this;
    }

    /**
     * Get model class from a factory instance.
     *
     * @param Factory $factory
     * @return string|null
     */
    protected function getModelFromFactory(Factory $factory): ?string
    {
        // Use reflection to access protected $model property
        try {
            $reflection = new \ReflectionClass($factory);
            $property = $reflection->getProperty('model');
            $property->setAccessible(true);
            return $property->getValue($factory);
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * Define a many-to-many relationship with pivot data.
     *
     * Attaches related models via pivot table with optional pivot attributes.
     * Supports both factory instances and existing model arrays.
     *
     * Usage:
     * ```php
     * // Attach 3 roles via pivot table
     * UserFactory::new()
     *     ->hasAttached(RoleFactory::new(), 3, 'roles', ['assigned_at' => now()])
     *     ->create();
     *
     * // Attach existing models
     * $roles = [Role::find(1), Role::find(2)];
     * UserFactory::new()
     *     ->hasAttached($roles, count($roles), 'roles')
     *     ->create();
     * ```
     *
     * Laravel-compatible syntax:
     * ```php
     * UserFactory::new()
     *     ->hasAttached(RoleFactory::new()->count(3), 'roles')
     *     ->create();
     * ```
     *
     * @param FactoryInterface|array $factoryOrModels Related factory or array of models
     * @param int $count Number of models to attach (ignored if array provided)
     * @param string $relationship Relationship name
     * @param array $pivotAttributes Pivot table attributes
     * @return static
     */
    public function hasAttached(
        FactoryInterface|array $factoryOrModels,
        int|string $countOrRelationship = 1,
        ?string $relationship = null,
        array $pivotAttributes = []
    ): static {
        // Handle flexible parameter order (Laravel-compatible)
        if (is_string($countOrRelationship)) {
            // hasAttached($factory, 'roles') - relationship name as second param
            $relationship = $countOrRelationship;
            $count = 1;
        } else {
            // hasAttached($factory, 3, 'roles') - count as second param
            $count = $countOrRelationship;
        }

        if ($relationship === null) {
            throw new \InvalidArgumentException('Relationship name is required for hasAttached().');
        }

        $this->hasAttached[$relationship] = [$factoryOrModels, $count, $pivotAttributes];
        return $this;
    }

    /**
     * Recycle existing models to reduce database queries.
     *
     * Usage:
     * ```php
     * $users = UserFactory::new()->count(10)->create();
     * ProductFactory::new()
     *     ->recycle($users)
     *     ->create();
     * ```
     *
     * @param array<int, \Toporia\Framework\Database\ORM\Model> $models
     * @return static
     */
    public function recycle(array $models): static
    {
        $this->recycle = $models;
        return $this;
    }

    /**
     * Configure factory behavior.
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return static
     */
    public function configure(string $key, mixed $value): static
    {
        $this->config[$key] = $value;
        return $this;
    }

    /**
     * Create a model instance (not persisted).
     *
     * Performance: O(N) where N = number of attributes
     *
     * @param array $attributes Override attributes.
     * @return \Toporia\Framework\Database\ORM\Model Model instance.
     */
    public function make(array $attributes = []): Model
    {
        if (empty($this->model)) {
            throw new \RuntimeException('Factory model property must be set.');
        }

        $attributes = $this->getAttributes($attributes);
        $model = $this->createModel($attributes);

        if (!($model instanceof Model)) {
            throw new \RuntimeException('Factory createModel must return a Model instance.');
        }

        // Apply after making callbacks
        foreach ($this->afterMaking as $callback) {
            $callback($model);
        }

        return $model;
    }

    /**
     * Create and persist a model instance.
     *
     * Performance: O(N) where N = number of attributes + database insert
     *
     * @param array $attributes Override attributes.
     * @return \Toporia\Framework\Database\ORM\Model Model instance.
     */
    public function create(array $attributes = []): Model
    {
        $model = $this->make($attributes);
        $model->save();

        // Create relationships
        $this->createRelationships($model);

        // Apply after creating callbacks
        foreach ($this->afterCreating as $callback) {
            $callback($model);
        }

        return $model;
    }

    /**
     * Create multiple model instances (not persisted).
     *
     * Performance: O(N*M) where N = count, M = attributes per model
     *
     * @param int $count Number of models to create.
     * @param array $attributes Override attributes.
     * @return array<int, \Toporia\Framework\Database\ORM\Model> Array of model instances.
     */
    public function makeMany(int $count, array $attributes = []): array
    {
        $models = [];
        $originalCount = $this->count;
        $this->count = $count;

        for ($i = 0; $i < $count; $i++) {
            $this->sequenceIndex = $i;
            $models[] = $this->make($attributes);
        }

        $this->count = $originalCount;
        $this->sequenceIndex = 0;

        return $models;
    }

    /**
     * Create and persist multiple model instances.
     *
     * Optimized with batch insertion when possible.
     *
     * Performance: O(N*M) where N = count, M = attributes per model
     *
     * @param int $count Number of models to create.
     * @param array $attributes Override attributes.
     * @return array<int, \Toporia\Framework\Database\ORM\Model> Array of model instances.
     */
    public function createMany(int $count, array $attributes = []): array
    {
        $models = [];
        $originalCount = $this->count;
        $this->count = $count;

        // Try batch insert if configured and no relationships
        if (!empty($this->config['batch_insert']) && empty($this->has) && empty($this->recycle)) {
            $models = $this->createManyBatch($count, $attributes);
        } else {
            // Create models one by one (needed for relationships)
            for ($i = 0; $i < $count; $i++) {
                $this->sequenceIndex = $i;
                $model = $this->make($attributes);
                $model->save();
                $this->createRelationships($model);
                $models[] = $model;
            }
        }

        $this->count = $originalCount;
        $this->sequenceIndex = 0;

        // Apply after creating callbacks
        foreach ($this->afterCreating as $callback) {
            foreach ($models as $model) {
                $callback($model);
            }
        }

        return $models;
    }

    /**
     * Create multiple models using batch insert (optimized).
     *
     * @param int $count
     * @param array $attributes
     * @return array<int, \Toporia\Framework\Database\ORM\Model>
     */
    protected function createManyBatch(int $count, array $attributes = []): array
    {
        $data = [];
        $originalCount = $this->count;
        $this->count = $count;

        // Generate all data first
        for ($i = 0; $i < $count; $i++) {
            $this->sequenceIndex = $i;
            $data[] = $this->getAttributes($attributes);
        }

        // Batch insert
        $modelClass = $this->model;
        $chunkSize = $this->config['batch_size'] ?? 500;
        $insertedIds = [];

        foreach (array_chunk($data, $chunkSize) as $chunk) {
            $modelClass::query()->insert($chunk);
            // Get inserted IDs (approximate)
            $lastId = (int) ($modelClass::query()->max('id') ?? 0);
            $chunkCount = count($chunk);
            for ($j = 0; $j < $chunkCount; $j++) {
                $insertedIds[] = $lastId - $chunkCount + $j + 1;
            }
        }

        // Fetch created models
        $models = [];
        foreach ($insertedIds as $id) {
            $model = $modelClass::find($id);
            if ($model) {
                $models[] = $model;
            }
        }

        $this->count = $originalCount;
        $this->sequenceIndex = 0;

        return $models;
    }

    /**
     * Create relationships for a model.
     *
     * @param \Toporia\Framework\Database\ORM\Model $model
     * @return void
     */
    protected function createRelationships(Model $model): void
    {
        // Handle has() relationships (one-to-many)
        foreach ($this->has as $relationship => [$factory, $count, $attributes]) {
            $relatedModels = $factory->createMany($count, $attributes);

            // Associate relationships
            if (method_exists($model, $relationship)) {
                $relation = $model->$relationship();
                foreach ($relatedModels as $relatedModel) {
                    if (method_exists($relation, 'save')) {
                        $relation->save($relatedModel);
                    } elseif (method_exists($relation, 'attach')) {
                        $relation->attach($relatedModel->id);
                    }
                }
            }
        }

        // Handle hasAttached() relationships (many-to-many with pivot)
        foreach ($this->hasAttached as $relationship => [$factoryOrModels, $count, $pivotAttributes]) {
            $relatedModels = [];

            // Get or create related models
            if (is_array($factoryOrModels)) {
                // Use existing models
                $relatedModels = $factoryOrModels;
            } elseif ($factoryOrModels instanceof FactoryInterface) {
                // Create models from factory
                $relatedModels = $factoryOrModels->createMany($count);
            }

            // Attach to pivot table
            if (method_exists($model, $relationship)) {
                $relation = $model->$relationship();

                if (method_exists($relation, 'attach')) {
                    foreach ($relatedModels as $relatedModel) {
                        if ($relatedModel instanceof Model) {
                            $relation->attach($relatedModel->id, $pivotAttributes);
                        }
                    }
                }
            }
        }
    }

    /**
     * Get attributes with all modifiers applied.
     * Evaluates lazy attributes (Closures).
     *
     * @param array $override Override attributes.
     * @return array Final attributes.
     */
    protected function getAttributes(array $override = []): array
    {
        $definition = $this->definition();

        // Evaluate lazy attributes
        $definition = $this->evaluateLazyAttributes($definition);

        $attributes = array_merge($definition, $this->defaultAttributes, $override);

        // Apply sequences
        foreach ($this->sequences as $key => $sequence) {
            if (is_callable($sequence)) {
                $attributes[$key] = $sequence($this->sequenceIndex, $this->faker);
            } elseif (is_array($sequence)) {
                $index = $this->sequenceIndex % count($sequence);
                $attributes[$key] = $sequence[$index];
            }
        }

        // Apply for() relationships (belongs-to)
        foreach ($this->for as $relationship => [$factoryOrModel, $relationName]) {
            // Determine foreign key name (e.g., user_id for 'user')
            $foreignKey = $relationship . '_id';

            // Create or use parent model
            if ($factoryOrModel instanceof FactoryInterface) {
                $parent = $factoryOrModel->create();
                $attributes[$foreignKey] = $parent->id;
            } elseif ($factoryOrModel instanceof Model) {
                $attributes[$foreignKey] = $factoryOrModel->id;
            }
        }

        // Apply recycle (reuse existing models)
        if (!empty($this->recycle)) {
            $recycled = $this->recycle[$this->sequenceIndex % count($this->recycle)] ?? null;
            if ($recycled) {
                // Use recycled model's attributes
                foreach ($recycled->getAttributes() as $key => $value) {
                    if (!isset($attributes[$key])) {
                        $attributes[$key] = $value;
                    }
                }
            }
        }

        return $attributes;
    }

    /**
     * Evaluate lazy attributes (Closures).
     *
     * @param array $attributes
     * @return array
     */
    protected function evaluateLazyAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value instanceof Closure) {
                $attributes[$key] = $value($this->faker, $attributes);
            } elseif (is_array($value)) {
                $attributes[$key] = $this->evaluateLazyAttributes($value);
            }
        }
        return $attributes;
    }

    /**
     * Create the model instance.
     *
     * Performance: O(N) where N = number of attributes
     *
     * @param array $attributes Model attributes.
     * @return \Toporia\Framework\Database\ORM\Model Model instance.
     */
    protected function createModel(array $attributes): Model
    {
        if (empty($this->model)) {
            throw new \RuntimeException('Factory model property must be set.');
        }

        // Model constructor expects array
        return new $this->model($attributes);
    }

    /**
     * Get Faker instance.
     *
     * @return Generator
     */
    public function faker(): Generator
    {
        return $this->faker;
    }

    /**
     * Magic method for fluent relationship methods.
     *
     * Supports Laravel-style dynamic relationship methods:
     * - `hasPosts(3)` → `has(PostFactory::new(), 'posts', 3)`
     * - `forUser()` → `for(UserFactory::new(), 'user')`
     * - `hasAttachedRoles(3, ['admin' => true])` → `hasAttached(RoleFactory::new(), 3, 'roles', ['admin' => true])`
     *
     * Pattern matching:
     * - `has{Relationship}($count = 1, $attributes = [])` → has() relationship
     * - `for{Relationship}()` → for() relationship
     * - `hasAttached{Relationship}($count = 1, $pivotAttributes = [])` → hasAttached() relationship
     *
     * Usage:
     * ```php
     * UserFactory::new()
     *     ->hasPosts(5)
     *     ->hasComments(10, ['approved' => true])
     *     ->create();
     *
     * PostFactory::new()
     *     ->forUser()
     *     ->hasAttachedTags(3, ['created_at' => now()])
     *     ->create();
     * ```
     *
     * @param string $method Method name
     * @param array $parameters Method parameters
     * @return static
     * @throws \BadMethodCallException If method pattern is not recognized
     */
    public function __call(string $method, array $parameters): static
    {
        // Match hasAttached{Relationship}() pattern
        if (preg_match('/^hasAttached([A-Z].*)$/', $method, $matches)) {
            $relationship = $this->pluralizeRelationship($matches[1]);
            $factoryClass = $this->resolveFactoryClass($matches[1]);

            $count = $parameters[0] ?? 1;
            $pivotAttributes = $parameters[1] ?? [];

            return $this->hasAttached(
                $factoryClass::new(),
                $count,
                $relationship,
                $pivotAttributes
            );
        }

        // Match has{Relationship}() pattern
        if (preg_match('/^has([A-Z].*)$/', $method, $matches)) {
            $relationship = $this->pluralizeRelationship($matches[1]);
            $factoryClass = $this->resolveFactoryClass($matches[1]);

            $count = $parameters[0] ?? 1;
            $attributes = $parameters[1] ?? [];

            return $this->has($factoryClass::new(), $relationship, $count, $attributes);
        }

        // Match for{Relationship}() pattern
        if (preg_match('/^for([A-Z].*)$/', $method, $matches)) {
            $relationship = $this->singularizeRelationship($matches[1]);
            $factoryClass = $this->resolveFactoryClass($matches[1]);

            return $this->for($factoryClass::new(), $relationship);
        }

        throw new \BadMethodCallException(
            "Call to undefined method " . static::class . "::{$method}(). " .
            "Magic methods support: has{Relationship}(), for{Relationship}(), hasAttached{Relationship}()."
        );
    }

    /**
     * Resolve factory class from relationship name.
     *
     * Converts relationship name to factory class name.
     * Examples:
     * - Posts → PostFactory
     * - Comments → CommentFactory
     * - Users → UserFactory
     *
     * @param string $relationship Relationship name (PascalCase)
     * @return string Factory class name
     */
    protected function resolveFactoryClass(string $relationship): string
    {
        // Remove trailing 's' for plural relationships
        $singular = rtrim($relationship, 's');

        // Try common factory locations
        $possibleFactories = [
            "Database\\Factories\\{$singular}Factory",
            "App\\Database\\Factories\\{$singular}Factory",
            "{$singular}Factory",
        ];

        foreach ($possibleFactories as $factoryClass) {
            if (class_exists($factoryClass)) {
                return $factoryClass;
            }
        }

        // Fallback to first option (will fail gracefully if not found)
        return $possibleFactories[0];
    }

    /**
     * Pluralize relationship name for has() methods.
     *
     * @param string $relationship Relationship name
     * @return string Pluralized relationship name (lowercase)
     */
    protected function pluralizeRelationship(string $relationship): string
    {
        // Simple pluralization (can be enhanced with proper library)
        $lowercase = strtolower($relationship);

        // Already plural
        if (substr($lowercase, -1) === 's') {
            return $lowercase;
        }

        // Simple pluralization rules
        if (substr($lowercase, -1) === 'y') {
            return substr($lowercase, 0, -1) . 'ies'; // category → categories
        }

        return $lowercase . 's'; // post → posts
    }

    /**
     * Singularize relationship name for for() methods.
     *
     * @param string $relationship Relationship name
     * @return string Singularized relationship name (lowercase)
     */
    protected function singularizeRelationship(string $relationship): string
    {
        $lowercase = strtolower($relationship);

        // Simple singularization (can be enhanced)
        if (substr($lowercase, -3) === 'ies') {
            return substr($lowercase, 0, -3) . 'y'; // categories → category
        }

        if (substr($lowercase, -1) === 's') {
            return substr($lowercase, 0, -1); // posts → post
        }

        return $lowercase;
    }

    /**
     * Reset factory state.
     *
     * @return static
     */
    public function reset(): static
    {
        $this->defaultAttributes = [];
        $this->sequenceIndex = 0;
        $this->count = 1;
        $this->has = [];
        $this->for = [];
        $this->hasAttached = [];
        $this->recycle = [];
        $this->config = [];
        return $this;
    }
}
