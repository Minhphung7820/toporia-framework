<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM;

use Closure;
use Toporia\Framework\Database\Query\QueryBuilder;
use Toporia\Framework\Database\Contracts\ConnectionInterface;
use Toporia\Framework\Database\Contracts\RelationInterface;
use Toporia\Framework\Database\DatabaseCollection;
use Toporia\Framework\Database\ORM\Relations\BelongsTo;
use Toporia\Framework\Database\ORM\Relations\BelongsToMany;
use Toporia\Framework\Database\ORM\Relations\HasManyThrough;
use Toporia\Framework\Database\ORM\Relations\MorphMany;
use Toporia\Framework\Database\ORM\Relations\MorphOne;
use Toporia\Framework\Database\ORM\Relations\MorphTo;
use Toporia\Framework\Database\ORM\Relations\MorphToMany;
use Toporia\Framework\Database\ORM\Relations\MorphedByMany;
use Toporia\Framework\Database\ORM\Relations\Relation;
use Toporia\Framework\Support\Collection\LazyCollection;
use Toporia\Framework\Support\Pagination\CursorPaginator;
use Toporia\Framework\Support\Pagination\Paginator;


/**
 * Class ModelQueryBuilder
 *
 * Fluent SQL query builder providing chainable interface for constructing
 * SELECT, INSERT, UPDATE, DELETE queries with automatic parameter binding
 * and join support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  ORM
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class ModelQueryBuilder extends QueryBuilder
{
    /**
     * @param ConnectionInterface $connection Database connection
     * @param class-string<TModel> $modelClass Model class to hydrate results into
     */
    /**
     * Whether to skip applying global scopes.
     * Used by SoftDeletes::withTrashed() to bypass scopes.
     *
     * @var bool
     */
    private bool $skipGlobalScopes = false;



    /**
     * Whether relationship caching is enabled for this query.
     *
     * @var bool
     */
    private bool $relationshipCachingEnabled = false;

    /**
     * Eager loaded relationships configuration (ORM layer).
     *
     * @var array<string, callable|null>
     */
    private array $eagerLoad = [];

    public function __construct(
        ConnectionInterface $connection,
        private readonly string $modelClass,
        bool $skipGlobalScopes = false
    ) {
        parent::__construct($connection);

        $this->skipGlobalScopes = $skipGlobalScopes;

        // Apply global scopes if not skipped
        if (!$this->skipGlobalScopes) {
            $this->applyGlobalScopes();
        }
    }

    /**
     * Check if global scopes are being skipped.
     *
     * Used by Relation to determine if soft delete scope should be applied.
     * This avoids reflection overhead.
     *
     * @return bool
     */
    public function isSkippingGlobalScopes(): bool
    {
        return $this->skipGlobalScopes;
    }

    /**
     * Apply global scopes to the query.
     *
     * Checks if model uses HasQueryScopes trait and applies all global scopes.
     * Also applies SoftDeletes scopes if model uses SoftDeletes trait.
     *
     * @return void
     */
    private function applyGlobalScopes(): void
    {
        // Apply scopes from HasQueryScopes trait
        if (method_exists($this->modelClass, 'getGlobalScopes')) {
            $globalScopes = call_user_func([$this->modelClass, 'getGlobalScopes']);

            foreach ($globalScopes as $scope) {
                $scope($this);
            }
        }

        // Apply scopes from SoftDeletes trait (works independently)
        if (method_exists($this->modelClass, 'getSoftDeleteGlobalScopes')) {
            $softDeleteScopes = call_user_func([$this->modelClass, 'getSoftDeleteGlobalScopes']);

            foreach ($softDeleteScopes as $scope) {
                $scope($this);
            }
        }
    }

    /**
     * Execute the query and return a ModelCollection.
     *
     * @internal This is an internal implementation method.
     *           Use get() instead for cleaner public API.
     *
     * Internal method used by get(), first(), find() and relationship loading.
     * 1. Gets raw rows from database
     * 2. Hydrates into model instances
     * 3. Loads eager relationships
     *
     * @return ModelCollection<TModel>
     */
    public function getModels(): ModelCollection
    {
        // Step 1: Get raw rows from parent QueryBuilder
        $rowCollection = parent::get();
        $rows = $rowCollection->all();

        // Step 2: Hydrate rows into models
        /** @var callable $hydrate */
        $hydrate = [$this->modelClass, 'hydrate'];
        $collection = $hydrate($rows);

        // Step 3: Load eager relationships if configured
        $eagerLoad = $this->getEagerLoad();
        if (!empty($eagerLoad) && !$collection->isEmpty()) {
            /** @var callable $eagerLoadRelations */
            $eagerLoadRelations = [$this->modelClass, 'eagerLoadRelations'];
            $eagerLoadRelations($collection, $eagerLoad);
        }

        return $collection;
    }

    /**
     * Paginate the query results with Model hydration.
     *
     * Overrides parent to return Paginator with ModelCollection.
     *
     * @param int $perPage Number of items per page (default: 15)
     * @param int $page Current page number (1-indexed, default: 1)
     * @param string|null $path Base URL path for pagination links
     * @return \Toporia\Framework\Support\Pagination\Paginator<TModel>
     */
    public function paginate(int $perPage = 15, int $page = 1, ?string $path = null, ?string $baseUrl = null): Paginator
    {
        // Validate parameters
        if ($perPage < 1) {
            throw new \InvalidArgumentException('Per page must be at least 1');
        }
        if ($page < 1) {
            throw new \InvalidArgumentException('Page must be at least 1');
        }

        // Step 1: Get total count (without limit/offset)
        $total = $this->count();

        // Step 2: Get paginated items as ModelCollection
        $offset = ($page - 1) * $perPage;
        $items = $this->limit($perPage)->offset($offset)->getModels(); // Hydrates and loads relationships

        // Step 3: Return Paginator value object
        return new Paginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            path: $path,
            baseUrl: $baseUrl
        );
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
     * $paginator = ProductModel::query()
     *     ->orderBy('id', 'ASC')
     *     ->cursorPaginate(50);
     *
     * // Next page (using cursor from previous response)
     * $paginator = ProductModel::query()
     *     ->orderBy('id', 'ASC')
     *     ->cursorPaginate(50, ['cursor' => $request->get('cursor')]);
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
    /**
     * Paginate results using cursor-based pagination (Model-specific).
     *
     * Overrides parent to use model's primary key as default cursor column
     * and return ModelCollection instead of RowCollection.
     *
     * @param int $perPage Number of items per page
     * @param array<string, mixed>|null $options Options array with:
     *   - 'cursor': Encoded cursor string (optional)
     *   - 'column': Column name for cursor (default: model's primary key)
     *   - 'path': Base path for pagination URLs (optional)
     *   - 'baseUrl': Base URL for pagination URLs (optional)
     *   - 'cursorName': Query parameter name for cursor (default: 'cursor')
     * @param array<string, mixed>|null $options2 Alternative options format (for backward compatibility)
     * @return \Toporia\Framework\Support\Pagination\CursorPaginator
     */
    public function cursorPaginate(
        int $perPage = 15,
        ?array $options = null,
        ?array $options2 = null
    ): CursorPaginator {
        // Normalize options (support both formats)
        if ($options2 !== null) {
            $options = array_merge($options ?? [], $options2);
        }

        // Extract options with defaults
        $cursor = $options['cursor'] ?? null;
        // Default to model's primary key instead of 'id'
        $column = $options['column'] ?? $this->modelClass::getPrimaryKey();
        $path = $options['path'] ?? null;
        $baseUrl = $options['baseUrl'] ?? null;
        $cursorName = $options['cursorName'] ?? 'cursor';

        // Validate parameters
        if ($perPage < 1) {
            throw new \InvalidArgumentException('Per page must be at least 1');
        }

        // Get current order by direction for cursor column
        // Default to ASC if not specified
        $orderDirection = $this->getOrderDirectionForColumn($column) ?? 'ASC';

        // Build query with cursor constraint
        // Performance: Clone to avoid modifying original query
        $query = clone $this;

        // Performance Optimization: Ensure cursor column is indexed
        // For optimal performance, cursor column should have an index
        // This is especially important for large datasets
        // Note: Database will automatically use index if available

        // Apply cursor constraint if provided
        if ($cursor !== null && is_string($cursor)) {
            $cursorValue = $this->decodeCursor($cursor, $column);
            if ($cursorValue !== null) {
                // Performance: Use indexed WHERE clause (O(1) lookup)
                // WHERE id > cursor is much faster than OFFSET for large datasets
                if ($orderDirection === 'ASC') {
                    $query->where($column, '>', $cursorValue);
                } else {
                    $query->where($column, '<', $cursorValue);
                }
            }
        }

        // Ensure ordering by cursor column for consistent pagination
        // Critical: Cursor pagination requires stable ordering
        // The cursor column must be the primary sort key
        $query = $this->ensureOrderByCursorColumn($query, $column, $orderDirection);

        // Performance: Fetch one extra item to determine if there are more pages
        // This avoids an additional COUNT query (O(n) operation)
        // Instead, we use O(1) check: if we got perPage+1 items, there are more pages
        $items = $query->limit($perPage + 1)->getModels();

        // Determine if there are more pages
        $hasMore = $items->count() > $perPage;

        // Remove the extra item if it exists
        if ($hasMore) {
            $items = $items->take($perPage);
        }

        // Get cursors for next and previous pages
        $nextCursor = null;
        $prevCursor = null;

        if ($hasMore && $items->isNotEmpty()) {
            // Get the last item's cursor value
            $lastItem = $items->last();
            $nextCursorValue = $lastItem->getAttribute($column);
            $nextCursor = $this->encodeCursor($nextCursorValue, $column);
        }

        // Previous cursor is the current cursor (for backward navigation)
        $prevCursor = $cursor;

        return new CursorPaginator(
            items: $items,
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
     * Get the order direction for a specific column.
     *
     * Checks existing order by clauses to determine direction.
     * Uses public getOrders() method instead of reflection for better performance.
     *
     * Performance: O(n) where n = number of order by clauses (typically 1-3)
     * Clean Architecture: Uses public API instead of reflection
     *
     * @param string $column Column name
     * @return string|null 'ASC' or 'DESC', or null if not found
     */
    private function getOrderDirectionForColumn(string $column): ?string
    {
        // Use public getOrders() method (no reflection needed)
        $orders = $this->getOrders();

        // Find order by for this column
        foreach ($orders as $order) {
            if (isset($order['column']) && $order['column'] === $column) {
                return $order['direction'] ?? 'ASC';
            }
        }

        // Default to ASC if not found
        return 'ASC';
    }

    /**
     * Ensure query is ordered by cursor column.
     *
     * Adds cursor column as primary sort if not already present.
     * This is critical for cursor pagination to work correctly.
     *
     * Performance: O(n) where n = number of order by clauses
     * Clean Architecture: Uses public getOrders() method instead of reflection
     *
     * @param ModelQueryBuilder $query Query builder
     * @param string $column Cursor column
     * @param string $direction Order direction
     * @return ModelQueryBuilder
     */
    private function ensureOrderByCursorColumn(ModelQueryBuilder $query, string $column, string $direction): ModelQueryBuilder
    {
        // Use public getOrders() method (no reflection needed)
        $orders = $query->getOrders();

        // Check if cursor column is already in order by
        $hasCursorColumn = false;
        foreach ($orders as $order) {
            if (isset($order['column']) && $order['column'] === $column) {
                $hasCursorColumn = true;
                break;
            }
        }

        // Add cursor column as primary sort if not present
        if (!$hasCursorColumn) {
            // Note: We can't easily prepend, so we add it
            // The database will use the first order by as primary
            // For cursor pagination, cursor column should be first
            return $query->orderBy($column, $direction);
        }

        return $query;
    }

    /**
     * Encode cursor value for URL-safe transmission.
     *
     * Uses base64-encoded JSON for security and flexibility.
     * Can be extended to support complex cursors with multiple values.
     *
     * Security: Base64 encoding prevents direct manipulation
     * Performance: O(1) encoding operation
     *
     * @param mixed $value Cursor value (typically int for IDs, or string for UUIDs)
     * @param string $column Column name (for validation)
     * @return string Encoded cursor (URL-safe)
     */
    private function encodeCursor(mixed $value, string $column): string
    {
        // Format: {"column": "id", "value": 123, "ts": timestamp}
        // Timestamp can be used for cursor expiration/validation if needed
        $data = [
            'column' => $column,
            'value' => $value,
            'ts' => now()->getTimestamp(), // Optional: for cursor expiration
        ];

        return base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Decode cursor value from URL parameter.
     *
     * Validates cursor structure and column to prevent injection attacks.
     *
     * Security: Validates column name to prevent column injection
     * Performance: O(1) decoding operation
     *
     * @param string $cursor Encoded cursor
     * @param string $expectedColumn Expected column name (for validation)
     * @return mixed|null Decoded cursor value, or null if invalid
     */
    private function decodeCursor(string $cursor, string $expectedColumn): mixed
    {
        try {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false) {
                return null;
            }

            $data = json_decode($decoded, true);
            if (!is_array($data) || !isset($data['value'])) {
                return null;
            }

            // Security: Validate column matches (prevents column injection)
            // This ensures users can't manipulate cursor to query different columns
            if (isset($data['column']) && $data['column'] !== $expectedColumn) {
                return null;
            }

            return $data['value'];
        } catch (\Throwable $e) {
            // Invalid cursor format - return null to start from beginning
            return null;
        }
    }

    /**
     * Spawn a fresh ModelQueryBuilder sharing the same connection and model class.
     */
    public function newQuery(): self
    {
        return new self($this->getConnection(), $this->modelClass);
    }

    /**
     * Get the first model from the query results.
     *
     * Overrides parent to return Model instance instead of array.
     * Supports fluent syntax: Model::query()->where(...)->first()
     *
     * @return TModel|null Model instance or null
     * @phpstan-return TModel|null
     */
    public function first(): mixed
    {
        $collection = $this->limit(1)->getModels();
        return $collection->first();
    }

    /**
     * Find a model by its primary key.
     *
     * Supports both Model::find(1) and Model::query()->find(1) syntax.
     * Preserves eager loading and other query configurations.
     *
     * @param int|string $id Primary key value
     * @param string $column Column name (default: 'id')
     * @return TModel|null Model instance or null
     * @phpstan-return TModel|null
     */
    public function find(int|string $id, string $column = 'id'): mixed
    {
        // Apply where and limit to current query (preserves eager load config)
        $this->where($column, $id)->limit(1);
        $collection = $this->getModels();
        return $collection->first();
    }



    // =========================================================================
    // RELATIONSHIP QUERY METHODS
    // =========================================================================

    /**
     * Filter models that have at least one related model.
     *
     * This is a shorthand for whereHas() with default parameters.
     * Uses EXISTS subquery for optimal performance.
     *
     * @param string $relation Relationship method name
     * @param string $operator Comparison operator (>=, >, =, etc.)
     * @param int $count Minimum count (default: 1)
     * @return $this
     *
     * @example
     * // Products that have at least one review
     * ProductModel::has('reviews')->get();
     *
     * // Products with at least 5 reviews
     * ProductModel::has('reviews', '>=', 5)->get();
     *
     * // Products with exactly 3 reviews
     * ProductModel::has('reviews', '=', 3)->get();
     */
    public function has(string $relation, string $operator = '>=', int $count = 1): self
    {
        return $this->whereHas($relation, null, $operator, $count);
    }

    /**
     * OR filter models that have at least one related model.
     *
     * This is a shorthand for orWhereHas() with default parameters.
     *
     * @param string $relation Relationship method name
     * @param string $operator Comparison operator (>=, >, =, etc.)
     * @param int $count Minimum count (default: 1)
     * @return $this
     *
     * @example
     * // Products that have reviews OR have been featured
     * ProductModel::has('reviews')->orHas('features')->get();
     */
    public function orHas(string $relation, string $operator = '>=', int $count = 1): self
    {
        return $this->orWhereHas($relation, null, $operator, $count);
    }

    /**
     * Filter models that don't have any related models.
     *
     * This is a shorthand for whereDoesntHave() with default parameters.
     * Uses NOT EXISTS subquery for optimal performance.
     *
     * @param string $relation Relationship method name
     * @return $this
     *
     * @example
     * // Products that have no reviews
     * ProductModel::doesntHave('reviews')->get();
     */
    public function doesntHave(string $relation): self
    {
        return $this->whereDoesntHave($relation);
    }

    /**
     * OR filter models that don't have any related models.
     *
     * @param string $relation Relationship method name
     * @return $this
     *
     * @example
     * // Products without reviews OR without images
     * ProductModel::doesntHave('reviews')->orDoesntHave('images')->get();
     */
    public function orDoesntHave(string $relation): self
    {
        return $this->orWhereDoesntHave($relation);
    }

    /**
     * Filter models that have a polymorphic relation with specific morph types.
     *
     * This method is specifically for MorphTo relationships where you want to
     * filter based on the type of the related model.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array<string> $types Morph type class name(s) to filter by
     * @param string $operator Comparison operator (>=, =, etc.)
     * @param int $count Minimum count (default: 1)
     * @return $this
     *
     * @example
     * ```php
     * // Get comments that belong to posts (not videos or other types)
     * Comment::hasMorph('commentable', Post::class)->get();
     *
     * // Get comments that belong to posts OR videos
     * Comment::hasMorph('commentable', [Post::class, Video::class])->get();
     *
     * // Get comments with at least 2 post commentables (edge case)
     * Comment::hasMorph('commentable', Post::class, '>=', 2)->get();
     * ```
     */
    public function hasMorph(string $relation, string|array $types, string $operator = '>=', int $count = 1): self
    {
        return $this->whereHasMorph($relation, $types, null, $operator, $count);
    }

    /**
     * Filter models that have a polymorphic relation with constraints.
     *
     * This method allows filtering on MorphTo relationships with additional
     * constraints applied to the related models. Supports:
     * - Nested queries (whereHas inside callback)
     * - All query methods (where, whereIn, whereNull, etc.)
     * - Multiple morph types with OR logic
     * - Wildcard '*' for all types
     *
     * Performance: Uses EXISTS subquery (faster than COUNT for existence checks)
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array<string> $types Morph type class name(s) to filter by
     * @param callable|null $callback Optional callback to constrain each morph type query
     * @param string $operator Comparison operator (>=, =, etc.)
     * @param int $count Minimum count (default: 1)
     * @return $this
     *
     * @example
     * ```php
     * // Simple: Get comments that belong to posts
     * Comment::hasMorph('commentable', Post::class)->get();
     *
     * // With constraints: Get comments on published posts
     * Comment::whereHasMorph('commentable', Post::class, function($query) {
     *     $query->where('published', true);
     * })->get();
     *
     * // Multiple types: Get comments on posts OR videos
     * Comment::whereHasMorph('commentable', [Post::class, Video::class], function($query, $type) {
     *     $query->where('active', true);
     *     if ($type === Post::class) {
     *         $query->where('published', true);
     *     }
     * })->get();
     *
     * // Nested queries: Get comments on posts that have active authors
     * Comment::whereHasMorph('commentable', Post::class, function($query) {
     *     $query->whereHas('author', fn($q) => $q->where('active', true));
     * })->get();
     *
     * // Wildcard: All morph types created in last 30 days
     * Comment::whereHasMorph('commentable', '*', function($query) {
     *     $query->where('created_at', '>', now()->subDays(30));
     * })->get();
     * ```
     */
    public function whereHasMorph(
        string $relation,
        string|array $types,
        ?callable $callback = null,
        string $operator = '>=',
        int $count = 1
    ): self {
        // Normalize types to array
        $types = is_array($types) ? $types : [$types];

        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        // Must be a MorphTo relationship
        if (!$relationInstance instanceof MorphTo) {
            throw new \InvalidArgumentException(
                "Relationship '{$relation}' must be a MorphTo relationship for hasMorph/whereHasMorph"
            );
        }

        // Get morph type and id columns
        $morphType = $this->getRelationProperty($relationInstance, 'morphType');
        $morphId = $this->getRelationProperty($relationInstance, 'foreignKey');

        // Handle wildcard '*' - get all possible types from the database
        if (count($types) === 1 && $types[0] === '*') {
            $distinctTypes = $this->getConnection()->select(
                "SELECT DISTINCT {$morphType} FROM {$table} WHERE {$morphType} IS NOT NULL"
            );
            $types = array_column($distinctTypes, $morphType);

            if (empty($types)) {
                $this->whereRaw('1 = 0');
                return $this;
            }
        }

        // Filter to only valid classes
        $validTypes = array_filter($types, fn($type) => class_exists($type));

        if (empty($validTypes)) {
            $this->whereRaw('1 = 0');
            return $this;
        }

        // Build combined OR EXISTS clause for all morph types
        // OPTIMIZED: Direct EXISTS without derived table wrapper for better index usage
        $this->where(function ($outerQuery) use ($validTypes, $callback, $table, $morphType, $morphId, $operator, $count) {
            $isFirst = true;

            foreach ($validTypes as $type) {
                /** @var callable $getRelatedTable */
                $getRelatedTable = [$type, 'getTableName'];
                $relatedTable = $getRelatedTable();

                /** @var callable $getPrimaryKey */
                $getPrimaryKey = [$type, 'getPrimaryKey'];
                $relatedKey = $getPrimaryKey();

                // Resolve morph type alias using global morph map
                $morphTypeValue = Relation::getMorphAlias($type);

                // Build final EXISTS clause directly without derived table
                // This is more efficient and allows better index utilization
                if ($count === 1 && $operator === '>=') {
                    // Simple existence check - use direct EXISTS (optimal)
                    // SQL: EXISTS (SELECT 1 FROM related_table WHERE related.id = parent.morph_id AND parent.morph_type = ?)
                    $existsBindings = [$morphTypeValue];

                    // Start building WHERE conditions for EXISTS
                    $whereConditions = [
                        "{$relatedTable}.{$relatedKey} = {$table}.{$morphId}",
                        "{$table}.{$morphType} = ?",
                    ];

                    // Apply callback constraints if provided
                    $callbackSql = '';
                    $callbackBindings = [];
                    if ($callback !== null) {
                        $subqueryBuilder = $type::query();
                        $callback($subqueryBuilder, $type);

                        // Extract WHERE clause from callback query
                        $callbackWheres = $subqueryBuilder->getWheres();
                        if (!empty($callbackWheres)) {
                            // Get SQL and bindings from the callback query
                            $callbackQuerySql = $subqueryBuilder->toSql();
                            $callbackBindings = $subqueryBuilder->getBindings();

                            // Extract WHERE part from the full SQL
                            // IMPORTANT: Use extractCompleteWhereClause to handle nested subqueries properly
                            $callbackSql = $this->extractCompleteWhereClause($callbackQuerySql);
                        }
                    }

                    // Build the complete EXISTS SQL
                    $existsWhere = implode(' AND ', $whereConditions);
                    if (!empty($callbackSql)) {
                        $existsWhere .= ' AND (' . $callbackSql . ')';
                        $existsBindings = array_merge($existsBindings, $callbackBindings);
                    }

                    $existsSql = "EXISTS (SELECT 1 FROM {$relatedTable} WHERE {$existsWhere})";

                    if ($isFirst) {
                        $outerQuery->whereRaw($existsSql, $existsBindings);
                        $isFirst = false;
                    } else {
                        $outerQuery->orWhereRaw($existsSql, $existsBindings);
                    }
                } else {
                    // Count-based check - still needs subquery for COUNT
                    $countBindings = [$morphTypeValue];

                    // Build WHERE conditions
                    $whereConditions = [
                        "{$relatedTable}.{$relatedKey} = {$table}.{$morphId}",
                        "{$table}.{$morphType} = ?",
                    ];

                    // Apply callback constraints if provided
                    $callbackSql = '';
                    $callbackBindings = [];
                    if ($callback !== null) {
                        $subqueryBuilder = $type::query();
                        $callback($subqueryBuilder, $type);

                        $callbackWheres = $subqueryBuilder->getWheres();
                        if (!empty($callbackWheres)) {
                            $callbackQuerySql = $subqueryBuilder->toSql();
                            $callbackBindings = $subqueryBuilder->getBindings();

                            // IMPORTANT: Use extractCompleteWhereClause to handle nested subqueries properly
                            $callbackSql = $this->extractCompleteWhereClause($callbackQuerySql);
                        }
                    }

                    $countWhere = implode(' AND ', $whereConditions);
                    if (!empty($callbackSql)) {
                        $countWhere .= ' AND (' . $callbackSql . ')';
                        $countBindings = array_merge($countBindings, $callbackBindings);
                    }

                    $countBindings[] = $count;
                    $countSql = "(SELECT COUNT(*) FROM {$relatedTable} WHERE {$countWhere}) {$operator} ?";

                    if ($isFirst) {
                        $outerQuery->whereRaw($countSql, $countBindings);
                        $isFirst = false;
                    } else {
                        $outerQuery->orWhereRaw($countSql, $countBindings);
                    }
                }
            }
        });

        return $this;
    }

    /**
     * Filter models that don't have a polymorphic relation with specific morph types.
     *
     * Supports:
     * - Nested queries (whereHas inside callback)
     * - All query methods (where, whereIn, whereNull, etc.)
     * - Multiple morph types (AND logic - must not have ANY of the types)
     *
     * Performance: Uses NOT EXISTS subquery (optimal for non-existence checks)
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array<string> $types Morph type class name(s) to filter by
     * @param callable|null $callback Optional callback to constrain each morph type query
     * @return $this
     *
     * @example
     * ```php
     * // Get comments that don't belong to posts
     * Comment::whereDoesntHaveMorph('commentable', Post::class)->get();
     *
     * // Get comments that don't belong to published posts
     * Comment::whereDoesntHaveMorph('commentable', Post::class, function($query) {
     *     $query->where('published', true);
     * })->get();
     *
     * // Nested: Get comments not on posts with active authors
     * Comment::whereDoesntHaveMorph('commentable', Post::class, function($query) {
     *     $query->whereHas('author', fn($q) => $q->where('active', true));
     * })->get();
     * ```
     */
    public function whereDoesntHaveMorph(
        string $relation,
        string|array $types,
        ?callable $callback = null
    ): self {
        // Normalize types to array
        $types = is_array($types) ? $types : [$types];

        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        // Must be a MorphTo relationship
        if (!$relationInstance instanceof MorphTo) {
            throw new \InvalidArgumentException(
                "Relationship '{$relation}' must be a MorphTo relationship for whereDoesntHaveMorph"
            );
        }

        // Get morph type and id columns
        $morphType = $this->getRelationProperty($relationInstance, 'morphType');
        $morphId = $this->getRelationProperty($relationInstance, 'foreignKey');

        // Build NOT EXISTS subqueries for each morph type (AND logic)
        // OPTIMIZED: Direct NOT EXISTS without derived table wrapper for better index usage
        foreach ($types as $type) {
            if (!class_exists($type)) {
                continue;
            }

            /** @var callable $getRelatedTable */
            $getRelatedTable = [$type, 'getTableName'];
            $relatedTable = $getRelatedTable();

            /** @var callable $getPrimaryKey */
            $getPrimaryKey = [$type, 'getPrimaryKey'];
            $relatedKey = $getPrimaryKey();

            // Resolve morph type alias using global morph map
            $morphTypeValue = Relation::getMorphAlias($type);

            // Build NOT EXISTS directly without derived table (optimal)
            // SQL: NOT EXISTS (SELECT 1 FROM related_table WHERE related.id = parent.morph_id AND parent.morph_type = ?)
            $notExistsBindings = [$morphTypeValue];

            // Start building WHERE conditions for NOT EXISTS
            $whereConditions = [
                "{$relatedTable}.{$relatedKey} = {$table}.{$morphId}",
                "{$table}.{$morphType} = ?",
            ];

            // Apply callback constraints if provided
            $callbackSql = '';
            $callbackBindings = [];
            if ($callback !== null) {
                $subqueryBuilder = $type::query();
                $callback($subqueryBuilder, $type);

                // Extract WHERE clause from callback query
                $callbackWheres = $subqueryBuilder->getWheres();
                if (!empty($callbackWheres)) {
                    // Get SQL and bindings from the callback query
                    $callbackQuerySql = $subqueryBuilder->toSql();
                    $callbackBindings = $subqueryBuilder->getBindings();

                    // Extract WHERE part from the full SQL
                    if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER|\s+LIMIT|\s+GROUP|\s*$)/is', $callbackQuerySql, $matches)) {
                        $callbackSql = $matches[1];
                    }
                }
            }

            // Build the complete NOT EXISTS SQL
            $notExistsWhere = implode(' AND ', $whereConditions);
            if (!empty($callbackSql)) {
                $notExistsWhere .= ' AND (' . $callbackSql . ')';
                $notExistsBindings = array_merge($notExistsBindings, $callbackBindings);
            }

            $notExistsSql = "NOT EXISTS (SELECT 1 FROM {$relatedTable} WHERE {$notExistsWhere})";

            $this->whereRaw($notExistsSql, $notExistsBindings);
        }

        return $this;
    }

    /**
     * OR version of hasMorph.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array<string> $types Morph type class name(s)
     * @param string $operator Comparison operator
     * @param int $count Minimum count
     * @return $this
     */
    public function orHasMorph(string $relation, string|array $types, string $operator = '>=', int $count = 1): self
    {
        return $this->orWhereHasMorph($relation, $types, null, $operator, $count);
    }

    /**
     * OR version of whereHasMorph.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array<string> $types Morph type class name(s)
     * @param callable|null $callback Optional callback
     * @param string $operator Comparison operator
     * @param int $count Minimum count
     * @return $this
     */
    public function orWhereHasMorph(
        string $relation,
        string|array $types,
        ?callable $callback = null,
        string $operator = '>=',
        int $count = 1
    ): self {
        return $this->orWhere(function ($query) use ($relation, $types, $callback, $operator, $count) {
            $query->whereHasMorph($relation, $types, $callback, $operator, $count);
        });
    }

    /**
     * Filter models that do not have a polymorphic relation (simple version).
     *
     * Convenience wrapper for whereDoesntHaveMorph without callback.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array<string> $types Morph type class name(s)
     * @return $this
     *
     * @example
     * ```php
     * // Get comments that don't belong to posts
     * Comment::doesntHaveMorph('commentable', Post::class)->get();
     *
     * // Get comments that don't belong to posts or videos
     * Comment::doesntHaveMorph('commentable', [Post::class, Video::class])->get();
     * ```
     */
    public function doesntHaveMorph(string $relation, string|array $types): self
    {
        return $this->whereDoesntHaveMorph($relation, $types, null);
    }

    /**
     * OR version of doesntHaveMorph.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array<string> $types Morph type class name(s)
     * @return $this
     *
     * @example
     * ```php
     * // Get comments that belong to posts OR don't belong to videos
     * Comment::hasMorph('commentable', Post::class)
     *     ->orDoesntHaveMorph('commentable', Video::class)
     *     ->get();
     * ```
     */
    public function orDoesntHaveMorph(string $relation, string|array $types): self
    {
        return $this->orWhereDoesntHaveMorph($relation, $types, null);
    }

    /**
     * OR version of whereDoesntHaveMorph.
     *
     * Adds an OR condition for filtering models that don't have a polymorphic relation.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array<string> $types Morph type class name(s)
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @return $this
     *
     * @example
     * ```php
     * // Get comments where:
     * // - They belong to posts with rating >= 4
     * // - OR they don't belong to any videos
     * Comment::whereHasMorph('commentable', Post::class, function($query) {
     *     $query->where('rating', '>=', 4);
     * })->orWhereDoesntHaveMorph('commentable', Video::class)->get();
     *
     * // Get comments where they don't belong to published posts OR don't belong to active videos
     * Comment::whereDoesntHaveMorph('commentable', Post::class, function($query) {
     *     $query->where('published', true);
     * })->orWhereDoesntHaveMorph('commentable', Video::class, function($query) {
     *     $query->where('active', true);
     * })->get();
     * ```
     */
    public function orWhereDoesntHaveMorph(
        string $relation,
        string|array $types,
        ?callable $callback = null
    ): self {
        return $this->orWhere(function ($query) use ($relation, $types, $callback) {
            $query->whereDoesntHaveMorph($relation, $types, $callback);
        });
    }

    /**
     * Filter models that have a related model matching the given constraints.
     *
     * Optimized implementation:
     * - Uses EXISTS subquery instead of JOIN when possible (better performance)
     * - Supports callback for complex constraints
     *
     * Clean Architecture & SOLID:
     * - Single Responsibility: Only adds WHERE EXISTS clause
     * - Open/Closed: Extensible via callback
     * - Dependency Inversion: Works with any RelationInterface
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @param string $operator Comparison operator (>=, =, etc.)
     * @param int $count Minimum count (default: 1 means "has at least one")
     * @return $this
     *
     * @example
     * // Products that have at least one review
     * ProductModel::whereHas('reviews')->get();
     *
     * // Products with reviews rating >= 4
     * ProductModel::whereHas('reviews', function($query) {
     *     $query->where('rating', '>=', 4);
     * })->get();
     *
     * // Products with at least 5 reviews
     * ProductModel::whereHas('reviews', null, '>=', 5)->get();
     */
    public function whereHas(string $relation, ?callable $callback = null, string $operator = '>=', int $count = 1): self
    {
        // Auto-detect nested relations (dot notation) and delegate to whereHasNested
        if (str_contains($relation, '.')) {
            return $this->whereHasNested($relation, $callback, $operator, $count);
        }

        // For count-based queries (count != 1), use the count approach
        if ($count !== 1 || $operator !== '>=') {
            return $this->whereHasWithCount($relation, $callback, $operator, $count);
        }

        // For simple existence check, use optimized EXISTS
        return $this->whereHasExists($relation, $callback);
    }

    /**
     * Optimized whereHas using EXISTS (fastest approach).
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @return $this
     */
    private function whereHasExists(string $relation, ?callable $callback = null): self
    {
        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relation}' is not a valid relationship");
        }

        // Get the relationship's query builder
        $relationQuery = $relationInstance->getQuery();

        // Apply callback constraints if provided
        // IMPORTANT: Pass relationship instance instead of query builder
        // This allows relationship-specific __call() methods to auto-qualify columns
        if ($callback !== null) {
            $callback($relationInstance);
        }

        // Build EXISTS subquery
        $existsResult = $this->buildExistsSubquery($relationInstance, $table, $relationQuery);
        $existsSql = $existsResult['sql'];
        $existsBindings = $existsResult['bindings'];

        // Add EXISTS clause - much faster than COUNT(*)
        $this->whereRaw("EXISTS ({$existsSql})", $existsBindings);

        return $this;
    }

    /**
     * whereHas with count comparison (for count != 1 cases).
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @param string $operator Comparison operator
     * @param int $count Count threshold
     * @return $this
     */
    private function whereHasWithCount(string $relation, ?callable $callback = null, string $operator = '>=', int $count = 1): self
    {
        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relation}' is not a valid relationship");
        }

        // Get the relationship's query builder
        $relationQuery = $relationInstance->getQuery();

        // Apply callback constraints if provided
        // IMPORTANT: Pass relationship instance instead of query builder
        // This allows relationship-specific __call() methods to auto-qualify columns
        if ($callback !== null) {
            $callback($relationInstance);
        }

        // Build COUNT subquery (only when count comparison is needed)
        $countSubquery = $this->buildCountSubquery($relationInstance, $table, $relationQuery);

        // Add count comparison clause
        $this->whereRaw("({$countSubquery}) {$operator} ?", [$count]);

        return $this;
    }

    /**
     * OR version of whereHas using EXISTS for optimal performance.
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @param string $operator Comparison operator (>=, =, etc.)
     * @param int $count Count threshold (default: 1)
     * @return $this
     */
    public function orWhereHas(string $relation, ?callable $callback = null, string $operator = '>=', int $count = 1): self
    {
        // Auto-detect nested relations (dot notation) and delegate to orWhereHasNested
        if (str_contains($relation, '.')) {
            return $this->orWhereHasNested($relation, $callback, $operator, $count);
        }

        // For count-based queries (count != 1), use the count approach
        if ($count !== 1 || $operator !== '>=') {
            return $this->orWhereHasWithCount($relation, $callback, $operator, $count);
        }

        // For simple existence check, use optimized OR EXISTS
        return $this->orWhereHasExists($relation, $callback);
    }

    /**
     * Add whereHas constraint AND eager load the relationship.
     *
     * This combines whereHas() and with() in a single call, which is more efficient
     * than calling them separately because the constraint is applied consistently
     * to both the filtering and the eager loading.
     *
     * This is particularly useful when you want to:
     * 1. Filter parent models based on relationship conditions
     * 2. Eager load only the related models that match those conditions
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @return $this
     *
     * @example
     * // Get users who have published posts, and eager load only their published posts
     * User::withWhereHas('posts', fn($q) => $q->where('published', true))->get();
     *
     * // Without withWhereHas, you would need:
     * User::whereHas('posts', fn($q) => $q->where('published', true))
     *     ->with(['posts' => fn($q) => $q->where('published', true)])
     *     ->get();
     *
     * // Get products with approved reviews, load only approved reviews
     * Product::withWhereHas('reviews', fn($q) => $q->where('approved', true))->get();
     */
    public function withWhereHas(string $relation, ?callable $callback = null): self
    {
        // Apply whereHas constraint to filter parent models
        $this->whereHas($relation, $callback);

        // Also eager load with the same constraint
        if ($callback !== null) {
            $this->with([$relation => $callback]);
        } else {
            $this->with($relation);
        }

        return $this;
    }

    // =========================================================================
    // ADVANCED RELATIONSHIP QUERY METHODS (ORM-style)
    // =========================================================================

    /**
     * Filter models where a relationship column matches a given value.
     *
     * This is a shorthand for whereHas() that makes simple column comparisons cleaner.
     * Uses EXISTS subquery for optimal performance.
     *
     * @param string $relation Relationship method name
     * @param string $column Column on the related model
     * @param mixed $operator Operator or value (if 2 args, this is value with '=' operator)
     * @param mixed $value Value to compare (optional if operator is the value)
     * @return $this
     *
     * @example
     * ```php
     * // Instead of: User::whereHas('posts', fn($q) => $q->where('published', true))->get()
     * User::whereRelation('posts', 'published', true)->get();
     *
     * // With operator
     * User::whereRelation('posts', 'views', '>=', 100)->get();
     *
     * // Nested relations supported
     * User::whereRelation('posts.comments', 'approved', true)->get();
     * ```
     */
    public function whereRelation(string $relation, string $column, mixed $operator = null, mixed $value = null): self
    {
        // Handle 2-argument version: whereRelation('posts', 'published', true)
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        // Handle nested relations
        if (str_contains($relation, '.')) {
            return $this->whereHasNested($relation, function ($query) use ($column, $operator, $value) {
                $query->where($column, $operator, $value);
            });
        }

        return $this->whereHas($relation, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * OR filter models where a relationship column matches a given value.
     *
     * @param string $relation Relationship method name
     * @param string $column Column on the related model
     * @param mixed $operator Operator or value
     * @param mixed $value Value to compare
     * @return $this
     *
     * @example
     * ```php
     * // Users with published posts OR approved comments
     * User::whereRelation('posts', 'published', true)
     *     ->orWhereRelation('comments', 'approved', true)
     *     ->get();
     * ```
     */
    public function orWhereRelation(string $relation, string $column, mixed $operator = null, mixed $value = null): self
    {
        // Handle 2-argument version
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        return $this->orWhereHas($relation, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Filter models where a morph relationship column matches a given value.
     *
     * Shorthand for whereHasMorph() with simple column comparison.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array $types Morph type class name(s)
     * @param string $column Column on the related model
     * @param mixed $operator Operator or value
     * @param mixed $value Value to compare
     * @return $this
     *
     * @example
     * ```php
     * // Images where the imageable (Post/Video) is published
     * Image::whereMorphRelation('imageable', [Post::class, Video::class], 'published', true)->get();
     *
     * // Comments on posts with high views
     * Comment::whereMorphRelation('commentable', Post::class, 'views', '>=', 1000)->get();
     * ```
     */
    public function whereMorphRelation(string $relation, string|array $types, string $column, mixed $operator = null, mixed $value = null): self
    {
        // Handle 2-argument version
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        return $this->whereHasMorph($relation, $types, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * OR filter models where a morph relationship column matches a given value.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array $types Morph type class name(s)
     * @param string $column Column on the related model
     * @param mixed $operator Operator or value
     * @param mixed $value Value to compare
     * @return $this
     */
    public function orWhereMorphRelation(string $relation, string|array $types, string $column, mixed $operator = null, mixed $value = null): self
    {
        // Handle 2-argument version
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        return $this->orWhereHasMorph($relation, $types, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Filter models that belong to a specific model instance.
     *
     * This method provides a clean syntax for filtering by a BelongsTo relationship.
     * Automatically determines the foreign key from the relationship definition.
     *
     * @param string $relation BelongsTo relationship method name
     * @param \Toporia\Framework\Database\ORM\Model|int|string|array $related Related model instance(s), ID(s)
     * @return $this
     *
     * @example
     * ```php
     * // Get posts belonging to a specific user
     * $user = User::find(1);
     * Post::whereBelongsTo($user)->get();
     *
     * // With explicit relation name
     * Post::whereBelongsTo($user, 'author')->get();
     *
     * // Multiple models
     * Post::whereBelongsTo([$user1, $user2])->get();
     *
     * // By ID
     * Post::whereBelongsTo(1, 'user')->get();
     * ```
     */
    public function whereBelongsTo(Model|int|string|array $related, ?string $relation = null): self
    {
        // If related is a model, extract the relation name from class if not provided
        if ($related instanceof Model) {
            $relation ??= $this->guessBelongsToRelation($related);
            return $this->whereBelongsToModel($related, $relation);
        }

        // If array of models
        if (is_array($related) && !empty($related) && $related[0] instanceof Model) {
            $relation ??= $this->guessBelongsToRelation($related[0]);
            return $this->whereBelongsToModels($related, $relation);
        }

        // ID or array of IDs - relation is required
        if ($relation === null) {
            throw new \InvalidArgumentException(
                'Relation name is required when filtering by ID(s). Use whereBelongsTo($id, "relationName")'
            );
        }

        $ids = is_array($related) ? $related : [$related];
        return $this->whereBelongsToIds($ids, $relation);
    }

    /**
     * OR version of whereBelongsTo.
     *
     * @param \Toporia\Framework\Database\ORM\Model|int|string|array $related Related model instance(s), ID(s)
     * @param string|null $relation Relationship method name
     * @return $this
     */
    public function orWhereBelongsTo(Model|int|string|array $related, ?string $relation = null): self
    {
        return $this->orWhere(function ($query) use ($related, $relation) {
            $query->whereBelongsTo($related, $relation);
        });
    }

    /**
     * Filter models that don't belong to a specific model instance.
     *
     * @param \Toporia\Framework\Database\ORM\Model|int|string|array $related Related model instance(s), ID(s)
     * @param string|null $relation Relationship method name
     * @return $this
     *
     * @example
     * ```php
     * // Posts NOT belonging to a specific user
     * $user = User::find(1);
     * Post::whereDoesntBelongTo($user)->get();
     * ```
     */
    public function whereDoesntBelongTo(Model|int|string|array $related, ?string $relation = null): self
    {
        // If related is a model
        if ($related instanceof Model) {
            $relation ??= $this->guessBelongsToRelation($related);
            $model = new $this->modelClass([]);
            $relationInstance = $model->$relation();

            if (!$relationInstance instanceof BelongsTo) {
                throw new \InvalidArgumentException("Relationship '{$relation}' must be a BelongsTo relationship");
            }

            $foreignKey = $relationInstance->getForeignKeyName();
            $ownerKey = $this->getRelationProperty($relationInstance, 'ownerKey') ?? $related->getPrimaryKey();

            return $this->where($foreignKey, '!=', $related->getAttribute($ownerKey));
        }

        // Array of models or IDs
        if ($relation === null && !($related instanceof Model)) {
            throw new \InvalidArgumentException(
                'Relation name is required when filtering by ID(s)'
            );
        }

        if (is_array($related) && !empty($related) && $related[0] instanceof Model) {
            $relation ??= $this->guessBelongsToRelation($related[0]);
            $model = new $this->modelClass([]);
            $relationInstance = $model->$relation();

            if (!$relationInstance instanceof BelongsTo) {
                throw new \InvalidArgumentException("Relationship '{$relation}' must be a BelongsTo relationship");
            }

            $foreignKey = $relationInstance->getForeignKeyName();
            $ownerKey = $this->getRelationProperty($relationInstance, 'ownerKey') ?? $related[0]->getPrimaryKey();

            $ids = array_map(fn($m) => $m->getAttribute($ownerKey), $related);
            return $this->whereNotIn($foreignKey, $ids);
        }

        // By ID(s)
        $model = new $this->modelClass([]);
        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof BelongsTo) {
            throw new \InvalidArgumentException("Relationship '{$relation}' must be a BelongsTo relationship");
        }

        $foreignKey = $relationInstance->getForeignKeyName();
        $ids = is_array($related) ? $related : [$related];

        return $this->whereNotIn($foreignKey, $ids);
    }

    /**
     * Filter models that have a nested relationship with constraints.
     *
     * Supports deep nesting like 'posts.comments.author' with a single callback
     * applied to the deepest relation.
     *
     * Performance: Builds nested EXISTS subqueries for optimal execution.
     *
     * @param string $relation Dot-notated relationship path
     * @param callable|null $callback Callback applied to the deepest relation
     * @param string $operator Comparison operator
     * @param int $count Minimum count
     * @return $this
     *
     * @example
     * ```php
     * // Users with posts that have approved comments
     * User::whereHasNested('posts.comments', fn($q) => $q->where('approved', true))->get();
     *
     * // Categories with products that have high-rated reviews
     * Category::whereHasNested('products.reviews', fn($q) => $q->where('rating', '>=', 4))->get();
     *
     * // Deep nesting: Users -> Posts -> Comments -> Author -> Profile
     * User::whereHasNested('posts.comments.author.profile', fn($q) => $q->where('verified', true))->get();
     * ```
     */
    public function whereHasNested(string $relation, ?callable $callback = null, string $operator = '>=', int $count = 1): self
    {
        // If no dot, use regular whereHas
        if (!str_contains($relation, '.')) {
            return $this->whereHas($relation, $callback, $operator, $count);
        }

        $relations = explode('.', $relation);
        $firstRelation = array_shift($relations);
        $remainingPath = implode('.', $relations);

        // Build nested callback
        return $this->whereHas($firstRelation, function ($query) use ($remainingPath, $callback, $operator, $count) {
            if (str_contains($remainingPath, '.')) {
                // Still more nesting - recurse
                $query->whereHasNested($remainingPath, $callback, $operator, $count);
            } else {
                // Final relation
                $query->whereHas($remainingPath, $callback, $operator, $count);
            }
        });
    }

    /**
     * OR version of whereHasNested.
     *
     * @param string $relation Dot-notated relationship path
     * @param callable|null $callback Callback applied to the deepest relation
     * @param string $operator Comparison operator
     * @param int $count Minimum count
     * @return $this
     */
    public function orWhereHasNested(string $relation, ?callable $callback = null, string $operator = '>=', int $count = 1): self
    {
        if (!str_contains($relation, '.')) {
            return $this->orWhereHas($relation, $callback, $operator, $count);
        }

        $relations = explode('.', $relation);
        $firstRelation = array_shift($relations);
        $remainingPath = implode('.', $relations);

        return $this->orWhereHas($firstRelation, function ($query) use ($remainingPath, $callback, $operator, $count) {
            if (str_contains($remainingPath, '.')) {
                $query->whereHasNested($remainingPath, $callback, $operator, $count);
            } else {
                $query->whereHas($remainingPath, $callback, $operator, $count);
            }
        });
    }

    /**
     * Add a subselect to check if a relationship exists (returns boolean column).
     *
     * Unlike withCount(), this returns a boolean (1/0) instead of count.
     * Useful for conditional logic in application code.
     *
     * @param string|array $relations Relationship name(s)
     * @return $this
     *
     * @example
     * ```php
     * // Get users with exists flag for profile
     * $users = User::withExists('profile')->get();
     * // Access: $user->profile_exists (true/false)
     *
     * // Multiple relations
     * $users = User::withExists(['profile', 'posts'])->get();
     * // Access: $user->profile_exists, $user->posts_exists
     *
     * // With alias
     * $users = User::withExists(['profile as has_profile'])->get();
     * // Access: $user->has_profile
     * ```
     */
    public function withExists(string|array $relations): self
    {
        $relations = is_array($relations) ? $relations : [$relations];

        foreach ($relations as $key => $value) {
            // Handle alias: 'relation as alias'
            $relation = is_string($key) ? $key : $value;
            $alias = null;

            if (str_contains($relation, ' as ')) {
                [$relation, $alias] = array_map('trim', explode(' as ', $relation, 2));
            }

            $alias ??= $relation . '_exists';

            // Build EXISTS subquery
            $this->addExistsSelect($relation, $alias);
        }

        return $this;
    }

    /**
     * Add EXISTS subselect for a relation.
     *
     * @param string $relation Relationship name
     * @param string $alias Column alias
     * @return void
     */
    protected function addExistsSelect(string $relation, string $alias): void
    {
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relation}' is not a valid relationship");
        }

        $relationQuery = $relationInstance->getQuery();

        // Build EXISTS subquery
        $existsResult = $this->buildExistsSubquery($relationInstance, $table, $relationQuery);
        $existsSql = $existsResult['sql'];

        // Wrap in CASE WHEN for boolean result
        $selectSql = "CASE WHEN EXISTS ({$existsSql}) THEN 1 ELSE 0 END AS {$alias}";

        $this->selectRaw($selectSql, $existsResult['bindings']);
    }

    /**
     * Shorthand for whereHas with null check (relation exists and column is null).
     *
     * @param string $relation Relationship name
     * @param string $column Column to check for null
     * @return $this
     *
     * @example
     * ```php
     * // Users with posts that have no published_at date
     * User::whereRelationNull('posts', 'published_at')->get();
     * ```
     */
    public function whereRelationNull(string $relation, string $column): self
    {
        return $this->whereHas($relation, function ($query) use ($column) {
            $query->whereNull($column);
        });
    }

    /**
     * Shorthand for whereHas with not null check.
     *
     * @param string $relation Relationship name
     * @param string $column Column to check for not null
     * @return $this
     *
     * @example
     * ```php
     * // Users with posts that are published (have published_at)
     * User::whereRelationNotNull('posts', 'published_at')->get();
     * ```
     */
    public function whereRelationNotNull(string $relation, string $column): self
    {
        return $this->whereHas($relation, function ($query) use ($column) {
            $query->whereNotNull($column);
        });
    }

    /**
     * Shorthand for whereHas with IN check.
     *
     * @param string $relation Relationship name
     * @param string $column Column to check
     * @param array $values Values to check against
     * @return $this
     *
     * @example
     * ```php
     * // Users with posts in specific categories
     * User::whereRelationIn('posts', 'category_id', [1, 2, 3])->get();
     * ```
     */
    public function whereRelationIn(string $relation, string $column, array $values): self
    {
        return $this->whereHas($relation, function ($query) use ($column, $values) {
            $query->whereIn($column, $values);
        });
    }

    /**
     * Shorthand for whereHas with NOT IN check.
     *
     * @param string $relation Relationship name
     * @param string $column Column to check
     * @param array $values Values to exclude
     * @return $this
     */
    public function whereRelationNotIn(string $relation, string $column, array $values): self
    {
        return $this->whereHas($relation, function ($query) use ($column, $values) {
            $query->whereNotIn($column, $values);
        });
    }

    /**
     * Shorthand for whereHas with BETWEEN check.
     *
     * @param string $relation Relationship name
     * @param string $column Column to check
     * @param mixed $min Minimum value
     * @param mixed $max Maximum value
     * @return $this
     *
     * @example
     * ```php
     * // Users with orders between $100 and $500
     * User::whereRelationBetween('orders', 'total', 100, 500)->get();
     * ```
     */
    public function whereRelationBetween(string $relation, string $column, mixed $min, mixed $max): self
    {
        return $this->whereHas($relation, function ($query) use ($column, $min, $max) {
            $query->whereBetween($column, [$min, $max]);
        });
    }

    // =========================================================================
    // HELPER METHODS FOR ADVANCED QUERIES
    // =========================================================================

    /**
     * Guess the BelongsTo relation name from a related model.
     *
     * @param \Toporia\Framework\Database\ORM\Model $related
     * @return string
     */
    protected function guessBelongsToRelation(Model $related): string
    {
        // Get class name without namespace and convert to camelCase
        $class = get_class($related);

        // Extract base name from fully qualified class name
        $baseName = substr(strrchr($class, '\\'), 1);
        if ($baseName === false) {
            $baseName = $class;
        }

        // Remove 'Model' suffix if present
        if (str_ends_with($baseName, 'Model')) {
            $baseName = substr($baseName, 0, -5);
        }

        return lcfirst($baseName);
    }

    /**
     * Filter by a single model using BelongsTo relation.
     *
     * @param \Toporia\Framework\Database\ORM\Model $related
     * @param string $relation
     * @return $this
     */
    protected function whereBelongsToModel(Model $related, string $relation): self
    {
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof BelongsTo) {
            throw new \InvalidArgumentException("Relationship '{$relation}' must be a BelongsTo relationship");
        }

        $foreignKey = $relationInstance->getForeignKeyName();
        $ownerKey = $this->getRelationProperty($relationInstance, 'ownerKey') ?? $related->getPrimaryKey();

        return $this->where($foreignKey, $related->getAttribute($ownerKey));
    }

    /**
     * Filter by multiple models using BelongsTo relation.
     *
     * @param array<\Toporia\Framework\Database\ORM\Model> $models
     * @param string $relation
     * @return $this
     */
    protected function whereBelongsToModels(array $models, string $relation): self
    {
        if (empty($models)) {
            return $this->whereRaw('1 = 0'); // Return no results
        }

        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof BelongsTo) {
            throw new \InvalidArgumentException("Relationship '{$relation}' must be a BelongsTo relationship");
        }

        $foreignKey = $relationInstance->getForeignKeyName();
        $ownerKey = $this->getRelationProperty($relationInstance, 'ownerKey') ?? $models[0]->getPrimaryKey();

        $ids = array_map(fn($m) => $m->getAttribute($ownerKey), $models);

        return $this->whereIn($foreignKey, $ids);
    }

    /**
     * Filter by IDs using BelongsTo relation.
     *
     * @param array $ids
     * @param string $relation
     * @return $this
     */
    protected function whereBelongsToIds(array $ids, string $relation): self
    {
        if (empty($ids)) {
            return $this->whereRaw('1 = 0');
        }

        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof BelongsTo) {
            throw new \InvalidArgumentException("Relationship '{$relation}' must be a BelongsTo relationship");
        }

        $foreignKey = $relationInstance->getForeignKeyName();

        return count($ids) === 1
            ? $this->where($foreignKey, $ids[0])
            : $this->whereIn($foreignKey, $ids);
    }

    /**
     * OR version of optimized whereHas using EXISTS.
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @return $this
     */
    private function orWhereHasExists(string $relation, ?callable $callback = null): self
    {
        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relation}' is not a valid relationship");
        }

        // Get the relationship's query builder
        $relationQuery = $relationInstance->getQuery();

        // Apply callback constraints if provided
        // IMPORTANT: Pass relationship instance instead of query builder
        // This allows relationship-specific __call() methods to auto-qualify columns
        if ($callback !== null) {
            $callback($relationInstance);
        }

        // Build EXISTS subquery
        $existsResult = $this->buildExistsSubquery($relationInstance, $table, $relationQuery);
        $existsSql = $existsResult['sql'];
        $existsBindings = $existsResult['bindings'];

        // Add OR EXISTS clause - much faster than COUNT(*)
        $this->orWhereRaw("EXISTS ({$existsSql})", $existsBindings);

        return $this;
    }

    /**
     * OR version of whereHas with count comparison.
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @param string $operator Comparison operator
     * @param int $count Count threshold
     * @return $this
     */
    private function orWhereHasWithCount(string $relation, ?callable $callback = null, string $operator = '>=', int $count = 1): self
    {
        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relation}' is not a valid relationship");
        }

        // Get the relationship's query builder
        $relationQuery = $relationInstance->getQuery();

        // Apply callback constraints if provided
        // IMPORTANT: Pass relationship instance instead of query builder
        // This allows relationship-specific __call() methods to auto-qualify columns
        if ($callback !== null) {
            $callback($relationInstance);
        }

        // Build COUNT subquery (only when count comparison is needed)
        $countSubquery = $this->buildCountSubquery($relationInstance, $table, $relationQuery);

        // Add OR count comparison clause
        $this->orWhereRaw("({$countSubquery}) {$operator} ?", [$count]);

        return $this;
    }

    /**
     * Build subquery for pivot relationships (BelongsToMany, MorphToMany).
     *
     * @param \Toporia\Framework\Database\Contracts\RelationInterface $relation Relation instance
     * @param string $parentTable Parent table name
     * @param \Toporia\Framework\Database\Query\QueryBuilder $relationQuery Relation query
     * @return string Subquery SQL
     */
    protected function buildPivotWhereHasSubquery($relation, string $parentTable, $relationQuery): string
    {
        if ($relation instanceof BelongsToMany) {
            // Get pivot table and keys for BelongsToMany
            $pivotTable = $this->getRelationProperty($relation, 'pivotTable');
            $foreignPivotKey = $this->getRelationProperty($relation, 'foreignPivotKey');
            $relatedPivotKey = $this->getRelationProperty($relation, 'relatedPivotKey');
            $parentKey = $this->getRelationProperty($relation, 'parentKey');
            $relatedKey = $this->getRelationProperty($relation, 'relatedKey');

            // Get related table
            $relatedTable = $relationQuery->getTable();

            // Build subquery with proper JOIN
            // SELECT COUNT(*) FROM pivot_table
            // INNER JOIN related_table ON pivot_table.related_key = related_table.id
            // WHERE pivot_table.parent_key = parent_table.id
            $subquerySql = "SELECT COUNT(*) FROM {$pivotTable} " .
                "INNER JOIN {$relatedTable} ON {$pivotTable}.{$relatedPivotKey} = {$relatedTable}.{$relatedKey} " .
                "WHERE {$pivotTable}.{$foreignPivotKey} = {$parentTable}.{$parentKey}";
        } elseif ($relation instanceof MorphToMany) {
            // Optimized MorphToMany query matching Toporia's structure
            // Query starts from related table (tags) and joins pivot (taggables)
            // This is more efficient when filtering by related table columns
            $pivotTable = $this->getRelationProperty($relation, 'pivotTable');
            $morphType = $this->getRelationProperty($relation, 'morphType');
            $morphId = $this->getRelationProperty($relation, 'foreignKey');
            $relatedPivotKey = $this->getRelationProperty($relation, 'relatedPivotKey');
            $parentKey = $this->getRelationProperty($relation, 'localKey');
            $relatedKey = $this->getRelationProperty($relation, 'relatedKey');

            // Get related table and morph class
            $relatedTable = $relationQuery->getTable();
            $morphClass = $this->getMorphClassFromRelation($relation);

            // Toporia's structure: SELECT COUNT(*) FROM tags INNER JOIN taggables ON tags.id = taggables.tag_id
            // WHERE products.id = taggables.taggable_id AND taggables.taggable_type = ?
            // This is more efficient when filtering by tags because it can use tags table index first
            // Quote morph class value for safe SQL injection prevention
            $quotedMorphClass = $this->quoteValue($morphClass);
            $subquerySql = "SELECT COUNT(*) FROM {$relatedTable} " .
                "INNER JOIN {$pivotTable} ON {$relatedTable}.{$relatedKey} = {$pivotTable}.{$relatedPivotKey} " .
                "WHERE {$parentTable}.{$parentKey} = {$pivotTable}.{$morphId} " .
                "AND {$pivotTable}.{$morphType} = {$quotedMorphClass}";
        }

        return $subquerySql;
    }

    /**
     * Get a property value from a relation object using reflection.
     *
     * This is internal framework code that needs to access protected/private
     * properties of relation classes for query building.
     *
     * @param object $relation Relation instance
     * @param string $property Property name
     * @return mixed Property value
     */
    private function getRelationProperty(object $relation, string $property): mixed
    {
        return (new \ReflectionProperty($relation, $property))->getValue($relation);
    }

    /**
     * Get morph class from a polymorphic relation using reflection.
     *
     * This is internal framework code that needs to access protected methods
     * of relation classes for query building.
     *
     * @param object $relation MorphToMany or MorphedByMany relation instance
     * @return string Morph class name
     */
    private function getMorphClassFromRelation(object $relation): string
    {
        if (method_exists($relation, 'getMorphClass')) {
            $reflection = new \ReflectionMethod($relation, 'getMorphClass');
            $reflection->setAccessible(true);
            return $reflection->invoke($relation);
        }

        // Fallback to get_class if getMorphClass doesn't exist
        return get_class($relation->getParent());
    }

    /**
     * Build EXISTS subquery for relationships.
     *
     * @param \Toporia\Framework\Database\Contracts\RelationInterface $relation Relation instance
     * @param string $parentTable Parent table name
     * @param \Toporia\Framework\Database\Query\QueryBuilder $relationQuery Relation query
     * @return array<string, mixed> Array with 'sql' (string) and 'bindings' (array) keys
     */
    protected function buildExistsSubquery($relation, string $parentTable, $relationQuery): array
    {
        // Handle different relationship types
        if (
            $relation instanceof BelongsToMany ||
            $relation instanceof MorphToMany ||
            $relation instanceof MorphedByMany
        ) {
            return $this->buildPivotExistsSubquery($relation, $parentTable, $relationQuery);
        } elseif ($relation instanceof HasManyThrough) {
            // HasManyThrough requires JOIN with through table
            return $this->buildHasManyThroughExistsSubquery($relation, $parentTable, $relationQuery);
        } else {
            return $this->buildSimpleExistsSubquery($relation, $parentTable, $relationQuery);
        }
    }

    /**
     * Build EXISTS subquery for simple relationships (HasOne, HasMany, BelongsTo, etc.).
     *
     * @param \Toporia\Framework\Database\Contracts\RelationInterface $relation Relation instance
     * @param string $parentTable Parent table name
     * @param \Toporia\Framework\Database\Query\QueryBuilder $relationQuery Relation query
     * @return array<string, mixed> Array with 'sql' (string) and 'bindings' (array) keys
     */
    protected function buildSimpleExistsSubquery($relation, string $parentTable, $relationQuery): array
    {
        // Get foreign and local keys for simple relationships
        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey();

        // Get relation table
        $relationTable = $relationQuery->getTable();

        // Use alias for self-referencing relationships
        $relationAlias = $parentTable === $relationTable ? "{$relationTable}_relation" : $relationTable;

        // Build EXISTS subquery - SELECT 1 is faster than SELECT COUNT(*)
        $fromClause = $parentTable === $relationTable ? "{$relationTable} AS {$relationAlias}" : $relationTable;

        // Check if this is a polymorphic relationship (MorphOne or MorphMany)
        $isPolymorphic = $relation instanceof MorphOne
            || $relation instanceof MorphMany;

        // Collect bindings array
        $bindings = [];

        // For BelongsTo relationships, the foreign key is on the parent table and local key is on the related table
        // For HasOne/HasMany relationships, the foreign key is on the related table and local key is on the parent table
        if ($relation instanceof BelongsTo) {
            // BelongsTo: relatedTable.localKey = parentTable.foreignKey
            $subquerySql = "SELECT 1 FROM {$fromClause} WHERE {$relationAlias}.{$localKey} = {$parentTable}.{$foreignKey}";
        } else {
            // HasOne/HasMany/MorphOne/MorphMany: relatedTable.foreignKey = parentTable.localKey
            $subquerySql = "SELECT 1 FROM {$fromClause} WHERE {$relationAlias}.{$foreignKey} = {$parentTable}.{$localKey}";
        }

        // Add relation constraints (keep as bindings, don't inline)
        $relationSql = $relationQuery->toSql();
        $relationBindings = $relationQuery->getBindings();

        // Extract WHERE clause, handling nested subqueries properly
        $whereClause = $this->extractCompleteWhereClause($relationSql);

        // For polymorphic relationships, check if we need to add morph_type constraint
        // IMPORTANT: Only add morph_type constraint if callback doesn't already handle it
        // This fixes the bug where whereHasMorph inside whereHas callback gets conflicting constraints
        if ($isPolymorphic) {
            $morphType = $this->getRelationProperty($relation, 'morphType');
            $morphClass = $this->getMorphClassFromRelation($relation);

            // Check if the WHERE clause already contains the morph_type column
            // If callback uses whereHasMorph on the same morph relation, it will handle morph_type itself
            $morphTypePattern = preg_quote("{$relationAlias}.{$morphType}", '/');
            $hasMorphTypeInCallback = !empty($whereClause) && preg_match("/{$morphTypePattern}/", $whereClause);

            if (!$hasMorphTypeInCallback) {
                // Callback doesn't handle morph_type, so we add it
                $subquerySql .= " AND {$relationAlias}.{$morphType} = ?";
                $bindings[] = $morphClass;
            }
        }

        // Only add if WHERE clause is not empty
        if (!empty($whereClause)) {
            // Remove unnecessary parentheses and add relation constraints directly
            // Use bindings instead of inlining values for better plan cache optimization
            $subquerySql .= " AND {$whereClause}";
            $bindings = array_merge($bindings, $relationBindings);
        }

        // Add LIMIT 1 for maximum performance
        $subquerySql .= " LIMIT 1";

        return ['sql' => $subquerySql, 'bindings' => $bindings];
    }

    /**
     * Build EXISTS subquery for pivot relationships (BelongsToMany, MorphToMany).
     *
     * @param \Toporia\Framework\Database\Contracts\RelationInterface $relation Relation instance
     * @param string $parentTable Parent table name
     * @param \Toporia\Framework\Database\Query\QueryBuilder $relationQuery Relation query
     * @return array<string, mixed> Array with 'sql' (string) and 'bindings' (array) keys
     */
    protected function buildPivotExistsSubquery($relation, string $parentTable, $relationQuery): array
    {
        // Collect bindings array
        $bindings = [];

        if ($relation instanceof BelongsToMany) {
            // Get pivot table and keys for BelongsToMany
            $pivotTable = $this->getRelationProperty($relation, 'pivotTable');
            $foreignPivotKey = $this->getRelationProperty($relation, 'foreignPivotKey');
            $relatedPivotKey = $this->getRelationProperty($relation, 'relatedPivotKey');
            $parentKey = $this->getRelationProperty($relation, 'parentKey');
            $relatedKey = $this->getRelationProperty($relation, 'relatedKey');

            // Get related table
            $relatedTable = $relationQuery->getTable();

            // Build EXISTS subquery with proper JOIN - SELECT 1 is faster
            $subquerySql = "SELECT 1 FROM {$pivotTable} " .
                "INNER JOIN {$relatedTable} ON {$pivotTable}.{$relatedPivotKey} = {$relatedTable}.{$relatedKey} " .
                "WHERE {$pivotTable}.{$foreignPivotKey} = {$parentTable}.{$parentKey}";
        } elseif ($relation instanceof MorphToMany) {
            // Optimized MorphToMany query matching Toporia's structure
            // Query starts from related table (tags) and joins pivot (taggables)
            // This is more efficient when filtering by related table columns
            $pivotTable = $this->getRelationProperty($relation, 'pivotTable');
            $morphType = $this->getRelationProperty($relation, 'morphType');
            $morphId = $this->getRelationProperty($relation, 'foreignKey');
            $relatedPivotKey = $this->getRelationProperty($relation, 'relatedPivotKey');
            $parentKey = $this->getRelationProperty($relation, 'localKey');
            $relatedKey = $this->getRelationProperty($relation, 'relatedKey');

            // Get related table and morph class
            $relatedTable = $relationQuery->getTable();
            $morphClass = $this->getMorphClassFromRelation($relation);

            // Toporia's structure: SELECT * FROM tags INNER JOIN taggables ON tags.id = taggables.tag_id
            // WHERE products.id = taggables.taggable_id AND taggables.taggable_type = ? AND tags.id = ?
            // This is more efficient when filtering by tags because it can use tags table index first
            // Use binding for morph class value instead of inline for better plan cache optimization
            $subquerySql = "SELECT 1 FROM {$relatedTable} " .
                "INNER JOIN {$pivotTable} ON {$relatedTable}.{$relatedKey} = {$pivotTable}.{$relatedPivotKey} " .
                "WHERE {$parentTable}.{$parentKey} = {$pivotTable}.{$morphId} " .
                "AND {$pivotTable}.{$morphType} = ?";
            $bindings[] = $morphClass;
        } elseif ($relation instanceof MorphedByMany) {
            // MorphedByMany is the inverse of MorphToMany
            // Example: Tag morphedByMany Posts - start from posts table, join pivot taggables
            $pivotTable = $this->getRelationProperty($relation, 'pivotTable');
            $morphType = $this->getRelationProperty($relation, 'morphType');
            $morphId = $this->getRelationProperty($relation, 'foreignKey');
            $parentPivotKey = $this->getRelationProperty($relation, 'parentPivotKey');
            $parentKey = $this->getRelationProperty($relation, 'localKey');
            $relatedKey = $this->getRelationProperty($relation, 'relatedKey');

            // Get related table - this is the morphable model table (posts, videos, etc.)
            $relatedTable = $relationQuery->getTable();

            // Start from related table (posts), join pivot (taggables)
            // SELECT 1 FROM posts INNER JOIN taggables ON posts.id = taggables.taggable_id
            // WHERE tags.id = taggables.tag_id AND taggables.taggable_type = 'Post'
            $subquerySql = "SELECT 1 FROM {$relatedTable} " .
                "INNER JOIN {$pivotTable} ON {$relatedTable}.{$relatedKey} = {$pivotTable}.{$morphId} " .
                "WHERE {$parentTable}.{$parentKey} = {$pivotTable}.{$parentPivotKey} " .
                "AND {$pivotTable}.{$morphType} = ?";

            // Get the morph class from the related model - use relation instance method
            $relatedClass = $relation->getRelatedClass();
            $bindings[] = $relatedClass;
        }

        // Add relation constraints (keep as bindings, don't inline)
        $relationSql = $relationQuery->toSql();
        $relationBindings = $relationQuery->getBindings();

        // Extract WHERE clause, handling nested subqueries properly
        $whereClause = $this->extractCompleteWhereClause($relationSql);

        // Only add if WHERE clause is not empty
        if (!empty($whereClause)) {
            // For MorphToMany and MorphedByMany, qualify common ambiguous columns
            // since both related table and pivot table may have columns with same names
            if ($relation instanceof MorphToMany || $relation instanceof MorphedByMany) {
                // List of common columns that typically exist in both tables
                // Users can still manually qualify other columns if needed
                $ambiguousColumns = ['id', 'created_at', 'updated_at'];

                foreach ($ambiguousColumns as $col) {
                    // Qualify unqualified column with related table name
                    // Only replace if column is not already qualified (no dot before it)
                    // Matches: "column IN (...)", "column = value", etc.
                    $whereClause = preg_replace(
                        '/(?<!\w\.)(\b' . preg_quote($col, '/') . '\b)\s+(IN|NOT\s+IN|=|>|<|>=|<=|!=|<>|BETWEEN|LIKE|NOT\s+LIKE|IS|IS\s+NOT)\s*/i',
                        "{$relatedTable}.$1 $2 ",
                        $whereClause
                    );
                }
            }

            // Remove unnecessary parentheses and add relation constraints directly
            // Use bindings instead of inlining values for better plan cache optimization
            $subquerySql .= " AND {$whereClause}";
            $bindings = array_merge($bindings, $relationBindings);
        }

        // Add LIMIT 1 for maximum performance
        $subquerySql .= " LIMIT 1";

        return ['sql' => $subquerySql, 'bindings' => $bindings];
    }

    /**
     * Build EXISTS subquery for HasManyThrough relationship.
     *
     * HasManyThrough requires JOIN with the through table (intermediate model).
     * Example: City hasMany Books through Authors
     * SQL: SELECT 1 FROM books INNER JOIN authors ON authors.id = books.author_id
     *      WHERE authors.city_id = cities.id
     *
     * @param \Toporia\Framework\Database\ORM\Relations\HasManyThrough $relation Relation instance
     * @param string $parentTable Parent table name (cities)
     * @param \Toporia\Framework\Database\Query\QueryBuilder $relationQuery Relation query
     * @return array<string, mixed> Array with 'sql' (string) and 'bindings' (array) keys
     */
    protected function buildHasManyThroughExistsSubquery($relation, string $parentTable, $relationQuery): array
    {
        // Get keys and tables from HasManyThrough relationship
        // firstKey: foreign key on through table (authors.city_id)
        // secondKey: foreign key on related table (books.author_id) - stored in $relation->getForeignKey()
        // localKey: local key on parent table (cities.id)
        // secondLocalKey: local key on through table (authors.id)
        $firstKey = $this->getRelationProperty($relation, 'firstKey');
        $secondKey = $relation->getForeignKey(); // foreign key on books table
        $localKey = $relation->getLocalKey();
        $secondLocalKey = $this->getRelationProperty($relation, 'secondLocalKey');

        // Get table names
        // relatedTable: final table (books)
        // throughTable: intermediate table (authors)
        $relatedTable = $relationQuery->getTable();
        $throughTable = $this->getRelationProperty($relation, 'throughClass')::getTableName();

        // Build EXISTS subquery with JOIN to through table
        // SELECT 1 FROM books INNER JOIN authors ON authors.id = books.author_id
        // WHERE authors.city_id = cities.id
        $subquerySql = "SELECT 1 FROM {$relatedTable} " .
            "INNER JOIN {$throughTable} ON {$throughTable}.{$secondLocalKey} = {$relatedTable}.{$secondKey} " .
            "WHERE {$throughTable}.{$firstKey} = {$parentTable}.{$localKey}";

        // Collect bindings
        $bindings = [];

        // Add relation constraints from callback
        $relationSql = $relationQuery->toSql();
        $relationBindings = $relationQuery->getBindings();

        // Extract WHERE clause from relation query
        $whereClause = $this->extractCompleteWhereClause($relationSql);

        // Add WHERE constraints if present
        if (!empty($whereClause)) {
            // Qualify unqualified column names with related table to avoid ambiguity
            // Pattern: (?<!\w\.) means "not preceded by word_char + dot"
            $whereClause = preg_replace_callback(
                '/(?<!\w\.)(\b\w+)\s+(=|!=|<>|>|<|>=|<=|LIKE|NOT\s+LIKE|IN|NOT\s+IN|IS|IS\s+NOT)\s+/i',
                function ($matches) use ($relatedTable) {
                    $column = $matches[1];
                    $operator = $matches[2];
                    // Skip SQL keywords
                    $keywords = ['AND', 'OR', 'NOT', 'NULL', 'TRUE', 'FALSE', 'BETWEEN'];
                    if (in_array(strtoupper($column), $keywords)) {
                        return $matches[0];
                    }
                    return "{$relatedTable}.{$column} {$operator} ";
                },
                $whereClause
            );

            $subquerySql .= " AND {$whereClause}";
            $bindings = array_merge($bindings, $relationBindings);
        }

        // Add LIMIT 1 for maximum performance
        $subquerySql .= " LIMIT 1";

        return ['sql' => $subquerySql, 'bindings' => $bindings];
    }

    /**
     * Extract complete WHERE clause from SQL, handling nested subqueries properly.
     *
     * This method correctly handles nested subqueries like IN (SELECT ...) by
     * counting parentheses to find the complete WHERE clause.
     *
     * @param string $sql The SQL query string
     * @return string The extracted WHERE clause, or empty string if not found
     */
    protected function extractCompleteWhereClause(string $sql): string
    {
        // Find WHERE keyword
        if (!preg_match('/\bWHERE\s+/i', $sql, $whereMatch, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $whereStart = $whereMatch[0][1] + strlen($whereMatch[0][0]);
        $afterWhere = substr($sql, $whereStart);

        // Find the end of WHERE clause by looking for ORDER BY, LIMIT, or end of string
        // But we need to handle nested subqueries with parentheses
        $parenCount = 0;
        $inString = false;
        $stringChar = null;
        $length = strlen($afterWhere);
        $endPos = $length;

        for ($i = 0; $i < $length; $i++) {
            $char = $afterWhere[$i];
            $nextChar = $i + 1 < $length ? $afterWhere[$i + 1] : '';

            // Handle string literals
            if (!$inString && ($char === "'" || $char === '"')) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar && $nextChar !== $stringChar) {
                $inString = false;
                $stringChar = null;
            } elseif ($inString && $char === $stringChar && $nextChar === $stringChar) {
                // Escaped quote
                $i++;
                continue;
            }

            if ($inString) {
                continue;
            }

            // Count parentheses for nested subqueries
            if ($char === '(') {
                $parenCount++;
            } elseif ($char === ')') {
                $parenCount--;
            }

            // Check for ORDER BY or LIMIT (only when parentheses are balanced)
            if ($parenCount === 0) {
                // Check for ORDER BY (case-insensitive, word boundary aware)
                $remaining = substr($afterWhere, $i);
                if (preg_match('/^\s+ORDER\s+BY\b/i', $remaining, $orderMatch)) {
                    $endPos = $i;
                    break;
                }
                // Check for LIMIT (case-insensitive, word boundary aware)
                if (preg_match('/^\s+LIMIT\s+\d+/i', $remaining, $limitMatch)) {
                    $endPos = $i;
                    break;
                }
            }
        }

        $whereClause = substr($afterWhere, 0, $endPos);
        return trim($whereClause);
    }

    /**
     * Build COUNT subquery (only when count comparison is needed).
     *
     * @param \Toporia\Framework\Database\Contracts\RelationInterface $relation Relation instance
     * @param string $parentTable Parent table name
     * @param \Toporia\Framework\Database\Query\QueryBuilder $relationQuery Relation query
     * @return string COUNT subquery SQL
     */
    protected function buildCountSubquery($relation, string $parentTable, $relationQuery): string
    {
        // Handle different relationship types
        if (
            $relation instanceof BelongsToMany ||
            $relation instanceof MorphToMany
        ) {
            return $this->buildPivotWhereHasSubquery($relation, $parentTable, $relationQuery);
        } else {
            return $this->buildSimpleWhereHasSubquery($relation, $parentTable, $relationQuery);
        }
    }

    /**
     * Build subquery for simple relationships (HasOne, HasMany, BelongsTo, etc.).
     *
     * @param \Toporia\Framework\Database\Contracts\RelationInterface $relation Relation instance
     * @param string $parentTable Parent table name
     * @param \Toporia\Framework\Database\Query\QueryBuilder $relationQuery Relation query
     * @return string Subquery SQL
     */
    protected function buildSimpleWhereHasSubquery($relation, string $parentTable, $relationQuery): string
    {
        // Get foreign and local keys for simple relationships
        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey();

        // Get relation table
        $relationTable = $relationQuery->getTable();

        // Use alias for self-referencing relationships
        $relationAlias = $parentTable === $relationTable ? "{$relationTable}_relation" : $relationTable;

        // Build COUNT subquery (only used when count comparison is needed)
        $fromClause = $parentTable === $relationTable ? "{$relationTable} AS {$relationAlias}" : $relationTable;

        // For BelongsTo relationships, the foreign key is on the parent table and local key is on the related table
        // For HasOne/HasMany relationships, the foreign key is on the related table and local key is on the parent table
        if ($relation instanceof BelongsTo) {
            // BelongsTo: relatedTable.localKey = parentTable.foreignKey
            $subquerySql = "SELECT COUNT(*) FROM {$fromClause} WHERE {$relationAlias}.{$localKey} = {$parentTable}.{$foreignKey}";
        } else {
            // HasOne/HasMany: relatedTable.foreignKey = parentTable.localKey
            $subquerySql = "SELECT COUNT(*) FROM {$fromClause} WHERE {$relationAlias}.{$foreignKey} = {$parentTable}.{$localKey}";
        }

        return $subquerySql;
    }

    /**
     * Eager load relationships.
     *
     * Supports multiple syntaxes:
     * - with('relation')
     * - with(['relation'])
     * - with(['relation' => callback])
     * - with('relation:column1,column2')
     *
     * Clean Architecture & SOLID:
     * - Single Responsibility: Only configures eager loading
     * - Open/Closed: Extensible via callbacks
     * - Dependency Inversion: Works with any RelationInterface
     *
     * @param string|array|callable ...$relations Relationship specifications
     * @return $this
     *
     * @example
     * // Basic eager loading
     * $query->with('childrens')->get();
     *
     * // With column selection
     * $query->with('childrens:id,title,price')->get();
     *
     * // With callback constraints
     * $query->with(['childrens' => function($q) {
     *     $q->where('is_active', 1);
     * }])->get();
     *
     * // Multiple relationships
     * $query->with(['childrens', 'category'])->get();
     */
    public function with(string|array|callable ...$relations): self
    {
        // Delegate to Model's static method for normalization
        /** @var callable $normalizeMethod */
        $normalizeMethod = [$this->modelClass, 'normalizeWithRelations'];
        $normalized = $normalizeMethod($relations);
        // Merge with existing eager load instead of replacing
        $this->setEagerLoad(array_merge($this->eagerLoad, $normalized));

        return $this;
    }

    /**
     * Add a subselect count of a relationship to the query.
     *
     * Optimized implementation:
     * - Single query with subselect instead of separate query
     * - Automatically optimized by database engine
     *
     * Supports callbacks like with():
     * - withCount('reviews') - count all
     * - withCount(['reviews' => fn($q) => $q->where('rating', '>=', 4)]) - count with constraints
     *
     * @param string|array $relations Relationship name(s) or associative array with callbacks
     * @return $this
     *
     * @example
     * // Get products with review count
     * $products = ProductModel::withCount('reviews')->get();
     * // Access: $product->reviews_count
     *
     * // Multiple relationships
     * $products = ProductModel::withCount(['reviews', 'orders'])->get();
     *
     * // With callback constraints
     * $products = ProductModel::withCount(['reviews' => function($q) {
     *     $q->where('rating', '>=', 4);
     * }])->get();
     * // Access: $product->reviews_count (only counts reviews with rating >= 4)
     */
    public function withCount(string|array $relations): self
    {
        // Convert string to array
        if (is_string($relations)) {
            $relations = [$relations];
        }

        foreach ($relations as $key => $value) {
            // Case 1: 'relation' => callback or 'relation as alias' => callback
            if (is_string($key) && is_callable($value)) {
                $this->addRelationCountSelect($key, $value);
            }
            // Case 2: numeric key with string value (no callback)
            // Support: 'relation' or 'relation as alias'
            elseif (is_int($key) && is_string($value)) {
                $this->addRelationCountSelect($value, null);
            }
        }

        return $this;
    }

    /**
     * Filter models that DON'T have a related model matching the given constraints.
     *
     * This is the inverse of whereHas() - it finds records that lack the specified relationship.
     * Uses NOT EXISTS subquery for optimal performance.
     *
     * Clean Architecture & SOLID:
     * - Single Responsibility: Only adds WHERE NOT EXISTS clause
     * - Open/Closed: Extensible via callback
     * - Dependency Inversion: Works with any RelationInterface
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @param string $operator Comparison operator (<, =, etc.)
     * @param int $count Maximum count (default: 1 means "has less than one")
     * @return $this
     *
     * @example
     * // Products that have no reviews
     * ProductModel::whereDoesntHave('reviews')->get();
     *
     * // Products without high-rated reviews (rating >= 4)
     * ProductModel::whereDoesntHave('reviews', function($query) {
     *     $query->where('rating', '>=', 4);
     * })->get();
     *
     * // Products with less than 5 reviews
     * ProductModel::whereDoesntHave('reviews', null, '<', 5)->get();
     */
    public function whereDoesntHave(string $relation, ?callable $callback = null, string $operator = '<', int $count = 1): self
    {
        // For count-based queries (count != 1), use the count approach
        if ($count !== 1 || $operator !== '<') {
            return $this->whereDoesntHaveWithCount($relation, $callback, $operator, $count);
        }

        // For simple existence check, use optimized NOT EXISTS
        return $this->whereDoesntHaveExists($relation, $callback);
    }

    /**
     * Optimized whereDoesntHave using NOT EXISTS (fastest approach).
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @return $this
     */
    protected function whereDoesntHaveExists(string $relation, ?callable $callback = null): self
    {
        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relation}' is not a valid relationship");
        }

        // Get the relationship's query builder
        $relationQuery = $relationInstance->getQuery();

        // Apply callback constraints if provided
        // IMPORTANT: Pass relationship instance instead of query builder
        // This allows relationship-specific __call() methods to auto-qualify columns
        if ($callback !== null) {
            $callback($relationInstance);
        }

        // Build NOT EXISTS subquery
        $existsResult = $this->buildExistsSubquery($relationInstance, $table, $relationQuery);
        $existsSql = $existsResult['sql'];
        $existsBindings = $existsResult['bindings'];

        // Add NOT EXISTS clause - much faster than COUNT(*)
        $this->whereRaw("NOT EXISTS ({$existsSql})", $existsBindings);

        return $this;
    }

    /**
     * whereDoesntHave with count comparison (for count != 1 cases).
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @param string $operator Comparison operator
     * @param int $count Count threshold
     * @return $this
     */
    private function whereDoesntHaveWithCount(string $relation, ?callable $callback = null, string $operator = '<', int $count = 1): self
    {
        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relation}' is not a valid relationship");
        }

        // Get the relationship's query builder
        $relationQuery = $relationInstance->getQuery();

        // Apply callback constraints if provided
        // IMPORTANT: Pass relationship instance instead of query builder
        // This allows relationship-specific __call() methods to auto-qualify columns
        if ($callback !== null) {
            $callback($relationInstance);
        }

        // Build COUNT subquery (only when count comparison is needed)
        $countSubquery = $this->buildCountSubquery($relationInstance, $table, $relationQuery);

        // Add count comparison clause
        $this->whereRaw("({$countSubquery}) {$operator} ?", [$count]);

        return $this;
    }

    /**
     * OR version of whereDoesntHave.
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @param string $operator Comparison operator (<, =, etc.)
     * @param int $count Maximum count (default: 1)
     * @return $this
     */
    public function orWhereDoesntHave(string $relation, ?callable $callback = null, string $operator = '<', int $count = 1): self
    {
        // For count-based queries (count != 1), use the count approach
        if ($count !== 1 || $operator !== '<') {
            return $this->orWhereDoesntHaveWithCount($relation, $callback, $operator, $count);
        }

        // For simple existence check, use optimized OR NOT EXISTS
        return $this->orWhereDoesntHaveExists($relation, $callback);
    }

    /**
     * OR version of optimized whereDoesntHave using NOT EXISTS.
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @return $this
     */
    private function orWhereDoesntHaveExists(string $relation, ?callable $callback = null): self
    {
        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relation}' is not a valid relationship");
        }

        // Get the relationship's query builder
        $relationQuery = $relationInstance->getQuery();

        // Apply callback constraints if provided
        // IMPORTANT: Pass relationship instance instead of query builder
        // This allows relationship-specific __call() methods to auto-qualify columns
        if ($callback !== null) {
            $callback($relationInstance);
        }

        // Build NOT EXISTS subquery
        $existsResult = $this->buildExistsSubquery($relationInstance, $table, $relationQuery);
        $existsSql = $existsResult['sql'];
        $existsBindings = $existsResult['bindings'];

        // Add OR NOT EXISTS clause - much faster than COUNT(*)
        $this->orWhereRaw("NOT EXISTS ({$existsSql})", $existsBindings);

        return $this;
    }

    /**
     * OR version of whereDoesntHave with count comparison.
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @param string $operator Comparison operator
     * @param int $count Count threshold
     * @return $this
     */
    private function orWhereDoesntHaveWithCount(string $relation, ?callable $callback = null, string $operator = '<', int $count = 1): self
    {
        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relation}' is not a valid relationship");
        }

        // Get the relationship's query builder
        $relationQuery = $relationInstance->getQuery();

        // Apply callback constraints if provided
        // IMPORTANT: Pass relationship instance instead of query builder
        // This allows relationship-specific __call() methods to auto-qualify columns
        if ($callback !== null) {
            $callback($relationInstance);
        }

        // Build COUNT subquery (only when count comparison is needed)
        $countSubquery = $this->buildCountSubquery($relationInstance, $table, $relationQuery);

        // Add OR count comparison clause
        $this->orWhereRaw("({$countSubquery}) {$operator} ?", [$count]);

        return $this;
    }

    /**
     * Filter models that don't have nested relationships.
     *
     * Supports dot notation for nested relationships like 'posts.comments'.
     * This is a Toporia exclusive feature - exclusive feature.
     * Uses optimized EXISTS/NOT EXISTS for maximum performance.
     *
     * @param string $relation Nested relationship using dot notation (e.g., 'posts.comments')
     * @param callable|null $callback Optional callback to constrain the final relationship
     * @return $this
     *
     * @example
     * // Users without posts that have comments
     * UserModel::whereDoesntHaveNested('posts.comments')->get();
     *
     * // Categories without products that have high-rated reviews
     * CategoryModel::whereDoesntHaveNested('products.reviews', function($query) {
     *     $query->where('rating', '>=', 4);
     * })->get();
     */
    public function whereDoesntHaveNested(string $relation, ?callable $callback = null): self
    {
        $relations = explode('.', $relation);
        $finalRelation = array_pop($relations);

        // Build nested query from inside out using EXISTS for optimal performance
        $nestedCallback = $callback;

        // Work backwards through the relationship chain
        for ($i = count($relations) - 1; $i >= 0; $i--) {
            $currentRelation = $relations[$i];
            $previousCallback = $nestedCallback;

            $nestedCallback = function ($query) use ($currentRelation, $previousCallback) {
                // Use standard whereDoesntHave which will automatically use EXISTS for simple cases
                $query->whereDoesntHave($currentRelation, $previousCallback);
            };
        }

        // Use EXISTS-based whereDoesntHave for the final relationship
        return $this->whereDoesntHave($finalRelation, $nestedCallback);
    }

    /**
     * Filter models that don't have relationships with specific IDs.
     *
     * This is a Toporia exclusive feature for ID-based filtering.
     *
     * @param string $relation Relationship method name
     * @param array $ids Array of IDs to exclude
     * @param string $column Column to check IDs against (default: 'id')
     * @return $this
     *
     * @example
     * // Products without reviews from specific users
     * ProductModel::whereDoesntHaveIn('reviews', [1, 2, 3, 4, 5], 'user_id')->get();
     *
     * // Users without specific roles
     * UserModel::whereDoesntHaveIn('roles', [1, 2, 3])->get();
     */
    public function whereDoesntHaveIn(string $relation, array $ids, string $column = 'id'): self
    {
        if (empty($ids)) {
            return $this;
        }

        return $this->whereDoesntHave($relation, function ($query) use ($ids, $column) {
            $query->whereIn($column, $ids);
        });
    }

    /**
     * Filter models that don't have relationships within a date range.
     *
     * This is a Toporia exclusive feature for date-based filtering.
     *
     * @param string $relation Relationship method name
     * @param string $dateColumn Date column to check
     * @param string|\DateTime $startDate Start date (inclusive)
     * @param string|\DateTime|null $endDate End date (inclusive, optional)
     * @return $this
     *
     * @example
     * // Users without orders in the last 30 days
     * UserModel::whereDoesntHaveInDateRange('orders', 'created_at', now()->subDays(30))->get();
     *
     * // Products without reviews this year
     * ProductModel::whereDoesntHaveInDateRange('reviews', 'created_at', '2024-01-01', '2024-12-31')->get();
     */
    public function whereDoesntHaveInDateRange(string $relation, string $dateColumn, string|\DateTime $startDate, string|\DateTime|null $endDate = null): self
    {
        return $this->whereDoesntHave($relation, function ($query) use ($dateColumn, $startDate, $endDate) {
            if ($endDate !== null) {
                $query->whereBetween($dateColumn, [$startDate, $endDate]);
            } else {
                $query->where($dateColumn, '>=', $startDate);
            }
        });
    }

    /**
     * Filter models that don't have relationships with specific JSON attribute values.
     *
     * This is a Toporia exclusive feature for JSON-based filtering.
     *
     * @param string $relation Relationship method name
     * @param string $jsonColumn JSON column name
     * @param string $jsonPath JSON path (e.g., '$.source')
     * @param mixed $value Value to match
     * @return $this
     *
     * @example
     * // Products without mobile reviews
     * ProductModel::whereDoesntHaveJsonAttribute('reviews', 'metadata', '$.source', 'mobile')->get();
     *
     * // Users without email notifications enabled
     * UserModel::whereDoesntHaveJsonAttribute('preferences', 'settings', '$.notifications.email', true)->get();
     */
    public function whereDoesntHaveJsonAttribute(string $relation, string $jsonColumn, string $jsonPath, mixed $value): self
    {
        return $this->whereDoesntHave($relation, function ($query) use ($jsonColumn, $jsonPath, $value) {
            $query->whereJsonContains($jsonColumn . '->' . $jsonPath, $value);
        });
    }

    /**
     * Add a subselect sum of a relationship column to the query.
     *
     * Supports callbacks for filtering:
     * - withSum('orders', 'total') - sum all
     * - withSum('orders', 'total', fn($q) => $q->where('status', 'completed')) - sum with constraints
     *
     * @param string $relation Relationship name
     * @param string $column Column to sum
     * @param callable|null $callback Optional callback to constrain the sum
     * @return $this
     *
     * @example
     * // Get users with total order amount
     * $users = UserModel::withSum('orders', 'total')->get();
     * // Access: $user->orders_sum_total
     *
     * // Sum only completed orders
     * $users = UserModel::withSum('orders', 'total', function($q) {
     *     $q->where('status', 'completed');
     * })->get();
     */
    public function withSum(string $relation, string $column, ?callable $callback = null): self
    {
        return $this->addRelationAggregateSelect($relation, $column, 'SUM', $callback);
    }

    /**
     * Add a subselect average of a relationship column to the query.
     *
     * @param string $relation Relationship name
     * @param string $column Column to average
     * @param callable|null $callback Optional callback to constrain the average
     * @return $this
     *
     * @example
     * // Average rating of all reviews
     * $products = ProductModel::withAvg('reviews', 'rating')->get();
     *
     * // Average rating of verified reviews only
     * $products = ProductModel::withAvg('reviews', 'rating', function($q) {
     *     $q->where('verified', true);
     * })->get();
     */
    public function withAvg(string $relation, string $column, ?callable $callback = null): self
    {
        return $this->addRelationAggregateSelect($relation, $column, 'AVG', $callback);
    }

    /**
     * Add a subselect minimum of a relationship column to the query.
     *
     * @param string $relation Relationship name
     * @param string $column Column to find minimum
     * @param callable|null $callback Optional callback to constrain
     * @return $this
     */
    public function withMin(string $relation, string $column, ?callable $callback = null): self
    {
        return $this->addRelationAggregateSelect($relation, $column, 'MIN', $callback);
    }

    /**
     * Add a subselect maximum of a relationship column to the query.
     *
     * @param string $relation Relationship name
     * @param string $column Column to find maximum
     * @param callable|null $callback Optional callback to constrain
     * @return $this
     */
    public function withMax(string $relation, string $column, ?callable $callback = null): self
    {
        return $this->addRelationAggregateSelect($relation, $column, 'MAX', $callback);
    }

    // =========================================================================
    // MORPH-TO AGGREGATE METHODS
    // =========================================================================

    /**
     * Add a subselect count of a MorphTo relationship to the query.
     *
     * Unlike regular withCount(), this handles polymorphic inverse relationships
     * where the related model type varies per row (stored in morph_type column).
     *
     * Uses CASE WHEN to build a unified count across different morph types.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array $types Morph type class name(s) to count, or '*' for all types
     * @param callable|null $callback Optional callback to constrain each morph type query
     * @return $this
     *
     * @example
     * ```php
     * // Count for specific types
     * Comment::withCountMorph('commentable', [Post::class, Video::class])->get();
     * // Access: $comment->commentable_count (returns 1 if exists, 0 if not)
     *
     * // With constraints per type
     * Comment::withCountMorph('commentable', [Post::class], function($query, $type) {
     *     if ($type === Post::class) {
     *         $query->where('published', true);
     *     }
     * })->get();
     * ```
     */
    public function withCountMorph(string $relation, string|array $types, ?callable $callback = null): self
    {
        return $this->addMorphToAggregateSelect($relation, $types, null, 'COUNT', $callback);
    }

    /**
     * Add a subselect sum of a MorphTo relationship column to the query.
     *
     * Sums a column from the polymorphic parent models.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array $types Morph type class name(s)
     * @param string $column Column to sum from the morph parent
     * @param callable|null $callback Optional callback to constrain each morph type query
     * @return $this
     *
     * @example
     * ```php
     * // Sum view_count from posts and videos that images belong to
     * Image::withSumMorph('imageable', [Post::class, Video::class], 'view_count')->get();
     * // Access: $image->imageable_sum_view_count
     * ```
     */
    public function withSumMorph(string $relation, string|array $types, string $column, ?callable $callback = null): self
    {
        return $this->addMorphToAggregateSelect($relation, $types, $column, 'SUM', $callback);
    }

    /**
     * Add a subselect average of a MorphTo relationship column to the query.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array $types Morph type class name(s)
     * @param string $column Column to average from the morph parent
     * @param callable|null $callback Optional callback to constrain each morph type query
     * @return $this
     */
    public function withAvgMorph(string $relation, string|array $types, string $column, ?callable $callback = null): self
    {
        return $this->addMorphToAggregateSelect($relation, $types, $column, 'AVG', $callback);
    }

    /**
     * Add a subselect minimum of a MorphTo relationship column to the query.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array $types Morph type class name(s)
     * @param string $column Column to find minimum from the morph parent
     * @param callable|null $callback Optional callback to constrain each morph type query
     * @return $this
     */
    public function withMinMorph(string $relation, string|array $types, string $column, ?callable $callback = null): self
    {
        return $this->addMorphToAggregateSelect($relation, $types, $column, 'MIN', $callback);
    }

    /**
     * Add a subselect maximum of a MorphTo relationship column to the query.
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array $types Morph type class name(s)
     * @param string $column Column to find maximum from the morph parent
     * @param callable|null $callback Optional callback to constrain each morph type query
     * @return $this
     */
    public function withMaxMorph(string $relation, string|array $types, string $column, ?callable $callback = null): self
    {
        return $this->addMorphToAggregateSelect($relation, $types, $column, 'MAX', $callback);
    }

    /**
     * Add a MorphTo aggregate subselect to the query.
     *
     * This handles the complexity of polymorphic inverse relationships where
     * different rows may reference different tables.
     *
     * Uses CASE WHEN statements to build type-specific subqueries:
     * CASE
     *   WHEN morph_type = 'post' THEN (SELECT column FROM posts WHERE posts.id = table.morph_id)
     *   WHEN morph_type = 'video' THEN (SELECT column FROM videos WHERE videos.id = table.morph_id)
     *   ELSE NULL
     * END
     *
     * @param string $relation MorphTo relationship method name
     * @param string|array $types Morph type class name(s)
     * @param string|null $column Column to aggregate (null for COUNT)
     * @param string $function Aggregate function (COUNT, SUM, AVG, MIN, MAX)
     * @param callable|null $callback Optional callback to constrain each morph type query
     * @return $this
     */
    private function addMorphToAggregateSelect(
        string $relation,
        string|array $types,
        ?string $column,
        string $function,
        ?callable $callback = null
    ): self {
        // Normalize types to array
        $types = is_array($types) ? $types : [$types];

        // Get table name from model
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        // Create a dummy model to get the relationship
        $model = new $this->modelClass([]);

        if (!method_exists($model, $relation)) {
            throw new \InvalidArgumentException("Relationship '{$relation}' does not exist on model {$this->modelClass}");
        }

        $relationInstance = $model->$relation();

        // Must be a MorphTo relationship
        if (!$relationInstance instanceof MorphTo) {
            throw new \InvalidArgumentException(
                "Relationship '{$relation}' must be a MorphTo relationship for withCountMorph/withSumMorph"
            );
        }

        // Get morph type and id columns
        $morphType = $this->getRelationProperty($relationInstance, 'morphType');
        $morphId = $this->getRelationProperty($relationInstance, 'foreignKey');

        // Handle wildcard '*' - get all possible types from the database
        if (count($types) === 1 && $types[0] === '*') {
            $distinctTypes = $this->getConnection()->select(
                "SELECT DISTINCT {$morphType} FROM {$table} WHERE {$morphType} IS NOT NULL"
            );
            $types = array_column($distinctTypes, $morphType);

            if (empty($types)) {
                // No types found, add NULL column
                $columnAlias = $function === 'COUNT'
                    ? "{$relation}_count"
                    : "{$relation}_" . strtolower($function) . "_{$column}";

                $columns = $this->getColumns();
                if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
                    $this->select("{$table}.*");
                }
                $this->selectRaw("NULL AS {$columnAlias}");
                return $this;
            }
        }

        // Filter to only valid classes
        $validTypes = array_filter($types, fn($type) => class_exists($type));

        if (empty($validTypes)) {
            // No valid types, add NULL column
            $columnAlias = $function === 'COUNT'
                ? "{$relation}_count"
                : "{$relation}_" . strtolower($function) . "_{$column}";

            $columns = $this->getColumns();
            if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
                $this->select("{$table}.*");
            }
            $this->selectRaw("NULL AS {$columnAlias}");
            return $this;
        }

        // Build CASE WHEN statement for each morph type
        $caseParts = [];

        foreach ($validTypes as $type) {
            /** @var callable $getRelatedTable */
            $getRelatedTable = [$type, 'getTableName'];
            $relatedTable = $getRelatedTable();

            /** @var callable $getPrimaryKey */
            $getPrimaryKey = [$type, 'getPrimaryKey'];
            $relatedKey = $getPrimaryKey();

            // Resolve morph type alias using global morph map
            $morphTypeValue = Relation::getMorphAlias($type);

            // Build the aggregate expression
            if ($function === 'COUNT') {
                // For COUNT, we count 1 if exists
                $selectExpr = '1';
            } else {
                // For other aggregates, select the column
                $selectExpr = "{$relatedTable}.{$column}";
            }

            // Build WHERE conditions
            $whereConditions = [
                "{$relatedTable}.{$relatedKey} = {$table}.{$morphId}",
            ];

            // Apply callback constraints if provided
            $callbackSql = '';
            if ($callback !== null) {
                $subqueryBuilder = $type::query();
                $callback($subqueryBuilder, $type);

                // Extract WHERE clause from callback query
                $callbackWheres = $subqueryBuilder->getWheres();
                if (!empty($callbackWheres)) {
                    $callbackQuerySql = $subqueryBuilder->toSql();
                    $callbackBindings = $subqueryBuilder->getBindings();

                    if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER|\s+LIMIT|\s+GROUP|\s*$)/is', $callbackQuerySql, $matches)) {
                        $callbackSql = $matches[1];

                        // Replace placeholders with actual values
                        foreach ($callbackBindings as $binding) {
                            $quoted = $this->quoteValue($binding);
                            $callbackSql = preg_replace('/\?/', $quoted, $callbackSql, 1);
                        }
                    }
                }
            }

            // Apply soft delete scope if related model uses it
            if (method_exists($type, 'usesSoftDeletes') && $type::usesSoftDeletes()) {
                $deletedAtColumn = method_exists($type, 'getDeletedAtColumn')
                    ? $type::getDeletedAtColumn()
                    : 'deleted_at';
                $whereConditions[] = "{$relatedTable}.{$deletedAtColumn} IS NULL";
            }

            // Build complete WHERE clause
            $whereClause = implode(' AND ', $whereConditions);
            if (!empty($callbackSql)) {
                $whereClause .= ' AND (' . $callbackSql . ')';
            }

            // Build subquery for this type
            $subquery = "SELECT {$selectExpr} FROM {$relatedTable} WHERE {$whereClause}";

            // Quote morph type value
            $quotedMorphType = $this->quoteValue($morphTypeValue);

            // Build CASE WHEN part
            $caseParts[] = "WHEN {$table}.{$morphType} = {$quotedMorphType} THEN ({$subquery})";
        }

        // Build complete CASE expression
        $caseExpression = "CASE " . implode(' ', $caseParts) . " ELSE NULL END";

        // Determine column alias
        $columnAlias = $function === 'COUNT'
            ? "{$relation}_count"
            : "{$relation}_" . strtolower($function) . "_{$column}";

        // Wrap with aggregate function if needed
        // For MorphTo, each row has at most one parent, so COUNT returns 0 or 1
        // For other aggregates, we just return the value directly
        if ($function === 'COUNT') {
            // COALESCE to return 0 instead of NULL when no match
            $finalExpression = "COALESCE(({$caseExpression}), 0)";
        } else {
            $finalExpression = $caseExpression;
        }

        // Ensure we select table.* along with the subquery (only once)
        $columns = $this->getColumns();
        if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
            $this->select("{$table}.*");
        }

        $this->selectRaw("{$finalExpression} AS {$columnAlias}");

        return $this;
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Add a relationship count subselect to the query.
     *
     * @param string $relation Relationship name
     * @param callable|null $callback Optional callback to constrain the count
     * @return void
     */
    private function addRelationCountSelect(string $relation, ?callable $callback = null): void
    {
        // Parse relation name and alias from format: 'relation' or 'relation as alias'
        $relationParts = preg_split('/\s+as\s+/i', trim($relation), 2);
        $relationName = $relationParts[0];
        $alias = isset($relationParts[1]) ? trim($relationParts[1]) : null;

        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        $model = new $this->modelClass([]);
        $relationInstance = $model->$relationName();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relationName}' is not a valid relationship");
        }

        $relationQuery = $relationInstance->getQuery();

        // Apply callback constraints if provided
        if ($callback !== null) {
            $callback($relationQuery);
        }

        // Ensure SoftDeletes is applied if relation model uses it
        // This is important for withCount/withSum to match standard behavior
        // OPTIMIZED: Use public getRelatedClass() method instead of reflection (O(1) vs O(n))
        $relatedClass = $relationInstance->getRelatedClass();

        if ($relatedClass !== '' && method_exists($relatedClass, 'usesSoftDeletes') && $relatedClass::usesSoftDeletes()) {
            $deletedAtColumn = method_exists($relatedClass, 'getDeletedAtColumn')
                ? $relatedClass::getDeletedAtColumn()
                : 'deleted_at';
            $relationTable = $relationQuery->getTable();
            $relationQuery->whereNull("{$relationTable}.{$deletedAtColumn}");
        }

        // Special handling for BelongsToMany relationships (many-to-many through pivot table)
        if ($relationInstance instanceof BelongsToMany) {
            $this->addBelongsToManyCountSelect($relationInstance, $relationName, $table, $relationQuery, $alias);
            return;
        }

        // Special handling for MorphToMany relationships (polymorphic many-to-many through pivot table)
        if ($relationInstance instanceof MorphToMany) {
            $this->addMorphToManyCountSelect($relationInstance, $relationName, $table, $relationQuery, $alias);
            return;
        }

        // Special handling for MorphedByMany relationships (inverse polymorphic many-to-many)
        if ($relationInstance instanceof MorphedByMany) {
            $this->addMorphedByManyCountSelect($relationInstance, $relationName, $table, $relationQuery, $alias);
            return;
        }

        // Special handling for HasManyThrough relationships (requires JOIN with through table)
        if ($relationInstance instanceof HasManyThrough) {
            $this->addHasManyThroughCountSelect($relationInstance, $relationName, $table, $relationQuery, $alias);
            return;
        }

        $foreignKey = $relationInstance->getForeignKey();
        $localKey = $relationInstance->getLocalKey();
        $relationTable = $relationQuery->getTable();

        // Use alias for self-referencing relationships (e.g., products.parent_id -> products.id)
        // This prevents ambiguity when parent and child tables are the same
        $relationAlias = $table === $relationTable ? "{$relationTable}_relation" : $relationTable;

        // Build subselect with proper aliasing
        // Example: (SELECT COUNT(*) FROM products AS products_relation WHERE products_relation.parent_id = products.id)
        $fromClause = $table === $relationTable ? "{$relationTable} AS {$relationAlias}" : $relationTable;
        $subquery = "SELECT COUNT(*) FROM {$fromClause} WHERE {$relationAlias}.{$foreignKey} = {$table}.{$localKey}";

        // Add relation query wheres to subquery
        // Important: We need to inject bindings directly into SQL because
        // selectRaw bindings are added to the end, but subquery bindings need to be
        // embedded within the subquery itself for correct ordering
        $relationSql = $relationQuery->toSql();
        if (preg_match('/WHERE (.+?)(?:ORDER BY|LIMIT|GROUP BY|HAVING|$)/s', $relationSql, $matches)) {
            $whereClause = $matches[1];

            // Replace placeholders with actual values (safely quoted)
            // Security: Use PDO::quote() instead of addslashes() to prevent SQL injection
            $relationBindings = $relationQuery->getBindings();
            $boundWhereClause = $whereClause;
            foreach ($relationBindings as $binding) {
                // Safely quote value using PDO::quote() (prevents SQL injection)
                $quoted = $this->quoteValue($binding);
                $boundWhereClause = preg_replace('/\?/', $quoted, $boundWhereClause, 1);
            }

            $subquery .= " AND ({$boundWhereClause})";
        }

        // Use alias if provided, otherwise use relation name
        $columnAlias = $alias !== null ? trim($alias) : "{$relationName}_count";

        // Ensure we select table.* along with the subquery (only once)
        $columns = $this->getColumns();
        if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
            $this->select("{$table}.*");
        }

        $this->selectRaw("({$subquery}) AS {$columnAlias}");
    }

    /**
     * Add count select for BelongsToMany relationships.
     *
     * For many-to-many relationships, we count records in the pivot table,
     * not the related table directly.
     *
     * @param BelongsToMany $relationInstance The BelongsToMany relation instance
     * @param string $relation The relation name
     * @param string $table The parent table name
     * @param QueryBuilder $relationQuery The relation query builder
     * @return void
     */
    private function addBelongsToManyCountSelect(
        BelongsToMany $relationInstance,
        string $relation,
        string $table,
        QueryBuilder $relationQuery,
        ?string $alias = null
    ): void {
        // Get pivot table and keys using public methods
        $pivotTable = $relationInstance->getPivotTable();
        $foreignPivotKey = $relationInstance->getForeignPivotKey();
        $parentKey = $relationInstance->getParentKey();

        // Build subquery counting records in pivot table
        // Example: SELECT COUNT(*) FROM product_categories WHERE product_categories.product_id = products.id
        $pivotAlias = "{$pivotTable}_pivot";
        $subquery = "SELECT COUNT(*) FROM {$pivotTable} AS {$pivotAlias} WHERE {$pivotAlias}.{$foreignPivotKey} = {$table}.{$parentKey}";

        // Check if relation query has constraints on the related table
        // If so, we need to join the related table to apply those constraints
        $relationSql = $relationQuery->toSql();
        $hasRelatedConstraints = preg_match('/WHERE (.+?)(?:ORDER BY|LIMIT|$)/s', $relationSql, $matches);

        if ($hasRelatedConstraints) {
            $whereClause = $matches[1];

            // Get related table info using public methods
            $relatedTable = $relationInstance->getRelatedTable();
            $relatedPivotKey = $relationInstance->getRelatedPivotKey();
            $relatedKey = $relationInstance->getRelatedKey();

            // Join related table to apply constraints
            $relatedAlias = "{$relatedTable}_related";
            $subquery = "SELECT COUNT(*) FROM {$pivotTable} AS {$pivotAlias} " .
                "INNER JOIN {$relatedTable} AS {$relatedAlias} ON {$pivotAlias}.{$relatedPivotKey} = {$relatedAlias}.{$relatedKey} " .
                "WHERE {$pivotAlias}.{$foreignPivotKey} = {$table}.{$parentKey}";

            // Replace placeholders with actual values (safely quoted)
            $relationBindings = $relationQuery->getBindings();
            $boundWhereClause = $whereClause;
            foreach ($relationBindings as $binding) {
                $quoted = $this->quoteValue($binding);
                $boundWhereClause = preg_replace('/\?/', $quoted, $boundWhereClause, 1);
            }

            // Replace table references in where clause with alias
            // First replace explicit table.column with alias.column
            $boundWhereClause = preg_replace('/\b' . preg_quote($relatedTable, '/') . '\./', "{$relatedAlias}.", $boundWhereClause);

            // Qualify common ambiguous columns to avoid conflicts between pivot and related tables
            // Only qualify specific known columns instead of all columns to avoid false positives
            // This is safer than using aggressive regex that might match values/functions
            $ambiguousColumns = ['id', 'created_at', 'updated_at'];
            foreach ($ambiguousColumns as $col) {
                // Only qualify if not already qualified (no table/alias prefix)
                $boundWhereClause = preg_replace(
                    '/(?<!\w\.)(\b' . preg_quote($col, '/') . '\b)\s*(?=IN\s*\(|=|>|<|>=|<=|!=|<>|LIKE|NOT\s+LIKE|IS\s+NULL|IS\s+NOT\s+NULL)/i',
                    "{$relatedAlias}.$1 ",
                    $boundWhereClause
                );
            }

            $subquery .= " AND ({$boundWhereClause})";
        }

        // Note: Pivot where constraints (wherePivot, wherePivotIn) are already applied
        // to the relationQuery, so they'll be included in the above handling

        // Use alias if provided, otherwise use relation name
        $columnAlias = $alias !== null ? $alias : "{$relation}_count";

        // Ensure we select table.* along with the subquery (only once)
        $columns = $this->getColumns();
        if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
            $this->select("{$table}.*");
        }

        $this->selectRaw("({$subquery}) AS {$columnAlias}");
    }

    /**
     * Add count select for MorphToMany relationships.
     *
     * For polymorphic many-to-many relationships, we count records in the pivot table
     * filtered by morph type and morph id, not the related table directly.
     *
     * @param MorphToMany $relationInstance The MorphToMany relation instance
     * @param string $relation The relation name
     * @param string $table The parent table name
     * @param QueryBuilder $relationQuery The relation query builder
     * @param string|null $alias Optional alias for the count column
     * @return void
     */
    private function addMorphToManyCountSelect(
        MorphToMany $relationInstance,
        string $relation,
        string $table,
        QueryBuilder $relationQuery,
        ?string $alias = null
    ): void {
        // Get pivot table and keys using public methods
        $pivotTable = $relationInstance->getPivotTable();
        $foreignPivotKey = $relationInstance->getForeignPivotKey(); // morph id column (taggable_id)
        $morphType = $relationInstance->getMorphType(); // morph type column (taggable_type)
        $parentKey = $relationInstance->getParentKey();

        // Get morph class for the parent model
        // Use getMorphClass() if available, otherwise use full class name
        $model = new $this->modelClass([]);
        $morphClass = method_exists($model, 'getMorphClass')
            ? $model->getMorphClass()
            : $this->modelClass;

        // Build subquery counting records in pivot table filtered by morph type and morph id
        // Example: SELECT COUNT(*) FROM taggables WHERE taggables.taggable_type = 'post' AND taggables.taggable_id = posts.id
        $pivotAlias = "{$pivotTable}_pivot";
        $subquery = "SELECT COUNT(*) FROM {$pivotTable} AS {$pivotAlias} " .
            "WHERE {$pivotAlias}.{$morphType} = " . $this->quoteValue($morphClass) . " " .
            "AND {$pivotAlias}.{$foreignPivotKey} = {$table}.{$parentKey}";

        // Check if relation query has constraints on the related table
        // If so, we need to join the related table to apply those constraints
        $relationSql = $relationQuery->toSql();
        $hasRelatedConstraints = preg_match('/WHERE (.+?)(?:ORDER BY|LIMIT|$)/s', $relationSql, $matches);

        if ($hasRelatedConstraints) {
            $whereClause = $matches[1];

            // Get related table info using public methods
            $relatedTable = $relationInstance->getRelatedTable();
            $relatedPivotKey = $relationInstance->getRelatedPivotKey(); // tag_id
            $relatedKey = $relationInstance->getRelatedKey(); // id

            // Join related table to apply constraints
            $relatedAlias = "{$relatedTable}_related";
            $subquery = "SELECT COUNT(*) FROM {$pivotTable} AS {$pivotAlias} " .
                "INNER JOIN {$relatedTable} AS {$relatedAlias} ON {$pivotAlias}.{$relatedPivotKey} = {$relatedAlias}.{$relatedKey} " .
                "WHERE {$pivotAlias}.{$morphType} = " . $this->quoteValue($morphClass) . " " .
                "AND {$pivotAlias}.{$foreignPivotKey} = {$table}.{$parentKey}";

            // Replace placeholders with actual values (safely quoted)
            $relationBindings = $relationQuery->getBindings();
            $boundWhereClause = $whereClause;
            foreach ($relationBindings as $binding) {
                $quoted = $this->quoteValue($binding);
                $boundWhereClause = preg_replace('/\?/', $quoted, $boundWhereClause, 1);
            }

            // Replace table references in where clause with alias
            // First replace explicit table.column with alias.column
            $boundWhereClause = preg_replace('/\b' . preg_quote($relatedTable, '/') . '\./', "{$relatedAlias}.", $boundWhereClause);

            // Qualify common ambiguous columns to avoid conflicts between pivot and related tables
            // Only qualify specific known columns instead of all columns to avoid false positives
            // This is safer than using aggressive regex that might match values/functions
            $ambiguousColumns = ['id', 'created_at', 'updated_at'];
            foreach ($ambiguousColumns as $col) {
                // Only qualify if not already qualified (no table/alias prefix)
                $boundWhereClause = preg_replace(
                    '/(?<!\w\.)(\b' . preg_quote($col, '/') . '\b)\s*(?=IN\s*\(|=|>|<|>=|<=|!=|<>|LIKE|NOT\s+LIKE|IS\s+NULL|IS\s+NOT\s+NULL)/i',
                    "{$relatedAlias}.$1 ",
                    $boundWhereClause
                );
            }

            $subquery .= " AND ({$boundWhereClause})";
        }

        // Note: Pivot where constraints (wherePivot, wherePivotIn) are already applied
        // to the relationQuery, so they'll be included in the above handling

        // Use alias if provided, otherwise use relation name
        $columnAlias = $alias !== null ? $alias : "{$relation}_count";

        // Ensure we select table.* along with the subquery (only once)
        $columns = $this->getColumns();
        if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
            $this->select("{$table}.*");
        }

        $this->selectRaw("({$subquery}) AS {$columnAlias}");
    }

    /**
     * Add count select for MorphedByMany relationships.
     *
     * For inverse polymorphic many-to-many relationships, we count records in the pivot table
     * filtered by parent key and morph type of the related model.
     *
     * Example: Tag morphedByMany Posts
     * - Count posts that have this tag
     * - Filter by taggable_type = 'Post'
     *
     * @param MorphedByMany $relationInstance The MorphedByMany relation instance
     * @param string $relation The relation name
     * @param string $table The parent table name
     * @param QueryBuilder $relationQuery The relation query builder
     * @param string|null $alias Optional alias for the count column
     * @return void
     */
    private function addMorphedByManyCountSelect(
        MorphedByMany $relationInstance,
        string $relation,
        string $table,
        QueryBuilder $relationQuery,
        ?string $alias = null
    ): void {
        // Get pivot table and keys using public methods
        $pivotTable = $relationInstance->getPivotTable();
        $foreignPivotKey = $relationInstance->getForeignPivotKey(); // taggable_id
        $parentPivotKey = $relationInstance->getParentPivotKey(); // tag_id
        $morphType = $relationInstance->getMorphType(); // taggable_type
        $parentKey = $relationInstance->getParentKey();

        // Get the related model class for morph type value
        $relatedClass = $relationInstance->getRelatedClass();

        // Build subquery counting records in pivot table filtered by parent key and morph type
        // Example: SELECT COUNT(*) FROM taggables WHERE taggables.tag_id = tags.id AND taggables.taggable_type = 'Post'
        $pivotAlias = "{$pivotTable}_pivot";
        $subquery = "SELECT COUNT(*) FROM {$pivotTable} AS {$pivotAlias} " .
            "WHERE {$pivotAlias}.{$parentPivotKey} = {$table}.{$parentKey} " .
            "AND {$pivotAlias}.{$morphType} = " . $this->quoteValue($relatedClass);

        // Check if relation query has constraints on the related table
        // If so, we need to join the related table to apply those constraints
        $relationSql = $relationQuery->toSql();
        $hasRelatedConstraints = preg_match('/WHERE (.+?)(?:ORDER BY|LIMIT|$)/s', $relationSql, $matches);

        if ($hasRelatedConstraints) {
            $whereClause = $matches[1];

            // Get related table info using public methods
            $relatedTable = $relationInstance->getRelatedTable();
            $relatedKey = $relationInstance->getRelatedKey(); // id on posts table

            // Join related table to apply constraints
            $relatedAlias = "{$relatedTable}_related";
            $subquery = "SELECT COUNT(*) FROM {$pivotTable} AS {$pivotAlias} " .
                "INNER JOIN {$relatedTable} AS {$relatedAlias} ON {$pivotAlias}.{$foreignPivotKey} = {$relatedAlias}.{$relatedKey} " .
                "WHERE {$pivotAlias}.{$parentPivotKey} = {$table}.{$parentKey} " .
                "AND {$pivotAlias}.{$morphType} = " . $this->quoteValue($relatedClass);

            // Replace placeholders with actual values (safely quoted)
            $relationBindings = $relationQuery->getBindings();
            $boundWhereClause = $whereClause;
            foreach ($relationBindings as $binding) {
                $quoted = $this->quoteValue($binding);
                $boundWhereClause = preg_replace('/\?/', $quoted, $boundWhereClause, 1);
            }

            // Replace table references in where clause with alias
            $boundWhereClause = preg_replace('/\b' . preg_quote($relatedTable, '/') . '\./', "{$relatedAlias}.", $boundWhereClause);

            // Qualify unqualified 'id' column to avoid ambiguity
            $boundWhereClause = preg_replace(
                '/\b(id)\s+(IN\s*\(|=|>|<|>=|<=|!=|<>)/i',
                "{$relatedAlias}.$1 $2",
                $boundWhereClause
            );

            $subquery .= " AND ({$boundWhereClause})";
        }

        // Use alias if provided, otherwise use relation name
        $columnAlias = $alias !== null ? $alias : "{$relation}_count";

        // Ensure we select table.* along with the subquery (only once)
        $columns = $this->getColumns();
        if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
            $this->select("{$table}.*");
        }

        $this->selectRaw("({$subquery}) AS {$columnAlias}");
    }

    /**
     * Add withCount subselect for HasManyThrough relationship.
     *
     * HasManyThrough requires JOIN with through table for correct COUNT.
     * Example: City hasMany Books through Authors
     * SQL: SELECT COUNT(*) FROM books INNER JOIN authors ON authors.id = books.author_id
     *      WHERE authors.city_id = cities.id
     *
     * @param \Toporia\Framework\Database\ORM\Relations\HasManyThrough $relationInstance
     * @param string $relation Relationship name
     * @param string $table Parent table name
     * @param QueryBuilder $relationQuery Relation query
     * @param string|null $alias Optional column alias
     * @return void
     */
    private function addHasManyThroughCountSelect(
        $relationInstance,
        string $relation,
        string $table,
        QueryBuilder $relationQuery,
        ?string $alias = null
    ): void {
        // Get keys and tables from HasManyThrough relationship
        $firstKey = $this->getRelationProperty($relationInstance, 'firstKey'); // authors.city_id
        $secondKey = $relationInstance->getForeignKey(); // books.author_id
        $localKey = $relationInstance->getLocalKey(); // cities.id
        $secondLocalKey = $this->getRelationProperty($relationInstance, 'secondLocalKey'); // authors.id

        // Get table names
        $relatedTable = $relationQuery->getTable(); // books
        $throughTable = $this->getRelationProperty($relationInstance, 'throughClass')::getTableName(); // authors

        // Build COUNT subquery with JOIN to through table
        // SELECT COUNT(*) FROM books INNER JOIN authors ON authors.id = books.author_id
        // WHERE authors.city_id = cities.id
        $subquery = "SELECT COUNT(*) FROM {$relatedTable} " .
            "INNER JOIN {$throughTable} ON {$throughTable}.{$secondLocalKey} = {$relatedTable}.{$secondKey} " .
            "WHERE {$throughTable}.{$firstKey} = {$table}.{$localKey}";

        // Add relation query constraints if present
        $relationSql = $relationQuery->toSql();
        if (preg_match('/WHERE (.+?)(?:ORDER BY|LIMIT|GROUP BY|HAVING|$)/s', $relationSql, $matches)) {
            $whereClause = $matches[1];

            // Replace placeholders with actual values (safely quoted)
            $relationBindings = $relationQuery->getBindings();
            $boundWhereClause = $whereClause;
            foreach ($relationBindings as $binding) {
                $quoted = $this->quoteValue($binding);
                $boundWhereClause = preg_replace('/\?/', $quoted, $boundWhereClause, 1);
            }

            // Qualify unqualified column names with related table to avoid ambiguity
            // Use negative lookbehind to match columns NOT preceded by "table_name."
            // Pattern: (?<!\w\.) means "not preceded by word_char + dot"
            $boundWhereClause = preg_replace_callback(
                '/(?<!\w\.)(\b\w+)\s+(=|!=|<>|>|<|>=|<=|LIKE|NOT\s+LIKE|IN|NOT\s+IN|IS|IS\s+NOT)\s+/i',
                function ($matches) use ($relatedTable) {
                    $column = $matches[1];
                    $operator = $matches[2];
                    // Skip SQL keywords
                    $keywords = ['AND', 'OR', 'NOT', 'NULL', 'TRUE', 'FALSE', 'BETWEEN'];
                    if (in_array(strtoupper($column), $keywords)) {
                        return $matches[0];
                    }
                    return "{$relatedTable}.{$column} {$operator} ";
                },
                $boundWhereClause
            );

            $subquery .= " AND ({$boundWhereClause})";
        }

        // Use alias if provided, otherwise use relation name
        $columnAlias = $alias !== null ? $alias : "{$relation}_count";

        // Ensure we select table.* along with the subquery (only once)
        $columns = $this->getColumns();
        if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
            $this->select("{$table}.*");
        }

        $this->selectRaw("({$subquery}) AS {$columnAlias}");
    }

    /**
     * Add a relationship aggregate subselect to the query.
     *
     * @param string $relation Relationship name
     * @param string $column Column to aggregate
     * @param string $function Aggregate function (SUM, AVG, MIN, MAX)
     * @param callable|null $callback Optional callback to constrain the aggregate
     * @return $this
     */
    private function addRelationAggregateSelect(string $relation, string $column, string $function, ?callable $callback = null): self
    {
        /** @var callable $getTableName */
        $getTableName = [$this->modelClass, 'getTableName'];
        $table = $getTableName();

        $model = new $this->modelClass([]);
        $relationInstance = $model->$relation();

        if (!$relationInstance instanceof RelationInterface) {
            throw new \InvalidArgumentException("Method '{$relation}' is not a valid relationship");
        }

        $relationQuery = $relationInstance->getQuery();

        // Apply callback constraints if provided
        if ($callback !== null) {
            $callback($relationQuery);
        }

        // Ensure SoftDeletes is applied if relation model uses it
        // This is important for withSum/withAvg/etc to match standard behavior
        // OPTIMIZED: Use public getRelatedClass() method instead of reflection (O(1) vs O(n))
        $relatedClass = $relationInstance->getRelatedClass();

        if ($relatedClass !== '' && method_exists($relatedClass, 'usesSoftDeletes') && $relatedClass::usesSoftDeletes()) {
            $deletedAtColumn = method_exists($relatedClass, 'getDeletedAtColumn')
                ? $relatedClass::getDeletedAtColumn()
                : 'deleted_at';
            $relationTable = $relationQuery->getTable();
            $relationQuery->whereNull("{$relationTable}.{$deletedAtColumn}");
        }

        // Special handling for HasManyThrough relationships (requires through table JOIN)
        if ($relationInstance instanceof HasManyThrough) {
            $this->addHasManyThroughAggregateSelect($relationInstance, $relation, $column, $function, $table, $relationQuery);
            return $this;
        }

        // Special handling for BelongsToMany relationships (many-to-many through pivot table)
        if ($relationInstance instanceof BelongsToMany) {
            $this->addBelongsToManyAggregateSelect($relationInstance, $relation, $column, $function, $table, $relationQuery);
            return $this;
        }

        // Special handling for MorphToMany relationships (polymorphic many-to-many through pivot table)
        if ($relationInstance instanceof MorphToMany) {
            $this->addMorphToManyAggregateSelect($relationInstance, $relation, $column, $function, $table, $relationQuery);
            return $this;
        }

        $foreignKey = $relationInstance->getForeignKey();
        $localKey = $relationInstance->getLocalKey();
        $relationTable = $relationQuery->getTable();

        // Use alias for self-referencing relationships
        $relationAlias = $table === $relationTable ? "{$relationTable}_relation" : $relationTable;

        // Build subselect with proper aliasing
        $fromClause = $table === $relationTable ? "{$relationTable} AS {$relationAlias}" : $relationTable;
        $subquery = "SELECT {$function}({$relationAlias}.{$column}) FROM {$fromClause} WHERE {$relationAlias}.{$foreignKey} = {$table}.{$localKey}";

        // Add relation query wheres to subquery
        // Important: Inject bindings directly into SQL to avoid binding order issues
        $relationSql = $relationQuery->toSql();
        if (preg_match('/WHERE (.+?)(?:ORDER BY|LIMIT|GROUP BY|HAVING|$)/s', $relationSql, $matches)) {
            $whereClause = $matches[1];

            // Replace placeholders with actual values (safely quoted)
            // Security: Use PDO::quote() instead of addslashes() to prevent SQL injection
            $relationBindings = $relationQuery->getBindings();
            $boundWhereClause = $whereClause;
            foreach ($relationBindings as $binding) {
                // Safely quote value using PDO::quote() (prevents SQL injection)
                $quoted = $this->quoteValue($binding);
                $boundWhereClause = preg_replace('/\?/', $quoted, $boundWhereClause, 1);
            }

            $subquery .= " AND ({$boundWhereClause})";
        }

        $functionLower = strtolower($function);
        $columnAlias = "{$relation}_{$functionLower}_{$column}";

        // Ensure we select table.* along with the subquery (only once)
        $columns = $this->getColumns();
        if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
            $this->select("{$table}.*");
        }

        $this->selectRaw("({$subquery}) AS {$columnAlias}");

        return $this;
    }

    /**
     * Add aggregate select for HasManyThrough relationships.
     *
     * HasManyThrough requires JOIN with the through table (intermediate model).
     * Example: City hasMany Books through Authors
     * - withAvg('books', 'rating') should aggregate books.rating
     * - Join: books → authors → cities
     * - SQL: SELECT AVG(books.rating) FROM books
     *        INNER JOIN authors ON authors.id = books.author_id
     *        WHERE authors.city_id = cities.id
     *
     * @param HasManyThrough $relationInstance The HasManyThrough relation instance
     * @param string $relation The relation name (e.g., 'books')
     * @param string $column The column to aggregate (e.g., 'rating')
     * @param string $function The aggregate function (SUM, AVG, MIN, MAX)
     * @param string $table The parent table name (e.g., 'cities')
     * @param QueryBuilder $relationQuery The relation query builder
     * @return void
     */
    private function addHasManyThroughAggregateSelect(
        HasManyThrough $relationInstance,
        string $relation,
        string $column,
        string $function,
        string $table,
        QueryBuilder $relationQuery
    ): void {
        // Get keys and tables from HasManyThrough relationship
        // firstKey: foreign key on through table (authors.city_id)
        // secondKey: foreign key on related table (books.author_id)
        // localKey: local key on parent table (cities.id)
        // secondLocalKey: local key on through table (authors.id)
        $firstKey = $relationInstance->getFirstKey();
        $secondKey = $relationInstance->getForeignKey();
        $localKey = $relationInstance->getLocalKey();
        $secondLocalKey = $relationInstance->getSecondLocalKey();

        // Get table names
        // relatedTable: final table (books)
        // throughTable: intermediate table (authors)
        $relatedTable = $relationQuery->getTable();
        $throughTable = $relationInstance->getThroughClass()::getTableName();

        // Qualify column name if not already qualified
        $aggregateColumn = str_contains($column, '.') ? $column : "{$relatedTable}.{$column}";

        // Build aggregate subquery with JOIN to through table
        // SELECT AVG(books.rating) FROM books
        // INNER JOIN authors ON authors.id = books.author_id
        // WHERE authors.city_id = cities.id
        $subquery = "SELECT {$function}({$aggregateColumn}) FROM {$relatedTable} " .
            "INNER JOIN {$throughTable} ON {$throughTable}.{$secondLocalKey} = {$relatedTable}.{$secondKey} " .
            "WHERE {$throughTable}.{$firstKey} = {$table}.{$localKey}";

        // Add relation query constraints to subquery
        $relationSql = $relationQuery->toSql();
        if (preg_match('/WHERE (.+?)(?:ORDER BY|LIMIT|GROUP BY|HAVING|$)/s', $relationSql, $matches)) {
            $whereClause = trim($matches[1]);

            // Qualify unqualified column names with related table to avoid ambiguity
            // Pattern: (?<!\w\.) means "not preceded by word_char + dot"
            $whereClause = preg_replace_callback(
                '/(?<!\w\.)(\b\w+)\s+(=|!=|<>|>|<|>=|<=|LIKE|NOT\s+LIKE|IN|NOT\s+IN|IS|IS\s+NOT)\s+/i',
                function ($matches) use ($relatedTable) {
                    $column = $matches[1];
                    $operator = $matches[2];
                    // Skip SQL keywords
                    $keywords = ['AND', 'OR', 'NOT', 'NULL', 'TRUE', 'FALSE', 'BETWEEN'];
                    if (in_array(strtoupper($column), $keywords)) {
                        return $matches[0];
                    }
                    return "{$relatedTable}.{$column} {$operator} ";
                },
                $whereClause
            );

            // Replace placeholders with actual values (safely quoted)
            $relationBindings = $relationQuery->getBindings();
            foreach ($relationBindings as $binding) {
                $quoted = $this->quoteValue($binding);
                $whereClause = preg_replace('/\?/', $quoted, $whereClause, 1);
            }

            $subquery .= " AND ({$whereClause})";
        }

        $functionLower = strtolower($function);
        $columnAlias = "{$relation}_{$functionLower}_{$column}";

        // Ensure we select table.* along with the subquery (only once)
        $columns = $this->getColumns();
        if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
            $this->select("{$table}.*");
        }

        $this->selectRaw("({$subquery}) AS {$columnAlias}");
    }

    /**
     * Add aggregate select for BelongsToMany relationships.
     *
     * For many-to-many relationships, the column can be:
     * 1. A column in the pivot table (e.g., 'sort_order' in product_categories)
     * 2. A column in the related table (e.g., 'name' in categories) - requires join
     *
     * By default, we assume the column is in the pivot table. If constraints exist
     * on the related table, we join it and can aggregate on either table.
     *
     * @param BelongsToMany $relationInstance The BelongsToMany relation instance
     * @param string $relation The relation name
     * @param string $column The column to aggregate (can be pivot or related table column)
     * @param string $function The aggregate function (SUM, AVG, MIN, MAX)
     * @param string $table The parent table name
     * @param QueryBuilder $relationQuery The relation query builder
     * @return void
     */
    private function addBelongsToManyAggregateSelect(
        BelongsToMany $relationInstance,
        string $relation,
        string $column,
        string $function,
        string $table,
        QueryBuilder $relationQuery
    ): void {
        // Get pivot table and keys using public methods
        $pivotTable = $relationInstance->getPivotTable();
        $foreignPivotKey = $relationInstance->getForeignPivotKey();
        $parentKey = $relationInstance->getParentKey();

        // Check if relation query has constraints on the related table
        $relationSql = $relationQuery->toSql();
        $hasRelatedConstraints = preg_match('/WHERE (.+?)(?:ORDER BY|LIMIT|$)/s', $relationSql, $matches);

        // Determine if column is in pivot table or related table
        // By default, assume pivot table. If column contains dot (e.g., "categories.name"),
        // it's explicitly from related table
        $isPivotColumn = !str_contains($column, '.');

        if ($hasRelatedConstraints || !$isPivotColumn) {
            // Need to join related table
            $relatedTable = $relationInstance->getRelatedTable();
            $relatedPivotKey = $relationInstance->getRelatedPivotKey();
            $relatedKey = $relationInstance->getRelatedKey();

            $pivotAlias = "{$pivotTable}_pivot";
            $relatedAlias = "{$relatedTable}_related";

            // Determine which table the column belongs to
            if (str_contains($column, '.')) {
                // Explicit table.column format
                [$tablePart, $columnPart] = explode('.', $column, 2);
                if ($tablePart === $relatedTable || $tablePart === 'categories') {
                    $aggregateColumn = "{$relatedAlias}.{$columnPart}";
                } else {
                    $aggregateColumn = "{$pivotAlias}.{$columnPart}";
                }
            } else {
                // Default: try pivot table first, but we'll join related table for constraints
                // If column doesn't exist in pivot, it should be in related table
                $aggregateColumn = "{$pivotAlias}.{$column}";
            }

            $subquery = "SELECT {$function}({$aggregateColumn}) FROM {$pivotTable} AS {$pivotAlias} " .
                "INNER JOIN {$relatedTable} AS {$relatedAlias} ON {$pivotAlias}.{$relatedPivotKey} = {$relatedAlias}.{$relatedKey} " .
                "WHERE {$pivotAlias}.{$foreignPivotKey} = {$table}.{$parentKey}";

            // Add constraints from relation query
            if ($hasRelatedConstraints) {
                $whereClause = $matches[1];

                // Replace placeholders with actual values (safely quoted)
                $relationBindings = $relationQuery->getBindings();
                $boundWhereClause = $whereClause;
                foreach ($relationBindings as $binding) {
                    $quoted = $this->quoteValue($binding);
                    $boundWhereClause = preg_replace('/\?/', $quoted, $boundWhereClause, 1);
                }

                // Replace table references in where clause with alias
                $boundWhereClause = preg_replace('/\b' . preg_quote($relatedTable, '/') . '\./', "{$relatedAlias}.", $boundWhereClause);

                $subquery .= " AND ({$boundWhereClause})";
            }
        } else {
            // Simple case: aggregate on pivot table column only
            $pivotAlias = "{$pivotTable}_pivot";
            $subquery = "SELECT {$function}({$pivotAlias}.{$column}) FROM {$pivotTable} AS {$pivotAlias} " .
                "WHERE {$pivotAlias}.{$foreignPivotKey} = {$table}.{$parentKey}";
        }

        $functionLower = strtolower($function);
        $columnAlias = "{$relation}_{$functionLower}_{$column}";

        // Ensure we select table.* along with the subquery (only once)
        $columns = $this->getColumns();
        if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
            $this->select("{$table}.*");
        }

        $this->selectRaw("({$subquery}) AS {$columnAlias}");
    }

    /**
     * Add aggregate select for MorphToMany relationships.
     *
     * For polymorphic many-to-many relationships, we aggregate records in the pivot table
     * filtered by morph type and morph id, optionally joining the related table for constraints.
     *
     * @param MorphToMany $relationInstance The MorphToMany relation instance
     * @param string $relation The relation name
     * @param string $column Column to aggregate
     * @param string $function Aggregate function (SUM, AVG, MIN, MAX)
     * @param string $table The parent table name
     * @param QueryBuilder $relationQuery The relation query builder
     * @return void
     */
    private function addMorphToManyAggregateSelect(
        MorphToMany $relationInstance,
        string $relation,
        string $column,
        string $function,
        string $table,
        QueryBuilder $relationQuery
    ): void {
        // Get pivot table and keys using public methods
        $pivotTable = $relationInstance->getPivotTable();
        $foreignPivotKey = $relationInstance->getForeignPivotKey(); // morph id column (taggable_id)
        $morphType = $relationInstance->getMorphType(); // morph type column (taggable_type)
        $parentKey = $relationInstance->getParentKey();

        // Get morph class for the parent model
        // Use getMorphClass() if available, otherwise use full class name
        $model = new $this->modelClass([]);
        $morphClass = method_exists($model, 'getMorphClass')
            ? $model->getMorphClass()
            : $this->modelClass;

        // Check if relation query has constraints on the related table
        $relationSql = $relationQuery->toSql();
        $hasRelatedConstraints = preg_match('/WHERE (.+?)(?:ORDER BY|LIMIT|$)/s', $relationSql, $matches);

        // Determine if column is in pivot table or related table
        // By default, assume pivot table. If column contains dot (e.g., "tags.name"),
        // it's explicitly from related table
        $isPivotColumn = !str_contains($column, '.');

        if ($hasRelatedConstraints || !$isPivotColumn) {
            // Need to join related table
            $relatedTable = $relationInstance->getRelatedTable();
            $relatedPivotKey = $relationInstance->getRelatedPivotKey(); // tag_id
            $relatedKey = $relationInstance->getRelatedKey(); // id

            $pivotAlias = "{$pivotTable}_pivot";
            $relatedAlias = "{$relatedTable}_related";

            // Determine which table the column belongs to
            if (str_contains($column, '.')) {
                // Explicit table.column format
                [$tablePart, $columnPart] = explode('.', $column, 2);
                if ($tablePart === $relatedTable) {
                    $aggregateColumn = "{$relatedAlias}.{$columnPart}";
                } else {
                    $aggregateColumn = "{$pivotAlias}.{$columnPart}";
                }
            } else {
                // Default: try pivot table first, but we'll join related table for constraints
                // If column doesn't exist in pivot, it should be in related table
                $aggregateColumn = "{$pivotAlias}.{$column}";
            }

            $subquery = "SELECT {$function}({$aggregateColumn}) FROM {$pivotTable} AS {$pivotAlias} " .
                "INNER JOIN {$relatedTable} AS {$relatedAlias} ON {$pivotAlias}.{$relatedPivotKey} = {$relatedAlias}.{$relatedKey} " .
                "WHERE {$pivotAlias}.{$morphType} = " . $this->quoteValue($morphClass) . " " .
                "AND {$pivotAlias}.{$foreignPivotKey} = {$table}.{$parentKey}";

            // Add constraints from relation query
            if ($hasRelatedConstraints) {
                $whereClause = $matches[1];

                // Replace placeholders with actual values (safely quoted)
                $relationBindings = $relationQuery->getBindings();
                $boundWhereClause = $whereClause;
                foreach ($relationBindings as $binding) {
                    $quoted = $this->quoteValue($binding);
                    $boundWhereClause = preg_replace('/\?/', $quoted, $boundWhereClause, 1);
                }

                // Replace table references in where clause with alias
                $boundWhereClause = preg_replace('/\b' . preg_quote($relatedTable, '/') . '\./', "{$relatedAlias}.", $boundWhereClause);

                $subquery .= " AND ({$boundWhereClause})";
            }
        } else {
            // Simple case: aggregate on pivot table column only
            $pivotAlias = "{$pivotTable}_pivot";
            $subquery = "SELECT {$function}({$pivotAlias}.{$column}) FROM {$pivotTable} AS {$pivotAlias} " .
                "WHERE {$pivotAlias}.{$morphType} = " . $this->quoteValue($morphClass) . " " .
                "AND {$pivotAlias}.{$foreignPivotKey} = {$table}.{$parentKey}";
        }

        $functionLower = strtolower($function);
        $columnAlias = "{$relation}_{$functionLower}_{$column}";

        // Ensure we select table.* along with the subquery (only once)
        $columns = $this->getColumns();
        if (empty($columns) || !in_array("{$table}.*", $columns, true)) {
            $this->select("{$table}.*");
        }

        $this->selectRaw("({$subquery}) AS {$columnAlias}");
    }

    /**
     * Chunk the query results into smaller batches.
     *
     * Allows chunking with query constraints applied.
     * More flexible than Model::chunk() for complex queries.
     *
     * Performance: O(n/chunkSize) queries
     * Memory: O(chunkSize) - Only one chunk in memory at a time
     *
     * @param int $chunkSize Number of records per chunk
     * @param callable|null $callback Optional callback to process each chunk
     * @return \Generator<ModelCollection>|void Generator of chunks (if no callback), void (if callback provided)
     *
     * @example
     * ```php
     * // Chunk with WHERE clause
     * foreach (User::query()->where('age', '>=', 25)->chunk(100) as $chunk) {
     *     // Process chunk
     * }
     *
     * // With callback
     * User::query()->where('active', 1)->chunk(100, function ($chunk) {
     *     // Process chunk
     * });
     * ```
     */
    public function chunk(int $count, \Closure $callback): bool
    {
        $offset = 0;

        while (true) {
            // Clone query to preserve original state
            $query = clone $this;
            $chunk = $query
                ->limit($count)
                ->offset($offset)
                ->getModels();

            if ($chunk->isEmpty()) {
                break;
            }

            $callback($chunk);

            // If chunk is smaller than count, we're done
            if ($chunk->count() < $count) {
                break;
            }

            $offset += $count;

            // Force garbage collection to free memory
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        return true;
    }

    /**
     * Magic method to enable fluent get() method and local scopes.
     *
     * Intercepts:
     * - ->get() calls and redirects to ->getModels() to return ModelCollection
     * - Local scope calls (e.g., ->published(), ->active()) from HasQueryScopes trait
     *
     * This is needed because PHP doesn't support return type covariance for Collection types.
     *
     * @param string $method Method name
     * @param array<mixed> $arguments Method arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        // Intercept get() to return ModelCollection
        if ($method === 'get') {
            return $this->getModels();
        }

        // Check if this is a local scope call (e.g., ->published(), ->active())
        // Local scopes are defined as scopeXxx() methods in the model
        if (method_exists($this->modelClass, 'hasLocalScope') &&
            call_user_func([$this->modelClass, 'hasLocalScope'], $method)) {
            call_user_func([$this->modelClass, 'applyLocalScope'], $this, $method, ...$arguments);
            return $this;
        }

        // Forward to parent QueryBuilder for other methods
        if (method_exists(parent::class, $method)) {
            return parent::$method(...$arguments);
        }

        throw new \BadMethodCallException("Method {$method} does not exist on " . static::class);
    }

    /**
     * Chunk the query results using cursor-based pagination.
     *
     * More efficient than offset-based chunking for large datasets.
     * Uses WHERE id > lastId instead of OFFSET.
     *
     * Performance: O(n/chunkSize) queries, faster than OFFSET-based
     * Memory: O(chunkSize) - Only one chunk in memory at a time
     *
     * @param int $chunkSize Number of records per chunk
     * @param callable|null $callback Optional callback to process each chunk
     * @return \Generator<ModelCollection>|void
     *
     * @example
     * ```php
     * // More efficient for large datasets
     * foreach (User::query()->where('active', 1)->chunkById(1000) as $chunk) {
     *     // Process chunk
     * }
     * ```
     */
    public function chunkById(int $count, Closure $callback, ?string $column = null, ?string $alias = null): bool
    {
        /** @var callable $getPrimaryKey */
        $getPrimaryKey = [$this->modelClass, 'getPrimaryKey'];
        $primaryKey = $column ?? $getPrimaryKey();
        $lastId = 0;

        while (true) {
            // Clone query to preserve original state
            $query = clone $this;
            $chunk = $query
                ->where($primaryKey, '>', $lastId)
                ->orderBy($primaryKey, 'ASC')
                ->limit($count)
                ->getModels();

            if ($chunk->isEmpty()) {
                break;
            }

            $callback($chunk);

            // Get last ID from chunk
            /** @var \Toporia\Framework\Database\ORM\Model $lastModel */
            $lastModel = $chunk->last();
            $lastId = $lastModel->getKey();

            // If chunk is smaller than count, we're done
            if ($chunk->count() < $count) {
                break;
            }

            // Force garbage collection to free memory
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        return true;
    }

    // =========================================================================
    // PERFORMANCE OPTIMIZATION METHODS
    // =========================================================================

    /**
     * Add query hints for performance optimization.
     *
     * This is a Toporia exclusive feature for database optimization.
     *
     * @param string $type Hint type ('index', 'force_index', 'use_index')
     * @param array $values Hint values
     * @return $this
     *
     * @example
     * ProductModel::whereDoesntHave('reviews')
     *     ->addQueryHint('index', ['idx_product_id'])
     *     ->get();
     */
    public function addQueryHint(string $type, array $values = []): self
    {
        // Delegate to parent QueryBuilder
        parent::addQueryHint($type, $values);
        return $this;
    }

    /**
     * Optimize query for large result sets.
     *
     * @param bool $optimize Whether to enable optimization
     * @return $this
     */
    public function optimizeForLargeResults(bool $optimize = true): self
    {
        if ($optimize) {
            // Add SQL_NO_CACHE hint for large datasets
            $this->addQueryHint('no_cache');

            // Enable streaming mode flag
            $this->addQueryHint('stream_results');

            // Disable query result caching
            $this->disableQueryCaching();
        }

        return $this;
    }

    /**
     * Execute query in streaming mode for large datasets.
     *
     * @return \Generator<static> Generator yielding Model instances
     */
    public function stream(): \Generator
    {
        foreach (parent::stream() as $row) {
            // Hydrate each row into model instance
            $model = new $this->modelClass([]);

            // Set attributes directly (bypass mass assignment)
            foreach ($row as $key => $value) {
                $model->setAttribute($key, $value);
            }

            $model->exists = true;
            $model->syncOriginal();

            yield $model;
        }
    }

    /**
     * Process large model datasets in chunks using streaming.
     *
     * Overrides parent to work with ModelCollection instead of RowCollection.
     * Provides same signature as parent for consistency.
     *
     * @param int $chunkSize Number of models per chunk
     * @param callable $callback Callback to process each chunk
     * @return bool
     */
    public function streamChunk(int $chunkSize, callable $callback): bool
    {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException('Chunk size must be at least 1');
        }

        $chunk = [];
        $count = 0;

        foreach ($this->stream() as $model) {
            $chunk[] = $model;
            $count++;

            if ($count >= $chunkSize) {
                // Create ModelCollection for chunk
                $collection = $this->newCollection($chunk);

                // Process chunk - same signature as parent (collection, count)
                $result = $callback($collection, $count);

                if ($result === false) {
                    return false;
                }

                // Reset for next chunk
                $chunk = [];
                $count = 0;
            }
        }

        // Process remaining models
        if (!empty($chunk)) {
            $collection = $this->newCollection($chunk);
            $callback($collection, $count);
        }

        return true;
    }

    /**
     * Create a new model collection.
     *
     * @param array $models
     * @return ModelCollection
     */
    private function newCollection(array $models = []): ModelCollection
    {
        return new ModelCollection($models);
    }

    /**
     * Enable query explanation for debugging.
     *
     * @param bool $analyze Whether to include execution statistics
     * @return $this
     */
    public function explain(bool $analyze = false): array
    {
        $sql = $this->toSql();
        $bindings = $this->getBindings();

        return $this->executeExplain($sql, $bindings, $analyze);
    }

    /**
     * Execute EXPLAIN query and return results.
     *
     * @param string $sql Base SQL query
     * @param array $bindings Query bindings
     * @param bool $analyze Whether to include execution statistics
     * @return array Explain results
     */
    private function executeExplain(string $sql, array $bindings, bool $analyze = false): array
    {
        $connection = $this->getConnection();
        $driver = $connection->getConfig()['driver'] ?? 'mysql';

        $explainSql = match ($driver) {
            'mysql' => $analyze ? "EXPLAIN ANALYZE {$sql}" : "EXPLAIN {$sql}",
            'pgsql' => $analyze ? "EXPLAIN (ANALYZE, BUFFERS) {$sql}" : "EXPLAIN {$sql}",
            'sqlite' => "EXPLAIN QUERY PLAN {$sql}",
            default => "EXPLAIN {$sql}"
        };

        try {
            $results = $connection->select($explainSql, $bindings);

            return [
                'driver' => $driver,
                'analyze' => $analyze,
                'original_sql' => $sql,
                'explain_sql' => $explainSql,
                'bindings' => $bindings,
                'results' => $results,
                'formatted' => $this->formatExplainResults($results, $driver, $analyze)
            ];
        } catch (\Exception $e) {
            return [
                'error' => true,
                'message' => $e->getMessage(),
                'driver' => $driver,
                'sql' => $explainSql
            ];
        }
    }

    /**
     * Format explain results for better readability.
     *
     * @param array $results Raw explain results
     * @param string $driver Database driver
     * @param bool $analyze Whether analysis was performed
     * @return array Formatted results
     */
    private function formatExplainResults(array $results, string $driver, bool $analyze): array
    {
        if (empty($results)) {
            return ['message' => 'No explain results returned'];
        }

        return match ($driver) {
            'mysql' => $this->formatMySQLExplain($results, $analyze),
            'pgsql' => $this->formatPostgreSQLExplain($results, $analyze),
            'sqlite' => $this->formatSQLiteExplain($results),
            default => $results
        };
    }

    /**
     * Format MySQL explain results.
     *
     * @param array $results
     * @param bool $analyze
     * @return array
     */
    private function formatMySQLExplain(array $results, bool $analyze): array
    {
        $formatted = [];

        foreach ($results as $row) {
            $formatted[] = [
                'id' => $row['id'] ?? null,
                'select_type' => $row['select_type'] ?? null,
                'table' => $row['table'] ?? null,
                'type' => $row['type'] ?? null,
                'possible_keys' => $row['possible_keys'] ?? null,
                'key' => $row['key'] ?? null,
                'key_len' => $row['key_len'] ?? null,
                'ref' => $row['ref'] ?? null,
                'rows' => $row['rows'] ?? null,
                'filtered' => $row['filtered'] ?? null,
                'extra' => $row['Extra'] ?? null,
                'performance_analysis' => $this->analyzeMySQLPerformance($row)
            ];
        }

        return $formatted;
    }

    /**
     * Analyze MySQL performance from explain results.
     *
     * @param array $row
     * @return array
     */
    private function analyzeMySQLPerformance(array $row): array
    {
        $analysis = [];

        // Analyze access type
        $type = $row['type'] ?? '';
        $analysis['access_type'] = match ($type) {
            'const' => ['status' => 'excellent', 'message' => 'Constant time lookup'],
            'eq_ref' => ['status' => 'excellent', 'message' => 'Unique index lookup'],
            'ref' => ['status' => 'good', 'message' => 'Non-unique index lookup'],
            'range' => ['status' => 'acceptable', 'message' => 'Index range scan'],
            'index' => ['status' => 'poor', 'message' => 'Full index scan'],
            'ALL' => ['status' => 'bad', 'message' => 'Full table scan - consider adding index'],
            default => ['status' => 'unknown', 'message' => 'Unknown access type']
        };

        // Analyze rows examined
        $rows = (int)($row['rows'] ?? 0);
        $analysis['rows_examined'] = [
            'count' => $rows,
            'status' => match (true) {
                $rows <= 100 => 'excellent',
                $rows <= 1000 => 'good',
                $rows <= 10000 => 'acceptable',
                default => 'poor'
            }
        ];

        // Check for key usage
        $key = $row['key'] ?? null;
        $analysis['index_usage'] = [
            'using_index' => !empty($key),
            'index_name' => $key,
            'status' => empty($key) ? 'bad' : 'good'
        ];

        return $analysis;
    }

    /**
     * Format PostgreSQL explain results.
     *
     * @param array $results
     * @param bool $analyze
     * @return array
     */
    private function formatPostgreSQLExplain(array $results, bool $analyze): array
    {
        // PostgreSQL EXPLAIN returns text format
        return [
            'raw_output' => $results,
            'note' => 'PostgreSQL explain output is in text format'
        ];
    }

    /**
     * Format SQLite explain results.
     *
     * @param array $results
     * @return array
     */
    private function formatSQLiteExplain(array $results): array
    {
        return [
            'query_plan' => $results,
            'note' => 'SQLite query plan output'
        ];
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Safely quote a value for SQL injection prevention.
     *
     * Uses PDO::quote() for proper escaping based on database type.
     *
     * @param mixed $value Value to quote
     * @return string Quoted value
     */
    protected function quoteValue(mixed $value): string
    {
        $pdo = $this->getConnection()->getPdo();

        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Use PDO::quote for strings to prevent SQL injection
        return $pdo->quote((string) $value);
    }

    /**
     * Build a safe subquery for relationship filtering.
     *
     * This method ensures proper SQL generation and prevents injection attacks.
     *
     * @param RelationInterface $relation The relationship instance
     * @param string $parentTable Parent table name
     * @param QueryBuilder $relationQuery Relation query builder
     * @param bool $exists Whether this is for EXISTS (true) or NOT EXISTS (false)
     * @return string Safe subquery SQL
     */
    private function buildSafeRelationSubquery(RelationInterface $relation, string $parentTable, QueryBuilder $relationQuery, bool $exists = true): string
    {
        // Handle different relationship types
        if (
            $relation instanceof BelongsToMany ||
            $relation instanceof MorphToMany
        ) {
            $subquerySql = $this->buildPivotWhereHasSubquery($relation, $parentTable, $relationQuery);
        } else {
            $subquerySql = $this->buildSimpleWhereHasSubquery($relation, $parentTable, $relationQuery);
        }

        // Add relation constraints safely
        $relationSql = $relationQuery->toSql();
        if (preg_match('/WHERE (.+?)(?:ORDER BY|LIMIT|$)/s', $relationSql, $matches)) {
            $whereClause = $matches[1];

            // Safely bind parameters
            $relationBindings = $relationQuery->getBindings();
            $boundWhereClause = $whereClause;
            foreach ($relationBindings as $binding) {
                $quoted = $this->quoteValue($binding);
                $boundWhereClause = preg_replace('/\?/', $quoted, $boundWhereClause, 1);
            }

            $subquerySql .= " AND ({$boundWhereClause})";
        }

        return $subquerySql;
    }

    /**
     * Get relationship cache key for performance optimization.
     *
     * @param string $relation Relationship name
     * @param array $constraints Query constraints
     * @return string Cache key
     */
    private function getRelationshipCacheKey(string $relation, array $constraints = []): string
    {
        $modelClass = $this->modelClass;
        $constraintsHash = md5(serialize($constraints));

        return "relationship:{$modelClass}:{$relation}:{$constraintsHash}";
    }

    /**
     * Check if relationship caching is enabled.
     *
     * @return bool
     */
    private function isRelationshipCachingEnabled(): bool
    {
        return property_exists($this, 'relationshipCachingEnabled') && $this->relationshipCachingEnabled;
    }

    // =========================================================================
    // EAGER LOADING METHODS (ORM LAYER - NOT IN QUERY BUILDER)
    // =========================================================================

    /**
     * Set the relationships that should be eager loaded (ORM layer).
     *
     * @param array<string, callable|null> $relations
     * @return $this
     */
    public function setEagerLoad(array $relations): self
    {
        $this->eagerLoad = $relations;
        return $this;
    }

    /**
     * Get the relationships that should be eager loaded (ORM layer).
     *
     * @return array<string, callable|null>
     */
    public function getEagerLoad(): array
    {
        return $this->eagerLoad;
    }

    /**
     * Execute the query and return a DatabaseCollection (ModelCollection).
     *
     * Overrides parent get() method with same return type for compatibility.
     *
     * @return DatabaseCollection
     */
    public function get(): DatabaseCollection
    {
        return $this->getModels();
    }

    /**
     * Get a cursor for streaming model results without loading into memory.
     *
     * Overrides parent cursor() to hydrate rows into Model instances.
     * This is the most memory-efficient method for processing large datasets.
     *
     * Uses PDO cursor streaming to fetch one row at a time from the database,
     * hydrating each row into a Model instance on-demand. This prevents loading
     * all records into memory at once.
     *
     * Performance:
     * - Memory: O(1) - Only one Model in memory at a time
     * - Time: O(N) - Processes all records
     * - Database: Single query, PDO streams results
     * - Hydration: Models are hydrated on-demand during iteration
     *
     * Note: Cursor keeps database connection open during iteration.
     * Don't use for long-running processes that need connection pooling.
     *
     * Eager loading: Relationships are NOT automatically loaded. Use with() before
     * calling cursor() if you need relationships, but be aware this may impact memory usage.
     *
     * @return \Generator<int, TModel> Generator of model instances
     *
     * @example
     * ```php
     * // Process all users without loading into memory
     * foreach (UserModel::query()->cursor() as $user) {
     *     echo $user->name;
     * }
     *
     * // With query constraints
     * foreach (UserModel::query()->where('active', true)->cursor() as $user) {
     *     processUser($user);
     * }
     * ```
     */
    public function cursor(): \Generator
    {
        $modelClass = $this->modelClass;
        $eagerLoad = $this->getEagerLoad();

        // Use parent cursor() to get raw rows (PDO streaming)
        foreach (parent::cursor() as $row) {
            // Hydrate row into Model instance
            /** @var callable $hydrate */
            $hydrate = [$modelClass, 'hydrate'];
            $models = $hydrate([$row]); // hydrate expects array of rows

            if ($models->isEmpty()) {
                continue;
            }

            $model = $models->first();

            // Load eager relationships if configured
            // Note: For cursor, we load relationships per model
            // This is less efficient than batch loading but necessary for memory efficiency
            if (!empty($eagerLoad) && $model !== null) {
                /** @var callable $eagerLoadRelations */
                $eagerLoadRelations = [$modelClass, 'eagerLoadRelations'];
                $eagerLoadRelations($models, $eagerLoad);
            }

            yield $model;
        }
    }

    /**
     * Get results as a LazyCollection of Models for memory-efficient processing.
     *
     * Returns a LazyCollection that uses PDO cursor to stream results one at a time,
     * hydrating each row into a Model instance. This is ideal for processing large
     * datasets without loading everything into memory.
     *
     * The LazyCollection supports all collection methods (map, filter, etc.) and can be
     * chained seamlessly, just like regular ModelCollections.
     *
     * Example:
     * ```php
     * $users = UserModel::query()
     *     ->where('active', true)
     *     ->toLazyCollection()
     *     ->map(fn($user) => $user->name)
     *     ->filter(fn($name) => strlen($name) > 5)
     *     ->take(100);
     *
     * foreach ($users as $name) {
     *     echo $name;
     * }
     * ```
     *
     * Performance:
     * - Memory: O(1) - Only one Model in memory at a time
     * - Time: O(N) - Processes all records
     * - Database: Single query, PDO streams results
     * - Hydration: Models are hydrated on-demand during iteration
     *
     * Note: Cursor keeps database connection open during iteration.
     * Don't use for long-running processes that need connection pooling.
     *
     * Eager loading: Relationships are NOT automatically loaded. Use with() before
     * calling toLazyCollection() if you need relationships, but be aware this may
     * impact memory usage.
     *
     * @return \Toporia\Framework\Support\Collection\LazyCollection<int, TModel>
     */
    public function toLazyCollection(): LazyCollection
    {
        $modelClass = $this->modelClass;
        $eagerLoad = $this->getEagerLoad();

        return LazyCollection::make(function () use ($modelClass, $eagerLoad) {
            // Use cursor to stream results
            foreach (parent::cursor() as $row) {
                // Hydrate row into Model instance
                /** @var callable $hydrate */
                $hydrate = [$modelClass, 'hydrate'];
                $models = $hydrate([$row]); // hydrate expects array of rows

                if ($models->isEmpty()) {
                    continue;
                }

                $model = $models->first();

                // Load eager relationships if configured
                // Note: For lazy collections, we load relationships per model
                // This is less efficient than batch loading but necessary for memory efficiency
                if (!empty($eagerLoad) && $model !== null) {
                    /** @var callable $eagerLoadRelations */
                    $eagerLoadRelations = [$modelClass, 'eagerLoadRelations'];
                    $eagerLoadRelations($models, $eagerLoad);
                }

                yield $model;
            }
        });
    }

    /**
     * Get results as a LazyCollection of Models using chunked pagination.
     *
     * Alternative to toLazyCollection() that uses chunked queries instead of cursor.
     * This is useful when cursor() is not available or when you need more control
     * over memory usage with chunked processing.
     *
     * Models are hydrated in chunks, which can be more memory-efficient for certain
     * use cases, especially when processing relationships.
     *
     * Example:
     * ```php
     * $users = UserModel::query()
     *     ->with('posts')
     *     ->toLazyCollectionByChunk(1000)
     *     ->map(fn($user) => $user->posts->count())
     *     ->filter(fn($count) => $count > 10);
     * ```
     *
     * Performance:
     * - Memory: O(chunkSize) - Only chunkSize Models in memory at a time
     * - Time: O(N) - Processes all records
     * - Database: Multiple queries with LIMIT/OFFSET pagination
     * - Hydration: Models are hydrated in chunks
     *
     * @param int $chunkSize Number of records to fetch per database query (default: 1000)
     * @return \Toporia\Framework\Support\Collection\LazyCollection<int, TModel>
     */
    public function toLazyCollectionByChunk(int $chunkSize = 1000): LazyCollection
    {
        $modelClass = $this->modelClass;
        $eagerLoad = $this->getEagerLoad();

        return LazyCollection::make(function () use ($modelClass, $chunkSize, $eagerLoad) {
            // Use lazy() generator which handles chunking
            foreach (parent::lazy($chunkSize) as $row) {
                // Hydrate row into Model instance
                /** @var callable $hydrate */
                $hydrate = [$modelClass, 'hydrate'];
                $models = $hydrate([$row]); // hydrate expects array of rows

                if ($models->isEmpty()) {
                    continue;
                }

                $model = $models->first();

                // Load eager relationships if configured
                if (!empty($eagerLoad) && $model !== null) {
                    /** @var callable $eagerLoadRelations */
                    $eagerLoadRelations = [$modelClass, 'eagerLoadRelations'];
                    $eagerLoadRelations($models, $eagerLoad);
                }

                yield $model;
            }
        });
    }
}
