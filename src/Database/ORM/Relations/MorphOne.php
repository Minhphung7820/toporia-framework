<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Relations;

use Toporia\Framework\Database\ORM\{Model, ModelCollection};
use Toporia\Framework\Database\Query\QueryBuilder;

/**
 * MorphOne Relationship
 *
 * Handles polymorphic one-to-one relationships.
 * Example: Post/Video morphOne Image
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
 * @package     toporia/framework
 * @subpackage  Relations
 * @since       2025-01-10
 */
class MorphOne extends Relation
{
    /** @var string Morph type column name */
    protected string $morphType;

    /**
     * @param QueryBuilder $query Query builder for related model
     * @param Model $parent Parent model instance (Post or Video)
     * @param class-string<Model> $relatedClass Related model class (Image)
     * @param string $morphName Morph name ('imageable')
     * @param string|null $morphType Type column (imageable_type)
     * @param string|null $morphId ID column (imageable_id)
     * @param string|null $localKey Local key on parent (id)
     */
    public function __construct(
        QueryBuilder $query,
        Model $parent,
        protected string $relatedClass,
        protected string $morphName,
        ?string $morphType = null,
        ?string $morphId = null,
        ?string $localKey = null,
        bool $skipConstraints = false
    ) {
        $this->morphType = $morphType ?? "{$morphName}_type";
        $this->foreignKey = $morphId ?? "{$morphName}_id";
        $this->localKey = $localKey ?? $parent::getPrimaryKey();

        parent::__construct($query, $parent, $this->foreignKey, $this->localKey);

        if (!$skipConstraints) {
            $this->addConstraints();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getRelatedClass(): string
    {
        return $this->relatedClass;
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

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
     * @return array<string, Model>
     */
    protected function buildDictionary(ModelCollection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $type = $result->getAttribute($this->morphType);
            $id = $result->getAttribute($this->foreignKey);
            $key = "{$type}:{$id}";
            $dictionary[$key] = $result;
        }

        return $dictionary;
    }

    /**
     * Recursively copy WHERE clauses from one query to another.
     *
     * This ensures all WHERE clause types (including nested) are properly copied.
     *
     * @param QueryBuilder $targetQuery Target query builder to apply constraints to
     * @param array $wheres Array of WHERE clause definitions
     * @return void
     */
    protected function copyWhereClausesRecursive(QueryBuilder $targetQuery, array $wheres): void
    {
        foreach ($wheres as $where) {
            $type = strtolower($where['type'] ?? '');
            match ($type) {
                'basic' => $targetQuery->where(
                    $where['column'],
                    $where['operator'] ?? '=',
                    $where['value'] ?? null,
                    $where['boolean'] ?? 'AND'
                ),
                'null' => $targetQuery->whereNull($where['column'], $where['boolean'] ?? 'AND'),
                'notnull' => $targetQuery->whereNotNull($where['column'], $where['boolean'] ?? 'AND'),
                'in' => $targetQuery->whereIn($where['column'], $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                'notin' => $targetQuery->whereNotIn($where['column'], $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                'raw' => $targetQuery->whereRaw(
                    $where['sql'] ?? '',
                    $where['bindings'] ?? [],
                    $where['boolean'] ?? 'AND'
                ),
                'nested' => $this->copyNestedWhereClause($targetQuery, $where),
                default => null
            };
        }
    }

    /**
     * Copy a nested WHERE clause to the target query.
     *
     * Recursively handles nested WHERE clauses to ensure all types are properly copied.
     *
     * @param QueryBuilder $targetQuery Target query builder
     * @param array $where Nested WHERE clause definition
     * @return void
     */
    protected function copyNestedWhereClause(QueryBuilder $targetQuery, array $where): void
    {
        if (!isset($where['query']) || !method_exists($where['query'], 'getWheres')) {
            return;
        }

        $nestedWheres = $where['query']->getWheres();

        // Don't create empty nested WHERE clauses - this causes SQL syntax errors
        if (empty($nestedWheres)) {
            return;
        }

        $boolean = $where['boolean'] ?? 'AND';

        $targetQuery->where(function ($q) use ($nestedWheres) {
            $this->copyWhereClausesRecursive($q, $nestedWheres);
        }, $boolean);
    }

    // =========================================================================
    // CORE RELATION METHODS
    // =========================================================================

    /**
     * Add constraints for morph relationship.
     *
     * @return static
     */
    public function addConstraints(): static
    {
        if ($this->parent->exists()) {
            // WHERE imageable_type = 'Post'
            $this->query->where($this->morphType, $this->getMorphClass());

            // AND imageable_id = ?
            $this->query->where(
                $this->foreignKey,
                $this->parent->getAttribute($this->localKey)
            );
        }

        // Apply soft delete scope if related model uses soft deletes
        $this->applySoftDeleteScope($this->query, $this->relatedClass);

        return $this;
    }

    /**
     * Get morph class name/alias for parent.
     *
     * Resolution order (first match wins):
     * 1. Model's getMorphClass() method if defined
     * 2. Global morph map alias (if registered via Relation::morphMap())
     * 3. Full namespace class name (e.g., "App\Models\Product")
     *
     * Using morph map aliases is recommended for:
     * - Shorter database storage
     * - Decoupling class names from stored data
     * - Easier refactoring (rename class without data migration)
     *
     * @return string Morph alias or full namespace class name
     */
    protected function getMorphClass(): string
    {
        // 1. Allow parent model to override getMorphClass() method
        if (method_exists($this->parent, 'getMorphClass')) {
            return $this->parent->getMorphClass();
        }

        // 2. Check global morph map for alias
        $className = get_class($this->parent);
        $alias = Relation::getMorphAlias($className);

        // 3. Return alias if found, otherwise return full class name
        return $alias;
    }

    /**
     * {@inheritdoc}
     */
    public function getResults(): mixed
    {
        // Check if this is eager loading (has WHERE IN, nested WHERE, or simple WHERE with morph type)
        // During eager loading, parent is a dummy model that doesn't exist,
        // but we still need to execute the query with eager constraints
        $wheres = $this->query->getWheres();
        $hasEagerConstraints = false;
        foreach ($wheres as $where) {
            // Check for WHERE IN (from addEagerConstraints - multiple models same type)
            if (strtolower($where['type'] ?? '') === 'in') {
                $hasEagerConstraints = true;
                break;
            }
            // Check for nested WHERE with morph type pattern (from addEagerConstraints - multiple types)
            if (strtolower($where['type'] ?? '') === 'nested') {
                $hasEagerConstraints = true;
                break;
            }
            // Check for simple WHERE with morphType (from addEagerConstraints - single model optimization)
            // This is the new optimization case where we use simple WHERE instead of nested WHERE
            if (
                strtolower($where['type'] ?? '') === 'basic' &&
                ($where['column'] ?? '') === $this->morphType
            ) {
                $hasEagerConstraints = true;
                break;
            }
        }

        // For eager loading, skip parent->exists() check and execute query directly
        // For lazy loading, check parent->exists() first
        if (!$hasEagerConstraints && !$this->parent->exists()) {
            return null;
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

        // Only add single parent constraints if not eager loading
        // (eager loading already has constraints from addEagerConstraints)
        if (!$hasEagerConstraints) {
            $this->query->where($this->morphType, $this->getMorphClass());
            $this->query->where($this->foreignKey, $this->parent->getAttribute($this->localKey));
            // For lazy loading, limit to 1 result
            $this->query->limit(1);
            return $this->query->first();
        }

        // For eager loading, return ModelCollection with all results
        // match() will distribute them to the correct parent models
        // Note: For MorphOne, if callback has orderBy(), match() will take the first result per parent
        // We need to get all results (without limit) during eager loading,
        // then match() will select the first one per parent based on orderBy
        // Create a new query without limit/offset to avoid modifying the original query
        $connection = $this->query->getConnection();
        $eagerQuery = new QueryBuilder($connection);

        // Copy table
        $table = $this->query->getTable();
        if ($table !== null) {
            $eagerQuery->table($table);
        }

        // Copy selects - if empty, Grammar will default to SELECT *
        $selects = $this->query->getColumns();
        if (!empty($selects)) {
            $eagerQuery->select($selects);
        } else {
            // No custom select from constraint - use default
            $eagerQuery->select('*');
        }

        // Copy all where constraints (including eager constraints from addEagerConstraints)
        $wheres = $this->query->getWheres();
        $this->copyWhereClausesRecursive($eagerQuery, $wheres);

        // Copy orderBy (important for match() to select correct first result per parent)
        $orders = $this->query->getOrders();
        foreach ($orders as $order) {
            $eagerQuery->orderBy($order['column'] ?? '', $order['direction'] ?? 'ASC');
        }

        // Execute query without limit/offset to get all results
        $results = $eagerQuery->get();

        // Convert DatabaseCollection to ModelCollection if needed
        if (!$results instanceof ModelCollection) {
            /** @var callable $hydrate */
            $hydrate = [$this->relatedClass, 'hydrate'];
            $results = $hydrate($results->all());
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function addEagerConstraints(array $models): void
    {
        if (empty($models)) {
            return;
        }

        $types = [];
        foreach ($models as $model) {
            // Use getMorphClass() if available, otherwise use get_class()
            $type = method_exists($model, 'getMorphClass')
                ? $model->getMorphClass()
                : get_class($model);
            $types[$type][] = $model->getAttribute($this->localKey);
        }

        // OPTIMIZATION: If only one type with one ID, use simple WHERE (no nested clause)
        // This fixes performance issue where nested WHERE with whereIn() was not compiled correctly
        if (count($types) === 1) {
            $type = array_key_first($types);
            $ids = $types[$type];

            if (count($ids) === 1) {
                // Single model case - use simple WHERE (no nested clause)
                // This ensures: WHERE imageable_type = ? AND imageable_id = ?
                $this->query->where($this->morphType, $type)
                    ->where($this->foreignKey, $ids[0]);
            } else {
                // Multiple models of same type - use WHERE IN
                // This ensures: WHERE imageable_type = ? AND imageable_id IN (?, ?, ...)
                $this->query->where($this->morphType, $type)
                    ->whereIn($this->foreignKey, $ids);
            }
        } else {
            // Multiple types - use nested WHERE with OR
            // This ensures: WHERE ((imageable_type = ? AND imageable_id IN (?)) OR (imageable_type = ? AND imageable_id IN (?)))
            $this->query->where(function ($q) use ($types) {
                $first = true;
                foreach ($types as $type => $ids) {
                    if ($first) {
                        $q->where(function ($subQ) use ($type, $ids) {
                            $subQ->where($this->morphType, $type)
                                ->whereIn($this->foreignKey, $ids);
                        });
                        $first = false;
                    } else {
                        $q->orWhere(function ($subQ) use ($type, $ids) {
                            $subQ->where($this->morphType, $type)
                                ->whereIn($this->foreignKey, $ids);
                        });
                    }
                }
            });
        }

        // Apply soft delete scope if related model uses soft deletes
        $this->applySoftDeleteScope($this->query, $this->relatedClass);
    }

    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     *
     * PERFORMANCE: Optimized dictionary building and null handling for single model relations.
     * Single relations (MorphOne) return null when empty, not empty array.
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
            // Use getMorphClass() if available, otherwise use get_class()
            $type = method_exists($model, 'getMorphClass')
                ? $model->getMorphClass()
                : get_class($model);
            $id = $model->getAttribute($this->localKey);
            $key = "{$type}:{$id}";

            // Use isset() for O(1) lookup instead of ?? operator with array access
            $relatedModel = isset($dictionary[$key]) ? $dictionary[$key] : null;
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
        // Create a clean query with table and selects from freshQuery
        $table = $freshQuery->getTable();
        $connection = $freshQuery->getConnection();
        $cleanQuery = new QueryBuilder($connection);

        if ($table !== null) {
            $cleanQuery->table($table);
        }

        // CRITICAL: Copy selects and orderBy from freshQuery FIRST
        // This ensures that constraints from eager loading (like select/orderBy) are preserved
        // when both direct relation ('image') and nested relation ('image.imageable') are defined
        // The constraint for 'image' is applied to freshQuery before newEagerInstance is called,
        // so we need to copy select/orderBy from freshQuery to preserve them
        $selects = $freshQuery->getColumns();
        if (!empty($selects)) {
            $cleanQuery->select($selects);
        }

        // Copy orderBy from freshQuery if any (from constraint closure)
        $orders = $freshQuery->getOrders();
        if (!empty($orders)) {
            foreach ($orders as $order) {
                if (isset($order['column'])) {
                    $cleanQuery->orderBy($order['column'], $order['direction'] ?? 'ASC');
                }
            }
        }

        // CRITICAL: Copy all where constraints from original query (relationship method)
        // This ensures constraints like where('width', '>=', 800) are preserved during eager loading
        // Exclude morph type and foreign key constraints as they will be added by addEagerConstraints()
        // This allows relationship methods with constraints to work correctly with eager loading
        $this->copyWhereConstraints($cleanQuery, [
            $this->morphType,
            $this->foreignKey,
            fn($col) => $col === $this->morphType || $col === $this->foreignKey
        ]);

        // Create a dummy parent model that doesn't exist to prevent addConstraints()
        // from adding WHERE morphType = ? AND foreignKey = ? constraints. This ensures only
        // the constraints from addEagerConstraints() are used during eager loading.
        // Creating a new instance ensures exists() returns false and localKey is null
        $parentClass = get_class($this->parent);
        $dummyParent = new $parentClass();

        // Create instance with clean query and dummy parent
        // addConstraints() will return early because dummy parent doesn't exist
        $instance = new static(
            $cleanQuery,
            $dummyParent,
            $this->relatedClass,
            $this->morphName,
            $this->morphType,
            $this->foreignKey,
            $this->localKey
        );

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
        $attributes[$this->morphType] = $this->getMorphClass();

        return $this->relatedClass::create($attributes);
    }

    /**
     * Save a related model.
     */
    public function save(Model $model): Model
    {
        $model->setAttribute($this->foreignKey, $this->getParentKeyOrFail());
        $model->setAttribute($this->morphType, $this->getMorphClass());
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
     * // Soft delete the image for a post
     * $post->image()->softDelete();
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
     * // Restore the soft-deleted image for a post
     * $post->image()->restore();
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
            ->where($this->morphType, $this->getMorphClass())
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
     * Get the first related model or instantiate a new one.
     */
    public function firstOrNew(array $attributes = []): Model
    {
        $instance = $this->getResults();

        if ($instance !== null) {
            return $instance;
        }

        $attributes[$this->foreignKey] = $this->parent->getAttribute($this->localKey);
        $attributes[$this->morphType] = $this->getMorphClass();

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
     * Get the count of related models (always 0 or 1 for MorphOne).
     */
    public function count(): int
    {
        return $this->parent->exists() ? $this->query->count() : 0;
    }

    // =========================================================================
    // GETTER METHODS
    // =========================================================================

    /**
     * Get the morph type column name.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Get the morph class value.
     */
    public function getMorphClassValue(): string
    {
        return $this->getMorphClass();
    }

    /**
     * Check if the parent is of a specific morph type.
     */
    public function isType(string $type): bool
    {
        return $this->getMorphClass() === $type;
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
