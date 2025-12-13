<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Relations;

use Toporia\Framework\Database\ORM\{Model, ModelCollection};
use Toporia\Framework\Database\Query\QueryBuilder;

/**
 * HasOne Relationship
 *
 * Handles one-to-one relationships.
 * Optimized for performance and follows Clean Architecture principles.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
 * @package     toporia/framework
 * @subpackage  Relations
 * @since       2025-01-10
 */
class HasOne extends Relation
{
    /** @var array|bool|null Default model attributes or boolean/null for withDefault() */
    protected array|bool|null $withDefault = null;

    /** @var bool Whether this is a one-of-many relationship */
    protected bool $isOneOfMany = false;

    /** @var string|null Column used for ofMany comparison */
    protected ?string $oneOfManyColumn = null;

    /** @var string ofMany aggregate function (MAX or MIN) */
    protected string $oneOfManyAggregate = 'MAX';

    /**
     * @param QueryBuilder $query Query builder for related model
     * @param Model $parent Parent model instance
     * @param class-string<Model> $relatedClass Related model class name
     * @param string $foreignKey Foreign key column name
     * @param string $localKey Local key column name
     */
    public function __construct(
        QueryBuilder $query,
        Model $parent,
        protected string $relatedClass,
        string $foreignKey,
        string $localKey
    ) {
        parent::__construct($query, $parent, $foreignKey, $localKey);
        $this->addConstraints();
    }

    /**
     * {@inheritdoc}
     */
    public function getRelatedClass(): string
    {
        return $this->relatedClass;
    }

    /**
     * Add basic WHERE constraint based on parent model.
     *
     * OPTIMIZED: Automatically applies soft delete scope if related model uses soft deletes.
     *
     * @return $this
     */
    public function addConstraints(): static
    {
        if ($this->parent->exists()) {
            $this->query->where(
                $this->foreignKey,
                $this->parent->getAttribute($this->localKey)
            );
        }

        // Apply soft delete scope if related model uses soft deletes
        $this->applySoftDeleteScope($this->query, $this->relatedClass);

        return $this;
    }

    // =========================================================================
    // CORE RELATION METHODS
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getResults(): ?Model
    {
        if (!$this->hasValidParentKey()) {
            return $this->getDefaultFor($this->parent);
        }

        // Ensure table is set before executing query
        $table = $this->query->getTable();
        if ($table === null) {
            // Get table from related model if not set
            $table = $this->relatedClass::getTableName();
            if ($table !== null) {
                $this->query->table($table);
            }
        }

        $result = $this->query->first();

        return $result ?? $this->getDefaultFor($this->parent);
    }

    /**
     * Get the default value for the relationship.
     *
     * @param Model $parent Parent model
     * @return Model|null Default model or null
     */
    protected function getDefaultFor(Model $parent): ?Model
    {
        if ($this->withDefault === null || $this->withDefault === false) {
            return null;
        }

        $instance = new $this->relatedClass();

        // Set the foreign key to link to parent
        $instance->setAttribute($this->foreignKey, $parent->getAttribute($this->localKey));

        if (is_array($this->withDefault)) {
            foreach ($this->withDefault as $key => $value) {
                // Support callable values
                $instance->setAttribute($key, is_callable($value) ? $value($parent) : $value);
            }
        } elseif (is_callable($this->withDefault)) {
            ($this->withDefault)($instance, $parent);
        }

        return $instance;
    }

    /**
     * Return a default model if the relationship returns null.
     *
     * This is useful when you want to avoid null checks in your code.
     * The default model will be a new (unsaved) instance of the related model.
     *
     * @param array|bool|callable $callback Default attributes, true for empty model, or callback
     * @return $this
     *
     * @example
     * // Return empty model
     * public function profile(): HasOne
     * {
     *     return $this->hasOne(Profile::class)->withDefault();
     * }
     *
     * // With default attributes
     * public function profile(): HasOne
     * {
     *     return $this->hasOne(Profile::class)->withDefault([
     *         'bio' => 'No bio yet'
     *     ]);
     * }
     *
     * // With callback
     * public function profile(): HasOne
     * {
     *     return $this->hasOne(Profile::class)->withDefault(function ($profile, $user) {
     *         $profile->bio = 'New profile for ' . $user->name;
     *     });
     * }
     */
    public function withDefault(array|bool|callable $callback = true): static
    {
        $this->withDefault = is_bool($callback) ? $callback : $callback;

        return $this;
    }

