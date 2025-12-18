<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Relations;

use Toporia\Framework\Database\ORM\{Model, ModelCollection};
use Toporia\Framework\Database\Query\{QueryBuilder, RowCollection};
use Toporia\Framework\Support\Pagination\CursorPaginator;

/**
 * HasMany Relationship
 *
 * Handles one-to-many relationships.
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
class HasMany extends Relation
{
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
     * Note: When eager loading (has whereIn), don't add individual where constraint
     * to avoid foreignKey = ? AND foreignKey IN (...) issue.
     *
     * @return $this
     */
    public function addConstraints(): static
    {
        // Check if this is eager loading (has whereIn for foreignKey)
        // If so, don't add individual constraint to avoid duplicate conditions
        $wheres = $this->query->getWheres();
        $hasWhereIn = false;
        foreach ($wheres as $where) {
            // Case-insensitive check for 'in' type (handles 'in', 'In', 'IN')
            $whereType = strtolower($where['type'] ?? '');
            if ($whereType === 'in' && ($where['column'] ?? '') === $this->foreignKey) {
                $hasWhereIn = true;
                break;
            }
        }

        // Only add individual constraint if not eager loading
        if (!$hasWhereIn && $this->parent->exists()) {
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
    public function getResults(): ModelCollection
    {
        // Check if this is eager loading with limit (needs window function optimization)
        $wheres = $this->query->getWheres();

        // Use base class helper - search for any WHERE IN (no table filter needed for HasMany)
        // We pass empty string as table name to match any WHERE IN clause
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
                // Get Grammar for safe column wrapping and feature detection
                $grammar = $this->query->getConnection()->getGrammar();

                // Check if database supports window functions
                // MySQL 8.0+, PostgreSQL, SQLite 3.25+ support window functions
                // For older databases, fall back to multiple queries (less efficient but compatible)
                if (!$grammar->supportsFeature('window_functions')) {
                    // Fallback: Execute query without per-parent limit optimization
                    // This may return more rows than expected but maintains compatibility
                    $rows = $this->query->get();
                    if ($rows->isEmpty()) {
                        return new ModelCollection([]);
                    }
                    return $this->relatedClass::hydrate($rows->toArray());
                }

                // Build window function query like other frameworks
                // SELECT * FROM (SELECT *, ROW_NUMBER() OVER (PARTITION BY foreignKey ORDER BY ...) AS toporia_row FROM table WHERE ...) AS toporia_table WHERE toporia_row <= limit
                $foreignKey = $this->foreignKey;
                $table = $this->query->getTable();

                $wrappedForeignKey = $grammar->wrapColumn($foreignKey);

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

                // Build subquery from QueryBuilder to ensure all conditions are included
                // Create a new query builder with same connection and table
                $connection = $this->query->getConnection();
                $subQuery = new QueryBuilder($connection);
                $subQuery->table($table);

                // Copy all wheres from original query (includes foreignKey IN (...), and other conditions)
                $wheres = $this->query->getWheres();
                foreach ($wheres as $where) {
                    match ($where['type'] ?? '') {
                        'basic' => $subQuery->where(
                            $where['column'],
                            $where['operator'] ?? '=',
                            $where['value'] ?? null,
                            $where['boolean'] ?? 'AND'
                        ),
                        'Null' => $subQuery->whereNull($where['column'], $where['boolean'] ?? 'AND'),
                        'NotNull' => $subQuery->whereNotNull($where['column'], $where['boolean'] ?? 'AND'),
                        'In' => $subQuery->whereIn($where['column'], $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                        'NotIn' => $subQuery->whereNotIn($where['column'], $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                        'Raw' => $subQuery->whereRaw(
                            $where['sql'] ?? '',
                            $where['bindings'] ?? [],
                            $where['boolean'] ?? 'AND'
                        ),
                        default => null
                    };
                }

                // Copy custom select and orderBy from constraint closure
                $this->copySelectAndOrderBy($this->query, $subQuery, true);

                // Build optimized window function query directly
                // Performance optimization: Use direct table reference instead of nested subquery when possible
                // Note: Requires index on (foreignKey, orderColumn) for optimal performance
                // Example: CREATE INDEX idx_reviews_product_rating ON reviews(foreign_key, rating DESC);

                // Get foreign key values from WHERE IN clause
                $foreignKeyValues = [];
                foreach ($wheres as $where) {
                    // Case-insensitive check for 'in' type
                    if (strtolower($where['type'] ?? '') === 'in') {
                        if (($where['column'] ?? '') === $foreignKey) {
                            $foreignKeyValues = $where['values'] ?? [];
                            break;
                        }
                    }
                }

                // Build base query with all conditions except WHERE IN (handled in window function)
                $baseQuery = new QueryBuilder($connection);
                $baseQuery->table($table);

                // Apply all where conditions except the WHERE IN for foreignKey
                foreach ($wheres as $where) {
                    // Case-insensitive check for 'in' type
                    if (strtolower($where['type'] ?? '') === 'in') {
                        if (($where['column'] ?? '') === $foreignKey) {
                            continue; // Skip WHERE IN for foreignKey only, handled in window function
                        }
                    }

                    // CRITICAL FIX: Add 'In' case to prevent dropping whereIn for other columns
                    // Previously, whereIn for columns OTHER than foreignKey were silently dropped
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

                // Copy selects
                $selects = $this->query->getColumns();
                if (!empty($selects)) {
                    $baseQuery->select($selects);
                } else {
                    $baseQuery->select('*');
                }

                // Build base query SQL and bindings
                $baseQuerySql = $baseQuery->toSql();
                $baseQueryBindings = $baseQuery->getBindings();

                // Build optimized window function query
                // Use parameterized query for better performance and security
                // Support both limit and offset (skip) for pagination within each parent
                $placeholders = implode(',', array_fill(0, count($foreignKeyValues), '?'));

                // Build WHERE clause for row number filtering
                // If offset is present, filter: offset < row <= (offset + limit)
                // Otherwise, filter: row <= limit
                if ($offset !== null && $offset > 0) {
                    $rowFilter = "toporia_row > {$offset} AND toporia_row <= " . ($offset + $limit);
                } else {
                    $rowFilter = "toporia_row <= {$limit}";
                }

                // Window query: partition by foreign key and filter by parent IDs
                $windowQuery = "SELECT * FROM (SELECT toporia_base.*, ROW_NUMBER() OVER (PARTITION BY toporia_base.{$wrappedForeignKey} ORDER BY {$orderByClause}) AS toporia_row FROM ({$baseQuerySql}) AS toporia_base WHERE toporia_base.{$wrappedForeignKey} IN ({$placeholders})) AS toporia_table WHERE {$rowFilter} ORDER BY toporia_row";

                // Combine bindings: base query bindings + foreign key values
                // PERFORMANCE: Use spread operator for better performance with small arrays
                $allBindings = [...$baseQueryBindings, ...$foreignKeyValues];

                // Execute optimized window function query with parameterized bindings
                $connection = $this->query->getConnection();
                $rows = $connection->select($windowQuery, $allBindings);

                if (empty($rows)) {
                    return new ModelCollection([]);
                }

                return $this->relatedClass::hydrate($rows);
            }
        }

        $rows = $this->query->get();

        if ($rows->isEmpty()) {
            return new ModelCollection([]);
        }

        return $this->relatedClass::hydrate($rows->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function match(array $models, mixed $results, string $relationName): array
    {
        if (!$results instanceof ModelCollection || $results->isEmpty()) {
            // Set empty collections for all models to prevent lazy loading
            foreach ($models as $model) {
                $model->setRelation($relationName, new ModelCollection([]));
            }
            return $models;
        }

        // OPTIMIZATION: Build dictionary once and reuse
        $dictionary = $this->buildDictionary($results);

        // OPTIMIZATION: Batch set relations
        foreach ($models as $model) {
            $localValue = $model->getAttribute($this->localKey);
            // Use isset() for O(1) lookup instead of ?? operator with array access
            $relatedModels = isset($dictionary[$localValue]) ? $dictionary[$localValue] : [];
            $model->setRelation($relationName, new ModelCollection($relatedModels));
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
     * Build dictionary mapping foreign key to array of models.
     *
     * For HasMany, multiple results can belong to the same parent,
     * so we group them by foreign key.
     *
     * PERFORMANCE: Optimized array building with isset() checks.
     *
     * @param ModelCollection $results
     * @return array<int|string, array<Model>>
     */
    protected function buildDictionary(ModelCollection $results): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);
            if ($key !== null) {
                // OPTIMIZATION: Use isset() check and direct assignment
                // This is faster than checking isset() then assigning separately
                if (!isset($dictionary[$key])) {
                    $dictionary[$key] = [];
                }
                $dictionary[$key][] = $result;
            }
        }
        return $dictionary;
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

        // CRITICAL: Copy all where constraints from original query (relationship method)
        // This ensures constraints like where('is_approved', true) are preserved during eager loading
        // Exclude foreign key constraint as it will be added by addEagerConstraints()
        // This allows relationship methods like approvedReviews() to work correctly with eager loading
        $this->copyWhereConstraints($cleanQuery, [$this->foreignKey]);

        // Create a dummy parent model that doesn't exist to prevent addConstraints()
        // from adding WHERE foreignKey = ? constraint. This ensures only WHERE foreignKey IN (...)
        // from addEagerConstraints() is used during eager loading.
        // Creating a new instance ensures exists() returns false and localKey is null
        $parentClass = get_class($this->parent);
        $dummyParent = new $parentClass();

        // Create instance with clean query and dummy parent
        // addConstraints() will return early because dummy parent doesn't exist
        $instance = new static(
            $cleanQuery,
            $dummyParent,
            $this->relatedClass,
            $this->foreignKey,
            $this->localKey
        );

        return $instance;
    }

    // =========================================================================
    // BULK OPERATIONS
    // =========================================================================

    /**
     * Get parent key or throw exception if not available.
     *
     * @return mixed Parent key value
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
     * Prepare models for bulk insert by adding foreign key.
     *
     * @param array<int, array<string, mixed>> $models Array of model attributes
     * @param mixed $parentKey Parent key value
     * @return array<int, array<string, mixed>> Prepared models with foreign key
     */
    protected function prepareModelsForBulkInsert(array $models, mixed $parentKey): array
    {
        return array_map(function ($attributes) use ($parentKey) {
            $attributes[$this->foreignKey] = $parentKey;
            return $attributes;
        }, $models);
    }

    /**
     * Execute bulk insert and return inserted IDs.
     *
     * @param array<int, array<string, mixed>> $models Prepared models to insert
     * @return array<int> Array of inserted IDs
     */
    protected function executeBulkInsert(array $models): array
    {
        $connection = $this->query->getConnection();
        $table = $this->relatedClass::getTableName();

        // Use transaction for atomicity
        $connection->beginTransaction();

        try {
            $insertedIds = [];

            foreach ($models as $attributes) {
                $connection->table($table)->insert($attributes);
                $insertedIds[] = (int) $connection->getPdo()->lastInsertId();
            }

            $connection->commit();
            return $insertedIds;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Hydrate inserted models from attributes and IDs.
     *
     * @param array<int, array<string, mixed>> $models Model attributes
     * @param array<int> $insertedIds Inserted IDs
     * @return ModelCollection Collection of hydrated models
     */
    protected function hydrateInsertedModels(array $models, array $insertedIds): ModelCollection
    {
        $primaryKey = $this->relatedClass::getPrimaryKey();
        $hydratedModels = [];

        foreach ($models as $index => $attributes) {
            $attributes[$primaryKey] = $insertedIds[$index] ?? null;
            $model = new $this->relatedClass($attributes);
            $model->syncOriginal(); // Mark as persisted
            $hydratedModels[] = $model;
        }

        return new ModelCollection($hydratedModels);
    }

    /**
     * Save multiple related models using bulk insert.
     *
     * @param array<int, array<string, mixed>> $models Array of model attributes
     * @return ModelCollection Collection of saved models
     */
    public function saveMany(array $models): ModelCollection
    {
        if ($models === []) {
            return new ModelCollection([]);
        }

        $parentKey = $this->getParentKeyOrFail();
        $preparedModels = $this->prepareModelsForBulkInsert($models, $parentKey);

        $insertedIds = $this->executeBulkInsert($preparedModels);

        return $this->hydrateInsertedModels($preparedModels, $insertedIds);
    }

    /**
     * Create multiple related models (alias for saveMany).
     */
    public function createMany(array $models): ModelCollection
    {
        return $this->saveMany($models);
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
     * // Soft delete all reviews for a product
     * $product->reviews()->softDelete();
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
     * // Restore all soft-deleted reviews for a product
     * $product->reviews()->restore();
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
     * Get the first related model or instantiate a new one (without saving).
     */
    public function firstOrNew(array $attributes = []): Model
    {
        $instance = $this->query->first();

        if ($instance instanceof Model) {
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

    /**
     * Process records in chunks to optimize memory usage.
     *
     * PERFORMANCE WARNING: Uses OFFSET/LIMIT which can be slow on large tables.
     * For better performance on large datasets, use chunkById() instead.
     *
     * Performance: O(n/chunk_size) but OFFSET becomes slower as offset increases
     * Clean Architecture: Callback pattern for flexible processing
     *
     * @param int $count Number of records per chunk
     * @param callable $callback Function to process each chunk
     * @return bool True if all chunks processed successfully
     *
     * @example
     * ```php
     * // For small to medium datasets
     * $user->posts()->chunk(100, function($posts) {
     *     foreach ($posts as $post) {
     *         // Process each post
     *     }
     * });
     *
     * // For large datasets, prefer chunkById():
     * $user->posts()->chunkById(100, function($posts) {
     *     // Much faster on large tables
     * });
     * ```
     */
    public function chunk(int $count, callable $callback): bool
    {
        $page = 1;
        $relatedKey = $this->relatedClass::getPrimaryKey();
        $relatedTable = $this->getRelatedTableName();

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
     * Process records in chunks ordered by ID for consistent results.
     *
     * Performance: O(n/chunk_size) - Consistent ordering prevents missed records
     * Clean Architecture: ID-based chunking ensures reliable pagination
     *
     * @param int $count Number of records per chunk
     * @param callable $callback Function to process each chunk
     * @param string $column Column to order by (default: 'id')
     * @param string $alias Optional column alias
     * @return bool True if all chunks processed successfully
     *
     * @example
     * ```php
     * $user->posts()->chunkById(50, function($posts) {
     *     // Process posts in consistent order
     * });
     * ```
     */
    public function chunkById(int $count, callable $callback, string $column = 'id', ?string $alias = null): bool
    {
        $alias = $alias ?: $column;
        $lastId = null;

        do {
            $clone = clone $this->query;

            if ($lastId !== null) {
                $clone->where($column, '>', $lastId);
            }

            $results = $clone->orderBy($column)->limit($count)->get();

            if ($results->isEmpty()) {
                break;
            }

            $models = $this->relatedClass::hydrate($results->toArray());

            if ($callback($models) === false) {
                return false;
            }

            $lastModel = $models->last();
            $lastId = $lastModel->getAttribute($alias);
        } while ($results->count() === $count);

        return true;
    }

    /**
     * Get the count of related models.
     *
     * Performance: O(1) - Single COUNT query
     * Clean Architecture: Expressive counting method
     *
     * @return int Count of related models
     *
     * @example
     * ```php
     * $postCount = $user->posts()->count();
     * ```
     */
    public function count(): int
    {
        if (!$this->parent->exists()) {
            return 0;
        }

        return $this->query->count();
    }

    /**
     * Check if any related models exist.
     *
     * Performance: O(1) - Single EXISTS query with early termination
     * Clean Architecture: Expressive existence check
     *
     * @return bool True if related models exist
     *
     * @example
     * ```php
     * if ($user->posts()->exists()) {
     *     // User has posts
     * }
     * ```
     */
    public function exists(): bool
    {
        if (!$this->parent->exists()) {
            return false;
        }

        return $this->query->exists();
    }

    /**
     * Get the sum of a column.
     *
     * Performance: O(1) - Single aggregation query
     * Clean Architecture: Expressive aggregation method
     *
     * @param string $column Column name
     * @return float|int
     *
     * @example
     * ```php
     * $totalViews = $user->posts()->sum('views');
     * ```
     */
    public function sum(string $column): float|int
    {
        return $this->query->sum($column) ?? 0;
    }

    /**
     * Get the average of a column.
     *
     * @param string $column Column name
     * @return float|int
     *
     * @example
     * ```php
     * $avgViews = $user->posts()->avg('views');
     * ```
     */
    public function avg(string $column): float|int
    {
        return $this->query->avg($column) ?? 0;
    }

    /**
     * Get the minimum value of a column.
     *
     * @param string $column Column name
     * @return mixed
     *
     * @example
     * ```php
     * $minViews = $user->posts()->min('views');
     * ```
     */
    public function min(string $column): mixed
    {
        return $this->query->min($column);
    }

    /**
     * Get the maximum value of a column.
     *
     * @param string $column Column name
     * @return mixed
     *
     * @example
     * ```php
     * $maxViews = $user->posts()->max('views');
     * ```
     */
    public function max(string $column): mixed
    {
        return $this->query->max($column);
    }

    /**
     * Paginate the results.
     *
     * Performance: O(1) - Single query with LIMIT/OFFSET
     * Clean Architecture: Consistent pagination interface
     *
     * @param int $perPage Items per page
     * @param int $page Current page
     * @return array Pagination results
     *
     * @example
     * ```php
     * $posts = $user->posts()->paginate(10, 2);
     * ```
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $total = $this->count();
        $offset = ($page - 1) * $perPage;

        $items = $this->query->limit($perPage)->offset($offset)->get();

        return [
            'data' => $this->relatedClass::hydrate($items->toArray()),
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
     * $paginator = $user->posts()->cursorPaginate(50);
     *
     * // Next page (using cursor from previous response)
     * $paginator = $user->posts()->cursorPaginate(50, $request->get('cursor'));
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


    /**
     * Find a related model by its primary key.
     *
     * Performance: O(log n) - Indexed primary key lookup
     * Clean Architecture: Expressive finder method
     *
     * @param mixed $id Primary key value
     * @param array $columns Columns to select
     * @return Model|null
     *
     * @example
     * ```php
     * $post = $user->posts()->find(1);
     * ```
     */
    public function find(mixed $id, array $columns = ['*']): ?Model
    {
        return $this->query->where('id', $id)->select($columns)->first();
    }

    /**
     * Find multiple related models by their primary keys.
     *
     * Performance: O(log n) - Indexed primary key lookup with IN clause
     * Clean Architecture: Bulk finder method
     *
     * @param array $ids Array of primary key values
     * @param array $columns Columns to select
     * @return ModelCollection
     *
     * @example
     * ```php
     * $posts = $user->posts()->findMany([1, 2, 3]);
     * ```
     */
    public function findMany(array $ids, array $columns = ['*']): ModelCollection
    {
        if (empty($ids)) {
            return new ModelCollection([]);
        }

        return $this->relatedClass::hydrate(
            $this->query->whereIn('id', $ids)->select($columns)->get()->toArray()
        );
    }

    /**
     * Magic method to delegate calls to the underlying query builder.
     *
     * Performance: O(1) - Direct method delegation
     * Clean Architecture: Proxy pattern for query builder methods
     * SOLID: Interface Segregation - Expose only relevant query methods
     *
     * @param string $method Method name
     * @param array $parameters Method parameters
     * @return mixed
     *
     * @throws \BadMethodCallException If method doesn't exist on query builder
     */
    public function __call(string $method, array $parameters): mixed
    {
        // Delegate to query builder for standard query methods
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

    /**
     * Get related models with specific attributes.
     *
     * Performance: O(n) - Single query with WHERE constraints
     * Clean Architecture: Expressive finder method
     *
     * @param array $attributes Attributes to search by
     * @param array $columns Columns to select
     * @return ModelCollection
     *
     * @example
     * ```php
     * $publishedPosts = $user->posts()->getBy(['status' => 'published']);
     * ```
     */
    public function getBy(array $attributes, array $columns = ['*']): ModelCollection
    {
        $query = clone $this->query;

        foreach ($attributes as $column => $value) {
            $query->where($column, $value);
        }

        return $this->relatedClass::hydrate($query->select($columns)->get()->toArray());
    }

    /**
     * Get the latest related models.
     *
     * Performance: O(log n) - Uses ORDER BY with LIMIT
     * Clean Architecture: Expressive temporal method
     *
     * @param int $limit Number of records to get
     * @param string $column Column to order by (default: 'created_at')
     * @return ModelCollection
     *
     * @example
     * ```php
     * $latestPosts = $user->posts()->latest(5);
     * ```
     */
    public function latest(int $limit = 10, string $column = 'created_at'): ModelCollection
    {
        return $this->relatedClass::hydrate(
            $this->query->orderBy($column, 'desc')->limit($limit)->get()->toArray()
        );
    }

    /**
     * Get the oldest related models.
     *
     * Performance: O(log n) - Uses ORDER BY with LIMIT
     * Clean Architecture: Expressive temporal method
     *
     * @param int $limit Number of records to get
     * @param string $column Column to order by (default: 'created_at')
     * @return ModelCollection
     *
     * @example
     * ```php
     * $oldestPosts = $user->posts()->oldest(5);
     * ```
     */
    public function oldest(int $limit = 10, string $column = 'created_at'): ModelCollection
    {
        return $this->relatedClass::hydrate(
            $this->query->orderBy($column, 'asc')->limit($limit)->get()->toArray()
        );
    }

    /**
     * Get random related models.
     *
     * Performance: O(n) - Database-dependent random ordering
     * Clean Architecture: Expressive randomization method
     *
     * @param int $limit Number of records to get
     * @return ModelCollection
     *
     * @example
     * ```php
     * $randomPosts = $user->posts()->random(3);
     * ```
     */
    public function random(int $limit = 1): ModelCollection
    {
        return $this->relatedClass::hydrate(
            $this->query->orderByRaw('RAND()')->limit($limit)->get()->toArray()
        );
    }

    /**
     * Sync related models (for HasMany with unique constraints).
     *
     * Performance: O(n) - Batch operations when possible
     * Clean Architecture: Atomic sync operation
     *
     * @param array $models Array of model data or IDs
     * @param string $uniqueColumn Column to use for uniqueness check
     * @return array Sync results
     *
     * @example
     * ```php
     * $user->posts()->syncBy([
     *     ['title' => 'Post 1', 'content' => 'Content 1'],
     *     ['title' => 'Post 2', 'content' => 'Content 2']
     * ], 'title');
     * ```
     */
    public function syncBy(array $models, string $uniqueColumn): array
    {
        $changes = ['created' => [], 'updated' => [], 'deleted' => []];

        if (empty($models)) {
            return $changes;
        }

        $parentKey = $this->parent->getAttribute($this->localKey);
        if ($parentKey === null) {
            throw new \RuntimeException('Cannot sync related models: parent model does not have a key');
        }

        // Get existing models
        $existing = $this->query->get();
        $existingByUnique = [];
        foreach ($existing as $model) {
            $key = $model->getAttribute($uniqueColumn);
            if ($key !== null) {
                $existingByUnique[$key] = $model;
            }
        }

        // Process new models
        $processedKeys = [];
        foreach ($models as $modelData) {
            if (is_array($modelData)) {
                $uniqueValue = $modelData[$uniqueColumn] ?? null;
                if ($uniqueValue === null) continue;

                $processedKeys[] = $uniqueValue;

                if (isset($existingByUnique[$uniqueValue])) {
                    // Update existing
                    $existingModel = $existingByUnique[$uniqueValue];
                    foreach ($modelData as $key => $value) {
                        $existingModel->setAttribute($key, $value);
                    }
                    $existingModel->save();
                    $changes['updated'][] = $existingModel;
                } else {
                    $modelData[$this->foreignKey] = $parentKey;
                    $changes['created'][] = $this->relatedClass::create($modelData);
                }
            }
        }

        // Delete models not in the new set
        foreach ($existingByUnique as $key => $model) {
            if (!in_array($key, $processedKeys)) {
                $model->delete();
                $changes['deleted'][] = $model;
            }
        }

        return $changes;
    }

    /**
     * Get models created within a date range.
     *
     * Performance: O(log n) - Uses indexed date column
     * Clean Architecture: Expressive temporal filtering
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @param string $column Date column (default: 'created_at')
     * @return ModelCollection
     *
     * @example
     * ```php
     * $recentPosts = $user->posts()->createdBetween('2024-01-01', '2024-01-31');
     * ```
     */
    public function createdBetween(string $startDate, string $endDate, string $column = 'created_at'): ModelCollection
    {
        return $this->relatedClass::hydrate(
            $this->query->whereBetween($column, [$startDate, $endDate])->get()->toArray()
        );
    }

    /**
     * Get models created today.
     *
     * Performance: O(log n) - Uses DATE function with index
     * Clean Architecture: Expressive temporal method
     *
     * @param string $column Date column (default: 'created_at')
     * @return ModelCollection
     *
     * @example
     * ```php
     * $todaysPosts = $user->posts()->createdToday();
     * ```
     */
    public function createdToday(string $column = 'created_at'): ModelCollection
    {
        return $this->relatedClass::hydrate(
            $this->query->whereRaw("DATE({$column}) = ?", [now()->toDateString()])->get()->toArray()
        );
    }
}
