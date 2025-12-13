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
        $this->recycle = [];
        $this->config = [];
        return $this;
    }
}
