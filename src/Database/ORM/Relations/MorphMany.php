<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Relations;

use Toporia\Framework\Database\ORM\{Model, ModelCollection};
use Toporia\Framework\Database\Query\{QueryBuilder, RowCollection};
use Toporia\Framework\Support\Pagination\CursorPaginator;

/**
 * MorphMany Relationship
 *
 * Handles polymorphic one-to-many relationships.
 * Example: Post/Video morphMany Comments
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
 * @package     toporia/framework
 * @subpackage  Relations
 * @since       2025-01-10
 */
class MorphMany extends Relation
{
    /** @var string Morph type column name */
    protected string $morphType;

    /** @var string|null Cached related table name */
    private ?string $relatedTableCache = null;

    /**
     * @param QueryBuilder $query Query builder for related model
     * @param Model $parent Parent model instance (Post or Video)
     * @param class-string<Model> $relatedClass Related model class (Comment)
     * @param string $morphName Morph name ('commentable')
     * @param string|null $morphType Type column (commentable_type)
     * @param string|null $morphId ID column (commentable_id)
     * @param string|null $localKey Local key on parent (id)
     */
    public function __construct(
        QueryBuilder $query,
        Model $parent,
        protected string $relatedClass,
        protected string $morphName,
        ?string $morphType = null,
        ?string $morphId = null,
        ?string $localKey = null
    ) {
        $this->morphType = $morphType ?? "{$morphName}_type";
        $this->foreignKey = $morphId ?? "{$morphName}_id";
        $this->localKey = $localKey ?? $parent::getPrimaryKey();

        parent::__construct($query, $parent, $this->foreignKey, $this->localKey);

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
            $this->query->where($this->morphType, $this->getMorphClass());
            $this->query->where($this->foreignKey, $this->parent->getAttribute($this->localKey));
        }

        // Apply soft delete scope if related model uses soft deletes
        $this->applySoftDeleteScope($this->query, $this->relatedClass);