    // =========================================================================
    // OF MANY PATTERN (HasOneOfMany)
    // =========================================================================

    /**
     * Indicate that the relationship is one of many.
     *
     * This pattern allows defining a HasOne relationship that selects a single
     * record from what would normally be a HasMany relationship, based on
     * aggregate functions (MAX, MIN).
     *
     * @param string|array $column Column(s) for aggregate, or ['column' => 'aggregate']
     * @param string|null $aggregate Aggregate function ('max', 'min')
     * @return $this
     *
     * @example
     * // Get the latest order for each user (by id)
     * public function latestOrder(): HasOne
     * {
     *     return $this->hasOne(Order::class)->ofMany();
     * }
     *
     * // Get the oldest order (by created_at)
     * public function oldestOrder(): HasOne
     * {
     *     return $this->hasOne(Order::class)->ofMany('created_at', 'min');
     * }
     *
     * // Get the highest priced order
     * public function mostExpensiveOrder(): HasOne
     * {
     *     return $this->hasOne(Order::class)->ofMany('total_amount', 'max');
     * }
     *
     * // Multiple columns (uses subquery)
     * public function latestCompletedOrder(): HasOne
     * {
     *     return $this->hasOne(Order::class)->ofMany([
     *         'completed_at' => 'max',
     *         'id' => 'max'
     *     ]);
     * }
     */
    public function ofMany(string|array $column = 'id', string|null $aggregate = 'max'): static
    {
        $this->isOneOfMany = true;

        if (is_array($column)) {
            // Multiple columns: use first column's aggregate
            $firstKey = array_key_first($column);
            $this->oneOfManyColumn = $firstKey;
            $this->oneOfManyAggregate = strtoupper($column[$firstKey]);
        } else {
            $this->oneOfManyColumn = $column;
            $this->oneOfManyAggregate = strtoupper($aggregate ?? 'MAX');
        }

        // Add the ofMany constraint to the query
        $this->addOneOfManyConstraint();

        return $this;
    }

    /**
     * Get the latest related model (based on primary key).
     *
     * Shorthand for ofMany('id', 'max').
     *
     * @param string|null $column Column to use for latest (default: primary key)
     * @return $this
     *
     * @example
     * public function latestOrder(): HasOne
     * {
     *     return $this->hasOne(Order::class)->latestOfMany();
     * }
     *
     * // Or by a specific column
     * public function latestOrder(): HasOne
     * {
     *     return $this->hasOne(Order::class)->latestOfMany('created_at');
     * }
     */
    public function latestOfMany(?string $column = null): static
    {
        $column = $column ?? $this->relatedClass::getPrimaryKey();
        return $this->ofMany($column, 'max');
    }

    /**
     * Get the oldest related model (based on primary key).
     *
     * Shorthand for ofMany('id', 'min').
     *
     * @param string|null $column Column to use for oldest (default: primary key)
     * @return $this
     *
     * @example
     * public function firstOrder(): HasOne
     * {
     *     return $this->hasOne(Order::class)->oldestOfMany();
     * }
     *
     * // Or by a specific column
     * public function firstOrder(): HasOne
     * {
     *     return $this->hasOne(Order::class)->oldestOfMany('created_at');
     * }
     */
    public function oldestOfMany(?string $column = null): static
    {
        $column = $column ?? $this->relatedClass::getPrimaryKey();
        return $this->ofMany($column, 'min');
    }

    /**
     * Add the one-of-many subquery constraint.
     *
     * Uses a correlated subquery approach:
     * SELECT * FROM orders WHERE user_id = ? AND id = (SELECT MAX(id) FROM orders WHERE user_id = ?)
     */
    protected function addOneOfManyConstraint(): void
    {
        if (!$this->isOneOfMany || $this->oneOfManyColumn === null) {
            return;
        }

        $relatedTable = $this->relatedClass::getTableName();
        $grammar = $this->query->getConnection()->getGrammar();
        $wrappedForeignKey = $grammar->wrapColumn($this->foreignKey);
        $wrappedColumn = $grammar->wrapColumn($this->oneOfManyColumn);
        $wrappedTable = $grammar->wrapTable($relatedTable);
        $wrappedLocalKey = $grammar->wrapColumn($this->localKey);

        // Build subquery: (SELECT MAX/MIN(column) FROM table WHERE foreign_key = parent.local_key)
        $parentTable = $this->parent::getTableName();
        $wrappedParentTable = $grammar->wrapTable($parentTable);

        $subquery = "(SELECT {$this->oneOfManyAggregate}({$wrappedColumn}) FROM {$wrappedTable} WHERE {$wrappedForeignKey} = {$wrappedParentTable}.{$wrappedLocalKey})";

        // Add constraint: column = (SELECT MAX/MIN(column) ...)
        $this->query->whereRaw("{$wrappedTable}.{$wrappedColumn} = {$subquery}");
    }

    /**
     * Check if this is a one-of-many relationship.
     */
    public function isOneOfMany(): bool
    {
        return $this->isOneOfMany;
    }

    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     *
     * PERFORMANCE: Optimized dictionary building and null handling for single model relations.
     * Single relations (HasOne) return null when empty, not empty array.
     */
    public function match(array $models, mixed $results, string $relationName): array
    {
        // Early return: set null for all models if no results (single relation behavior)
        if (!$results instanceof ModelCollection || $results->isEmpty()) {
            foreach ($models as $model) {
                $model->setRelation($relationName, null);
            }
            return $models;
        }

        // OPTIMIZATION: Build dictionary once and reuse
        $dictionary = $this->buildDictionary($results);

        // OPTIMIZATION: Batch set relations with efficient lookup
        foreach ($models as $model) {
            $localValue = $model->getAttribute($this->localKey);
            // Use isset() for O(1) lookup instead of ?? operator with array access
            $relatedModel = isset($dictionary[$localValue]) ? $dictionary[$localValue] : null;
            $model->setRelation($relationName, $relatedModel);
        }

        return $models;
    }

    /**
     * {@inheritdoc}
     */
    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * {@inheritdoc}
     */
    public function newEagerInstance(QueryBuilder $freshQuery): static
    {
        $instance = new static(
            $freshQuery,
            $this->parent,
            $this->relatedClass,
            $this->foreignKey,
            $this->localKey
        );

        // Use freshQuery directly instead of creating another new query
        // freshQuery already has the table set from loadRelationBatch
        $instance->setQuery($freshQuery);

        // Copy where constraints from original query (excluding parent-specific foreign key constraint)
        $this->copyWhereConstraints($freshQuery, [$this->foreignKey]);

        return $instance;
    }

    // =========================================================================
    // CRUD OPERATIONS
    // =========================================================================

    /**
     * Create a new related model.
     */
    public function create(array $attributes = []): Model
    {
        $attributes[$this->foreignKey] = $this->getParentKeyOrFail();

        return $this->relatedClass::create($attributes);
    }

    /**
     * Save a related model.
     */
    public function save(Model $model): Model
    {
        $model->setAttribute($this->foreignKey, $this->getParentKeyOrFail());
        $model->save();

        return $model;
    }

    /**
     * Update the related model.
     */
    public function update(array $attributes): int
    {
        return $this->parent->exists() ? $this->query->update($attributes) : 0;
    }

    /**
     * Delete the related model.
     */
    public function delete(): int
    {
        return $this->parent->exists() ? $this->query->delete() : 0;
    }

    /**
     * Soft delete the related model through this relationship.
     *
     * If related model uses soft deletes, this will soft delete the related model.
     * Otherwise, it will perform a hard delete.
     *
     * Performance: O(1) - Single UPDATE query for soft delete
     *
     * @return bool True if model was soft deleted
     *
     * @example
     * ```php
     * // Soft delete the profile for a user
     * $user->profile()->softDelete();
     * ```
     */
    public function softDelete(): bool
    {
        if (!$this->parent->exists()) {
            return false;
        }

        $related = $this->getResults();
        if (!$related instanceof Model) {
            return false;
        }

        // If related model uses soft deletes, call delete() which will soft delete
        if ($this->relatedModelUsesSoftDeletes($this->relatedClass)) {
            return $related->delete();
        }

        // Otherwise, perform hard delete
        if (method_exists($related, 'forceDelete')) {
            /** @var Model&\Toporia\Framework\Database\ORM\Concerns\SoftDeletes $related */
            return $related->forceDelete();
        }

        return $related->delete();
    }