        return $this;
    }

    // =========================================================================
    // TABLE NAME HELPERS (with caching)
    // =========================================================================

    /**
     * Get cached related table name.
     */
    protected function getRelatedTable(): string
    {
        return $this->relatedTableCache ??= $this->relatedClass::getTableName();
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
     * @return array<string, array<Model>>
     */
    protected function buildDictionary(ModelCollection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $type = $result->getAttribute($this->morphType);
            $id = $result->getAttribute($this->foreignKey);
            $key = "{$type}:{$id}";

            if (!isset($dictionary[$key])) {
                $dictionary[$key] = [];
            }
            $dictionary[$key][] = $result;
        }

        return $dictionary;
    }

    // =========================================================================
    // CORE RELATION METHODS
    // =========================================================================

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
    public function getResults(): ModelCollection
    {
        // Check if this is eager loading with limit (needs window function optimization)
        $wheres = $this->query->getWheres();

        // Use base class helper - search for any WHERE IN (no table filter needed for MorphMany)
        $isEagerLoading = $this->findWhereInRecursive($wheres, '');

        // If eager loading with limit, use window function for optimal performance
        // This matches Toporia's behavior: ROW_NUMBER() OVER (PARTITION BY ...)
        // When limit()/take() is used in eager loading, we need per-parent limiting, not global limiting
        // Also supports offset()/skip() for pagination within each parent
        if ($isEagerLoading) {
            $orders = $this->query->getOrders();
            $limit = $this->query->getLimit();
            $offset = $this->query->getOffset();

            // Use window function when limit is present (even without explicit orderBy)
            // Also works with offset/skip for pagination
            if ($limit !== null && $limit > 0) {
                // Build window function query for morph relationships
                $table = $this->getRelatedTable();
                $grammar = $this->query->getConnection()->getGrammar();

                // Check if database supports window functions
                // MySQL 8.0+, PostgreSQL, SQLite 3.25+ support window functions
                // For older databases, fall back to standard query (less efficient but compatible)
                if (!$grammar->supportsFeature('window_functions')) {
                    // Fallback: Execute query without per-parent limit optimization
                    $rows = $this->query->get();
                    if ($rows->isEmpty()) {
                        return new ModelCollection([]);
                    }
                    return $this->relatedClass::hydrate($rows->toArray());
                }

                $wrappedMorphType = $grammar->wrapColumn($this->morphType);
                $wrappedForeignKey = $grammar->wrapColumn($this->foreignKey);

                // Build ORDER BY clause for window function
                // If no explicit orderBy, use primary key as default for consistent ordering
                $orderParts = [];
                if (!empty($orders)) {
                    foreach ($orders as $order) {
                        $col = $order['column'] ?? '';
                        $dir = strtoupper($order['direction'] ?? 'ASC');
                        $wrappedCol = $grammar->wrapColumn($col);
                        $orderParts[] = "{$wrappedCol} {$dir}";
                    }
                } else {
                    // Default order by primary key for consistent results
                    $primaryKey = $this->relatedClass::getPrimaryKey();
                    $wrappedPrimaryKey = $grammar->wrapColumn($primaryKey);
                    $orderParts[] = "{$wrappedPrimaryKey} ASC";
                }
                $orderByClause = implode(', ', $orderParts);

                // Build base query with all conditions
                $connection = $this->query->getConnection();
                $baseQuery = new QueryBuilder($connection);
                $baseQuery->table($table);

                // Extract morph type and foreign keys from nested where (from addEagerConstraints)
                // This will be used to build WHERE clause in window function query
                $morphConditions = [];
                foreach ($wheres as $where) {
                    if (strtolower($where['type'] ?? '') === 'nested') {
                        if (isset($where['query']) && method_exists($where['query'], 'getWheres')) {
                            $nestedWheres = $where['query']->getWheres();
                            foreach ($nestedWheres as $nestedWhere) {
                                // Look for: where($morphType, $type)->whereIn($foreignKey, $ids)
                                // OR: orWhere($morphType, $type)->whereIn($foreignKey, $ids)
                                if (isset($nestedWhere['query']) && method_exists($nestedWhere['query'], 'getWheres')) {
                                    $subWheres = $nestedWhere['query']->getWheres();
                                    $morphType = null;
                                    $foreignIds = [];
                                    foreach ($subWheres as $subWhere) {
                                        if ($subWhere['type'] === 'basic' && ($subWhere['column'] ?? '') === $this->morphType) {
                                            $morphType = $subWhere['value'] ?? null;
                                        } elseif (strtolower($subWhere['type'] ?? '') === 'in' && ($subWhere['column'] ?? '') === $this->foreignKey) {
                                            $foreignIds = $subWhere['values'] ?? [];
                                        }
                                    }
                                    if ($morphType && !empty($foreignIds)) {
                                        $morphConditions[] = ['type' => $morphType, 'ids' => $foreignIds];
                                    }
                                }
                            }
                        }
                    }
                }

                // Copy only non-nested where conditions to base query
                foreach ($wheres as $where) {
                    // Skip nested where (from addEagerConstraints), handled separately
                    if (strtolower($where['type'] ?? '') === 'nested') {
                        continue;
                    }

                    match ($where['type'] ?? '') {
                        'basic' => $baseQuery->where(
                            $where['column'],
                            $where['operator'] ?? '=',
                            $where['value'] ?? null,
                            $where['boolean'] ?? 'AND'
                        ),
                        'Null' => $baseQuery->whereNull($where['column'], $where['boolean'] ?? 'AND'),
                        'NotNull' => $baseQuery->whereNotNull($where['column'], $where['boolean'] ?? 'AND'),
                        'In' => $baseQuery->whereIn($where['column'], $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                        'NotIn' => $baseQuery->whereNotIn($where['column'], $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                        'Raw' => $baseQuery->whereRaw(
                            $where['sql'] ?? '',
                            $where['bindings'] ?? [],
                            $where['boolean'] ?? 'AND'
                        ),
                        default => null
                    };
                }

                // Copy custom select and orderBy from constraint closure
                $this->copySelectAndOrderBy($this->query, $baseQuery, true);

                // Build base query SQL and bindings
                $baseQuerySql = $baseQuery->toSql();
                $baseQueryBindings = $baseQuery->getBindings();

                // Build optimized window function query for morph relationships
                // Partition by both morphType and foreignKey to handle multiple types
                // Support both limit and offset (skip) for pagination within each parent

                // Build WHERE clause for morph conditions
                // WHERE (type = ? AND id IN (...)) OR (type = ? AND id IN (...))
                $morphWhereParts = [];
                $morphBindings = [];
                foreach ($morphConditions as $condition) {
                    $idCount = count($condition['ids']);
                    $idPlaceholders = implode(',', array_fill(0, $idCount, '?'));
                    $morphWhereParts[] = "({$wrappedMorphType} = ? AND {$wrappedForeignKey} IN ({$idPlaceholders}))";
                    $morphBindings[] = $condition['type'];
                    $morphBindings = array_merge($morphBindings, $condition['ids']);
                }
                $morphWhereClause = implode(' OR ', $morphWhereParts);

                // Build WHERE clause for row number filtering
                // If offset is present, filter: offset < row <= (offset + limit)
                // Otherwise, filter: row <= limit
                if ($offset !== null && $offset > 0) {
                    $rowFilter = "toporia_row > {$offset} AND toporia_row <= " . ($offset + $limit);
                } else {
                    $rowFilter = "toporia_row <= {$limit}";
                }

                // Window query: partition by morph type and foreign key, filter by parent IDs
                $windowQuery = "SELECT * FROM (SELECT toporia_base.*, ROW_NUMBER() OVER (PARTITION BY toporia_base.{$wrappedMorphType}, toporia_base.{$wrappedForeignKey} ORDER BY {$orderByClause}) AS toporia_row FROM ({$baseQuerySql}) AS toporia_base WHERE {$morphWhereClause}) AS toporia_table WHERE {$rowFilter} ORDER BY toporia_row";

                // Combine bindings: base query bindings + morph bindings
                // PERFORMANCE: Use spread operator for better performance with small arrays
                $allBindings = [...$baseQueryBindings, ...$morphBindings];

                // Execute optimized window function query
                $rows = $connection->select($windowQuery, $allBindings);

                if (empty($rows)) {
                    return new ModelCollection([]);
                }

                return $this->relatedClass::hydrate($rows);
            }
        }

        // Fallback to standard query execution
        if ($this->parent->exists()) {
            $freshQuery = $this->query->newQuery();
            $freshQuery->table($this->getRelatedTable());

            $freshQuery->where($this->morphType, $this->getMorphClass());
            $freshQuery->where($this->foreignKey, $this->parent->getAttribute($this->localKey));

            $rowCollection = $freshQuery->get();
        } else {
            $rowCollection = $this->query->get();
        }

        $rows = $rowCollection instanceof RowCollection
            ? $rowCollection->all()
            : $rowCollection;

        if (empty($rows)) {
            return new ModelCollection([]);
        }

        return $this->relatedClass::hydrate($rows);
    }

    /**
     * {@inheritdoc}
     *
     * Eager loading optimization with closures:
     * Groups models by type and loads in minimal queries.
     *
     * Example: Loading comments for 50 Posts and 30 Videos
     * Single query with nested WHERE:
     * WHERE (type='Post' AND id IN (?,?,...)) OR (type='Video' AND id IN (?,?,...))
     *
     * Performance: 1 query instead of 80! O(N) where N = distinct types
     */
    public function addEagerConstraints(array $models): void
    {
        // Group models by type (full class name) for efficient loading
        $types = [];
        foreach ($models as $model) {
            // Use getMorphClass() if available, otherwise use get_class()
            $type = method_exists($model, 'getMorphClass')
                ? $model->getMorphClass()
                : get_class($model);

            if (!isset($types[$type])) {
                $types[$type] = [];
            }
            $types[$type][] = $model->getAttribute($this->localKey);
        }

        // Build nested WHERE with closures
        // WHERE (type='Post' AND id IN (...)) OR (type='Video' AND id IN (...))
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

        // Apply soft delete scope if related model uses soft deletes
        $this->applySoftDeleteScope($this->query, $this->relatedClass);
    }

    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     *
     * PERFORMANCE: Optimized dictionary building for collection relations.
     * Collection relations (MorphMany) return empty ModelCollection when empty.
     */
    public function match(array $models, mixed $results, string $relationName): array
    {
        // Early return: set empty collections for all models if no results (collection relation behavior)
        if (!$results instanceof ModelCollection || $results->isEmpty()) {
            foreach ($models as $model) {
                $model->setRelation($relationName, new ModelCollection([]));
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
            $related = isset($dictionary[$key]) ? $dictionary[$key] : [];
            $model->setRelation($relationName, new ModelCollection($related));
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
        // when both direct relation and nested relation are defined
        // The constraint for direct relation is applied to freshQuery before newEagerInstance is called,
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

        // Copy limit and offset from freshQuery (for window function optimization)
        $limit = $freshQuery->getLimit();
        if ($limit !== null) {
            $cleanQuery->limit($limit);
        }
        $offset = $freshQuery->getOffset();
        if ($offset !== null) {
            $cleanQuery->offset($offset);
        }

        // CRITICAL: Copy all where constraints from freshQuery (eager loading constraints from callback)
        // freshQuery contains constraints added by the eager loading callback like ->where('is_approved', true)
        // These must be copied FIRST before copying constraints from original query
        $freshWheres = $freshQuery->getWheres();
        foreach ($freshWheres as $where) {
            match ($where['type'] ?? '') {
                'basic' => $cleanQuery->where(
                    $where['column'],
                    $where['operator'] ?? '=',
                    $where['value'] ?? null,
                    $where['boolean'] ?? 'AND'
                ),
                'Null' => $cleanQuery->whereNull($where['column'], $where['boolean'] ?? 'AND'),
                'NotNull' => $cleanQuery->whereNotNull($where['column'], $where['boolean'] ?? 'AND'),
                'In' => $cleanQuery->whereIn($where['column'], $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                'NotIn' => $cleanQuery->whereNotIn($where['column'], $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                'Raw' => $cleanQuery->whereRaw(
                    $where['sql'] ?? '',
                    $where['bindings'] ?? [],
                    $where['boolean'] ?? 'AND'
                ),
                default => null
            };
        }

        // CRITICAL: Copy all where constraints from original query (relationship method)
        // This ensures constraints like where('is_approved', true) are preserved during eager loading
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
     * Save multiple related models.
     */
    public function saveMany(array $models): ModelCollection
    {
        if (empty($models)) {
            return new ModelCollection([]);
        }

        $parentKey = $this->getParentKeyOrFail();
        $morphClass = $this->getMorphClass();

        foreach ($models as &$attributes) {
            $attributes[$this->foreignKey] = $parentKey;
            $attributes[$this->morphType] = $morphClass;
        }

        return $this->bulkInsert($models);
    }

    /**
     * Create multiple related models.
     */
    public function createMany(array $models): ModelCollection
    {
        return $this->saveMany($models);
    }

    /**
     * Bulk insert models for performance.
     */
    protected function bulkInsert(array $models): ModelCollection
    {
        $table = $this->getRelatedTable();
        $now = now()->toDateTimeString();

        $preparedModels = [];
        foreach ($models as $attributes) {
            $attributes['created_at'] ??= $now;
            $attributes['updated_at'] ??= $now;
            $preparedModels[] = $attributes;
        }

        $allColumns = [];
        foreach ($preparedModels as $attributes) {
            $allColumns = [...$allColumns, ...array_keys($attributes)];
        }
        $columns = array_unique($allColumns);
        sort($columns);

        $values = [];
        $placeholders = [];

        foreach ($preparedModels as $attributes) {
            $rowValues = [];
            foreach ($columns as $column) {
                $rowValues[] = $attributes[$column] ?? null;
            }
            $placeholders[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $values = [...$values, ...$rowValues];
        }

        $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES " . implode(', ', $placeholders);
        $connection = $this->query->getConnection();
        $connection->execute($sql, $values);

        $lastInsertId = (int) $connection->lastInsertId();
        $insertedIds = range($lastInsertId, $lastInsertId + count($preparedModels) - 1);

        $savedModels = [];
        foreach ($insertedIds as $index => $id) {
            $model = new $this->relatedClass($preparedModels[$index]);
            $model->setAttribute('id', $id);
            $savedModels[] = $model;
        }

        return new ModelCollection($savedModels);
    }

    /**
     * Update all related models.
     */
    public function update(array $attributes): int
    {
        return $this->parent->exists() ? $this->query->update($attributes) : 0;
    }

    /**
     * Delete all related models.
     */
    public function delete(): int
    {
        return $this->parent->exists() ? $this->query->delete() : 0;
    }

    /**
     * Soft delete related models through this relationship.
     *
     * If related model uses soft deletes, this will soft delete the related models.
     * Otherwise, it will perform a hard delete.
     *
     * Performance: O(1) - Single UPDATE query for soft delete
     *
     * @return int Number of models soft deleted
     *
     * @example
     * ```php
     * // Soft delete all comments for a post
     * $post->comments()->softDelete();
     * ```
     */
    public function softDelete(): int
    {
        if (!$this->parent->exists()) {
            return 0;
        }

        // If related model doesn't use soft deletes, perform hard delete
        if (!$this->relatedModelUsesSoftDeletes($this->relatedClass)) {
            return $this->delete();
        }

        // Soft delete related models
        $deletedAtColumn = $this->getDeletedAtColumn($this->relatedClass);

        return $this->query
            ->where($this->morphType, $this->getMorphClass())
            ->whereNull($deletedAtColumn) // Only soft delete non-deleted records
            ->update([$deletedAtColumn => now()->toDateTimeString()]);
    }

    /**
     * Restore soft-deleted related models through this relationship.
     *
     * Restores soft-deleted related models.
     *
     * Performance: O(1) - Single UPDATE query for restore
     *
     * @return int Number of models restored
     *
     * @example
     * ```php
     * // Restore all soft-deleted comments for a post
     * $post->comments()->restore();
     * ```
     */
    public function restore(): int
    {
        if (!$this->parent->exists()) {
            return 0;
        }

        // If related model doesn't use soft deletes, return 0
        if (!$this->relatedModelUsesSoftDeletes($this->relatedClass)) {
            return 0;
        }

        // Restore related models
        $deletedAtColumn = $this->getDeletedAtColumn($this->relatedClass);

        return $this->relatedClass::withTrashed()
            ->where($this->morphType, $this->getMorphClass())
            ->where($this->foreignKey, $this->parent->getAttribute($this->localKey))
            ->whereNotNull($deletedAtColumn) // Only restore soft-deleted records
            ->update([$deletedAtColumn => null]);
    }

    /**
     * Get the first related model or create a new one.
     */
    public function firstOrCreate(array $attributes = []): Model
    {
        return $this->query->first() ?? $this->create($attributes);
    }

    /**
     * Get the first related model or instantiate a new one.
     */
    public function firstOrNew(array $attributes = []): Model
    {
        $instance = $this->query->first();

        if ($instance instanceof Model) {
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
        $query = clone $this->query;

        foreach ($attributes as $column => $value) {
            $query->where($column, $value);
        }

        $instance = $query->first();

        if ($instance instanceof Model) {
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
     * Process records in chunks to optimize memory usage.
     *
     * PERFORMANCE WARNING: Uses OFFSET/LIMIT which can be slow on large tables.
     * For better performance on large datasets, use chunkById() instead.
     *
     * CRITICAL: Always orders by primary key to ensure consistent pagination
     * and prevent skipped/duplicate records when data changes during chunking.
     */
    public function chunk(int $count, callable $callback): bool
    {
        $page = 1;
        $relatedKey = $this->relatedClass::getPrimaryKey();
        $relatedTable = $this->getRelatedTable();

        do {
            // Clone query to avoid mutating the base query
            $query = clone $this->query;

            // CRITICAL: Always order by primary key to ensure consistent pagination
            // This prevents skipped/duplicate records when data changes during chunking
            // Use qualified column name to avoid ambiguity in JOIN queries
            $query->orderBy("{$relatedTable}.{$relatedKey}", 'ASC');

            $results = $query->limit($count)->offset(($page - 1) * $count)->get();

            if ($results->isEmpty()) {
                break;
            }

            $models = $this->relatedClass::hydrate($results->toArray());

            if ($callback($models, $page) === false) {
                return false;
            }

            $page++;
        } while ($results->count() === $count);

        return true;
    }

    /**
     * Get the count of related models.
     */
    public function count(): int
    {
        return $this->parent->exists() ? $this->query->count() : 0;
    }

    /**
     * Check if any related models exist.
     */
    public function exists(): bool
    {
        return $this->parent->exists() && $this->query->exists();
    }

    // =========================================================================
    // AGGREGATION METHODS
    // =========================================================================

    /**
     * Get the sum of a column.
     */
    public function sum(string $column): float|int
    {
        return $this->query->sum($column) ?? 0;
    }

    /**
     * Get the average of a column.
     */
    public function avg(string $column): float|int
    {
        return $this->query->avg($column) ?? 0;
    }

    /**
     * Get the minimum value of a column.
     */
    public function min(string $column): mixed
    {
        return $this->query->min($column);
    }

    /**
     * Get the maximum value of a column.
     */
    public function max(string $column): mixed
    {
        return $this->query->max($column);
    }

    /**
     * Paginate the results.
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $total = $this->count();
        $offset = ($page - 1) * $perPage;

        $items = $this->query->limit($perPage)->offset($offset)->get();
        $models = $this->relatedClass::hydrate($items->toArray());

        return [
            'data' => $models,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
            'from' => $offset + 1,
            'to' => min($offset + $perPage, $total)
        ];
    }

    /**
     * Paginate using cursor-based pagination (high-performance for large datasets).
     *
     * Cursor pagination provides O(1) performance regardless of dataset size,
     * making it ideal for large datasets (millions+ records).
     *
     * Performance Benefits:
     * - No COUNT query overhead
     * - O(1) query time with indexed WHERE clause
     * - Consistent results even with concurrent inserts/deletes
     * - Works efficiently with millions of records
     *
     * Usage:
     * ```php
     * // First page
     * $paginator = $post->comments()->cursorPaginate(50);
     *
     * // Next page (using cursor from previous response)
     * $paginator = $post->comments()->cursorPaginate(50, $request->get('cursor'));
     * ```
     *
     * @param int $perPage Number of items per page
     * @param string|null $cursor Cursor value from previous page (null for first page)
     * @param string|null $column Column to use as cursor (default: primary key)
     * @param string|null $path Base URL path for pagination links
     * @param string|null $baseUrl Base URL (scheme + host) for building full URLs
     * @param string $cursorName Query parameter name for cursor (default: 'cursor')
     * @return \Toporia\Framework\Support\Pagination\CursorPaginator
     *
     * @throws \InvalidArgumentException If perPage is invalid
     */
    public function cursorPaginate(
        int $perPage = 15,
        ?string $cursor = null,
        ?string $column = null,
        ?string $path = null,
        ?string $baseUrl = null,
        string $cursorName = 'cursor'
    ): CursorPaginator {
        // Validate parameters
        if ($perPage < 1) {
            throw new \InvalidArgumentException('Per page must be at least 1');
        }

        // Determine cursor column (default to primary key)
        if ($column === null) {
            $column = $this->relatedClass::getPrimaryKey();
        }

        // Get related table name
        $relatedTable = $this->relatedClass::getTableName();
        $fullColumn = "{$relatedTable}.{$column}";

        // Clone query to avoid modifying original
        $query = clone $this->query;

        // Get current order direction for cursor column
        $orderDirection = $this->getOrderDirectionForColumn($query, $fullColumn) ?? 'ASC';

        // Apply cursor constraint if provided
        if ($cursor !== null) {
            $cursorValue = $this->decodeCursor($cursor, $column);
            if ($cursorValue !== null) {
                // Performance: Use indexed WHERE clause (O(1) lookup)
                if ($orderDirection === 'ASC') {
                    $query->where($fullColumn, '>', $cursorValue);
                } else {
                    $query->where($fullColumn, '<', $cursorValue);
                }
            }
        }

        // Ensure ordering by cursor column for consistent pagination
        $query = $this->ensureOrderByCursorColumn($query, $fullColumn, $orderDirection);

        // Performance: Fetch one extra item to determine if there are more pages
        $items = $query->limit($perPage + 1)->get();
        $rows = $items instanceof RowCollection ? $items->toArray() : $items;
        $models = $this->relatedClass::hydrate($rows);

        // Determine if there are more pages
        $hasMore = $models->count() > $perPage;

        // Remove the extra item if it exists
        if ($hasMore) {
            $models = $models->take($perPage);
        }

        // Get cursors for next and previous pages
        $nextCursor = null;
        $prevCursor = null;

        if ($hasMore && $models->isNotEmpty()) {
            // Get the last item's cursor value
            $lastItem = $models->last();
            $nextCursorValue = $lastItem->getAttribute($column);
            $nextCursor = $this->encodeCursor($nextCursorValue, $column);
        }

        // Previous cursor is the current cursor (for backward navigation)
        $prevCursor = $cursor;

        return new CursorPaginator(
            items: $models,
            perPage: $perPage,
            nextCursor: $nextCursor,
            prevCursor: $prevCursor,
            hasMore: $hasMore,
            path: $path,
            baseUrl: $baseUrl,
            cursorName: $cursorName
        );
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
     * Supports local scopes via parent Relation::__call().
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

        // Forward to parent for local scope handling
        return parent::__call($method, $parameters);
    }

    // =========================================================================
    // FINDER METHODS
    // =========================================================================

    /**
     * Get related models with specific attributes.
     */
    public function getBy(array $attributes, array $columns = ['*']): ModelCollection
    {
        $query = clone $this->query;

        foreach ($attributes as $column => $value) {
            $query->where($column, $value);
        }

        $results = $query->select($columns)->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    /**
     * Get the latest related models.
     */
    public function latest(int $limit = 10, string $column = 'created_at'): ModelCollection
    {
        $results = $this->query->orderBy($column, 'desc')->limit($limit)->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    /**
     * Get the oldest related models.
     */
    public function oldest(int $limit = 10, string $column = 'created_at'): ModelCollection
    {
        $results = $this->query->orderBy($column, 'asc')->limit($limit)->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    /**
     * Sync related models by morph type and unique column.
     */
    public function syncBy(array $models, string $uniqueColumn): array
    {
        $changes = ['created' => [], 'updated' => [], 'deleted' => []];

        if (empty($models)) {
            return $changes;
        }

        $parentKey = $this->getParentKeyOrFail();
        $morphClass = $this->getMorphClass();

        $existing = $this->query->get();
        $existingByUnique = [];
        foreach ($existing as $model) {
            $key = $model->getAttribute($uniqueColumn);
            if ($key !== null) {
                $existingByUnique[$key] = $model;
            }
        }

        $processedKeys = [];
        foreach ($models as $modelData) {
            if (!is_array($modelData)) {
                continue;
            }

            $uniqueValue = $modelData[$uniqueColumn] ?? null;
            if ($uniqueValue === null) {
                continue;
            }

            $processedKeys[] = $uniqueValue;

            if (isset($existingByUnique[$uniqueValue])) {
                $existingModel = $existingByUnique[$uniqueValue];
                foreach ($modelData as $key => $value) {
                    $existingModel->setAttribute($key, $value);
                }
                $existingModel->save();
                $changes['updated'][] = $existingModel;
            } else {
                $modelData[$this->foreignKey] = $parentKey;
                $modelData[$this->morphType] = $morphClass;
                $newModel = $this->relatedClass::create($modelData);
                $changes['created'][] = $newModel;
            }
        }

        foreach ($existingByUnique as $key => $model) {
            if (!in_array($key, $processedKeys, true)) {
                $model->delete();
                $changes['deleted'][] = $model;
            }
        }

        return $changes;
    }

    // =========================================================================
    // TEMPORAL METHODS
    // =========================================================================

    /**
     * Get models created within a date range.
     */
    public function createdBetween(string $startDate, string $endDate, string $column = 'created_at'): ModelCollection
    {
        $results = $this->query->whereBetween($column, [$startDate, $endDate])->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    /**
     * Get models created today.
     */
    public function createdToday(string $column = 'created_at'): ModelCollection
    {
        $today = now()->toDateString();
        $results = $this->query->whereRaw("DATE({$column}) = ?", [$today])->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    // =========================================================================
    // MORPH TYPE METHODS
    // =========================================================================

    /**
     * Get models by morph type.
     */
    public function morphedBy(string $morphType): ModelCollection
    {
        $results = $this->query->where($this->morphType, $morphType)->get();
        return $this->relatedClass::hydrate($results->toArray());
    }
}