    /**
     * Restore soft-deleted related model through this relationship.
     *
     * Restores soft-deleted related model.
     *
     * Performance: O(1) - Single UPDATE query for restore
     *
     * @return bool True if model was restored
     *
     * @example
     * ```php
     * // Restore the soft-deleted profile for a user
     * $user->profile()->restore();
     * ```
     */
    public function restore(): bool
    {
        if (!$this->parent->exists()) {
            return false;
        }

        // If related model doesn't use soft deletes, return false
        if (!$this->relatedModelUsesSoftDeletes($this->relatedClass)) {
            return false;
        }

        // Get soft-deleted related model
        $deletedAtColumn = $this->getDeletedAtColumn($this->relatedClass);
        $related = $this->relatedClass::withTrashed()
            ->where($this->foreignKey, $this->parent->getAttribute($this->localKey))
            ->whereNotNull($deletedAtColumn)
            ->first();

        if (!$related instanceof Model || !method_exists($related, 'restore')) {
            return false;
        }

        /** @var Model&\Toporia\Framework\Database\ORM\Concerns\SoftDeletes $related */
        return $related->restore();
    }

    /**
     * Get the first related model or create a new one.
     */
    public function firstOrCreate(array $attributes = []): Model
    {
        return $this->getResults() ?? $this->create($attributes);
    }

    /**
     * Get the first related model or instantiate a new one (without saving).
     */
    public function firstOrNew(array $attributes = []): Model
    {
        $instance = $this->getResults();

        if ($instance !== null) {
            return $instance;
        }

        $attributes[$this->foreignKey] = $this->parent->getAttribute($this->localKey);
        return new $this->relatedClass($attributes);
    }

    /**
     * Update or create a related model.
     */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $instance = $this->getResults();

        if ($instance !== null) {
            $instance->fill([...$attributes, ...$values]);
            $instance->save();
            return $instance;
        }

        return $this->create([...$attributes, ...$values]);
    }

    // =========================================================================
    // QUERY METHODS
    // =========================================================================

    /**
     * Check if the related model exists.
     */
    public function exists(): bool
    {
        return $this->parent->exists() && $this->query->exists();
    }

    /**
     * Get the count of related models (always 0 or 1 for HasOne).
     */
    public function count(): int
    {
        return $this->parent->exists() ? $this->query->count() : 0;
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Check if parent exists and has a valid local key.
     */
    protected function hasValidParentKey(): bool
    {
        return $this->parent->exists()
            && $this->parent->getAttribute($this->localKey) !== null;
    }

    /**
     * Get parent key or throw exception.
     *
     * @throws \RuntimeException If parent key is null
     */
    protected function getParentKeyOrFail(): mixed
    {
        $parentKey = $this->parent->getAttribute($this->localKey);

        if ($parentKey === null) {
            throw new \RuntimeException('Cannot perform operation: parent model does not have a key');
        }

        return $parentKey;
    }

    /**
     * Build dictionary for eager loading matching.
     *
     * @return array<int|string, Model>
     */
    protected function buildDictionary(ModelCollection $results): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);
            if ($key !== null) {
                $dictionary[$key] = $result;
            }
        }
        return $dictionary;
    }

    // =========================================================================
    // MAGIC METHODS
    // =========================================================================

    /**
     * Magic method to delegate calls to the underlying query builder.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (method_exists($this->query, $method)) {
            $result = $this->query->{$method}(...$parameters);

            if ($result instanceof QueryBuilder) {
                return $this;
            }

            return $result;
        }

        throw new \BadMethodCallException(
            sprintf('Method %s::%s does not exist.', static::class, $method)
        );
    }
}
