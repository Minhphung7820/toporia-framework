<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Relations;

use Toporia\Framework\Database\ORM\{Model, ModelCollection};
use Toporia\Framework\Database\Query\{QueryBuilder, RowCollection};
use Toporia\Framework\Support\Pagination\CursorPaginator;
use Toporia\Framework\Support\Str;

/**
 * HasManyThrough Relationship
 *
 * Handles has-many-through relationships for distant relations.
 * Example: Country hasMany Posts through Users
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
 * @package     toporia/framework
 * @subpackage  Relations
 * @since       2025-01-10
 */
class HasManyThrough extends Relation
{
    /** @var string|null Cached through table name */
    private ?string $throughTableCache = null;

    /** @var string|null Cached related table name */
    private ?string $relatedTableCache = null;

    /**
     * @param QueryBuilder $query Query builder for related model
     * @param Model $parent Parent model instance
     * @param class-string<Model> $relatedClass Related model class (Post)
     * @param class-string<Model> $throughClass Through model class (User)
     * @param string $firstKey Foreign key on through table (users.country_id)
     * @param string $secondKey Foreign key on related table (posts.user_id)
     * @param string $localKey Local key on parent table (countries.id)
     * @param string $secondLocalKey Local key on through table (users.id)
     */
    public function __construct(
        QueryBuilder $query,
        Model $parent,
        protected string $relatedClass,
        protected string $throughClass,
        protected string $firstKey,
        string $secondKey,
        string $localKey,
        protected string $secondLocalKey
    ) {
        parent::__construct($query, $parent, $firstKey, $localKey);

        // Override foreignKey to use secondKey (posts.user_id)
        $this->foreignKey = $secondKey;

        $this->performJoin();
    }

    /**
     * {@inheritdoc}
     */
    public function getRelatedClass(): string
    {
        return $this->relatedClass;
    }

    // =========================================================================
    // TABLE NAME HELPERS (with caching)
    // =========================================================================

    /**
     * Get cached through table name.
     */
    protected function getThroughTable(): string
    {
        return $this->throughTableCache ??= $this->throughClass::getTableName();
    }

    /**
     * Get cached related table name.
     */
    protected function getRelatedTable(): string
    {
        return $this->relatedTableCache ??= $this->relatedClass::getTableName();
    }

    // =========================================================================
    // CORE RELATION METHODS
    // =========================================================================

    /**
     * Perform join with through table.
     */
    protected function performJoin(): void
    {
        $throughTable = $this->getThroughTable();
        $relatedTable = $this->getRelatedTable();

        $this->query->join(
            $throughTable,
            "{$throughTable}.{$this->secondLocalKey}",
            '=',
            "{$relatedTable}.{$this->foreignKey}"
        );

        if ($this->parent->exists()) {
            $this->query->where(
                "{$throughTable}.{$this->firstKey}",
                $this->parent->getAttribute($this->localKey)
            );
        }
    }

    /**
     * Override addConstraints - constraints are already added in performJoin().
     */
    public function addConstraints(): static
    {
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getResults(): ModelCollection
    {
        // Check if this is eager loading with limit (needs window function optimization)
        $wheres = $this->query->getWheres();

        // Use base class helper - search for any WHERE IN (no table filter needed)
        $isEagerLoading = $this->findWhereInRecursive($wheres, '');

        $relatedTable = $this->getRelatedTable();
        $throughTable = $this->getThroughTable();

        // If eager loading with limit, use window function for optimal performance
        // When limit()/take() is used in eager loading, we need per-parent limiting, not global limiting
        // Also supports offset()/skip() for pagination within each parent
        if ($isEagerLoading) {
            $orders = $this->query->getOrders();
            $limit = $this->query->getLimit();
            $offset = $this->query->getOffset();

            // Use window function when limit is present (even without explicit orderBy)
            // Also works with offset/skip for pagination
            if ($limit !== null && $limit > 0) {
                // Build window function query for HasManyThrough (with JOIN)
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

                // Use column name without table prefix because baseQuery selects it without alias
                // BaseQuery: SELECT related.*, through.first_key (result column is just "first_key", not "through.first_key")
                $wrappedFirstKey = $grammar->wrapColumn($this->firstKey);

                // Build ORDER BY clause for window function
                // If no explicit orderBy, use primary key as default for consistent ordering
                $orderParts = [];
                if (!empty($orders)) {
                    foreach ($orders as $order) {
                        $col = $order['column'] ?? '';
                        $dir = strtoupper($order['direction'] ?? 'ASC');
                        // Handle qualified column names (table.column)
                        if (str_contains($col, '.')) {
                            $parts = explode('.', $col, 2);
                            $wrappedCol = $grammar->wrapTable($parts[0]) . '.' . $grammar->wrapColumn($parts[1]);
                        } else {
                            // IMPORTANT: Qualify unqualified columns with related table to avoid ambiguity
                            // Example: "rating" becomes "books.rating" when related table is "books"
                            // This prevents "Column 'rating' in order clause is ambiguous" errors
                            $wrappedCol = $grammar->wrapTable($relatedTable) . '.' . $grammar->wrapColumn($col);
                        }
                        $orderParts[] = "{$wrappedCol} {$dir}";
                    }
                } else {
                    // Default order by primary key for consistent results
                    $primaryKey = $this->relatedClass::getPrimaryKey();
                    $wrappedPrimaryKey = $grammar->wrapTable($relatedTable) . '.' . $grammar->wrapColumn($primaryKey);
                    $orderParts[] = "{$wrappedPrimaryKey} ASC";
                }
                $orderByClause = implode(', ', $orderParts);

                // Build base query with JOIN and all conditions
                $connection = $this->query->getConnection();
                $baseQuery = new QueryBuilder($connection);
                $baseQuery->table($relatedTable);
                $baseQuery->join(
                    $throughTable,
                    "{$throughTable}.{$this->secondLocalKey}",
                    '=',
                    "{$relatedTable}.{$this->foreignKey}"
                );

                // Copy all where conditions from original query
                foreach ($wheres as $where) {
                    // Case-insensitive check for 'in' type
                    if (strtolower($where['type'] ?? '') === 'in') {
                        // Skip WHERE IN for firstKey, handled in window function
                        if (($where['column'] ?? '') === "{$throughTable}.{$this->firstKey}") {
                            continue;
                        }
                    }

                    // Qualify column if not already qualified to avoid ambiguity
                    $column = $where['column'] ?? '';
                    if (!empty($column) && !str_contains($column, '.')) {
                        $column = "{$relatedTable}.{$column}";
                    }

                    match ($where['type'] ?? '') {
                        'basic' => $baseQuery->where(
                            $column,
                            $where['operator'] ?? '=',
                            $where['value'] ?? null,
                            $where['boolean'] ?? 'AND'
                        ),
                        'Null' => $baseQuery->whereNull($column, $where['boolean'] ?? 'AND'),
                        'NotNull' => $baseQuery->whereNotNull($column, $where['boolean'] ?? 'AND'),
                        'In' => $baseQuery->whereIn($column, $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                        'NotIn' => $baseQuery->whereNotIn($column, $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                        'Raw' => $baseQuery->whereRaw(
                            $where['sql'] ?? '',
                            $where['bindings'] ?? [],
                            $where['boolean'] ?? 'AND'
                        ),
                        default => null
                    };
                }

                // Get foreign key values from WHERE IN clause
                $foreignKeyValues = [];
                foreach ($wheres as $where) {
                    // Case-insensitive check for 'in' type
                    if (strtolower($where['type'] ?? '') === 'in') {
                        if (($where['column'] ?? '') === "{$throughTable}.{$this->firstKey}") {
                            $foreignKeyValues = $where['values'] ?? [];
                            break;
                        }
                    }
                }

                // Copy custom select from constraint closure, or use default
                // Use base class helper to check if we should use default selection
                $customColumns = $this->query->getColumns();
                if ($this->shouldUseDefaultSelect($customColumns)) {
                    // Use table-qualified wildcard to select only from related table, not all joined tables
                    $baseQuery->select("{$relatedTable}.*", "{$throughTable}.{$this->firstKey}");
                } else {
                    $baseQuery->select($customColumns);
                    // Check if through key is already in custom columns to avoid duplicate
                    $throughKeyColumn = "{$throughTable}.{$this->firstKey}";
                    $hasKeyColumn = false;
                    foreach ($customColumns as $col) {
                        // Exact match or table-qualified match (authors.city_id)
                        if ($col === $throughKeyColumn || $col === "{$throughTable}.`{$this->firstKey}`") {
                            $hasKeyColumn = true;
                            break;
                        }
                    }
                    if (!$hasKeyColumn) {
                        $baseQuery->selectRaw($throughKeyColumn);
                    }
                }

                // Copy orderBy from constraint closure
                $customOrders = $this->query->getOrders();
                if (!empty($customOrders)) {
                    foreach ($customOrders as $order) {
                        if (!isset($order['column'])) continue;
                        $baseQuery->orderBy($order['column'], $order['direction'] ?? 'ASC');
                    }
                }

                // Build base query SQL and bindings
                $baseQuerySql = $baseQuery->toSql();
                $baseQueryBindings = $baseQuery->getBindings();

                // Build optimized window function query for HasManyThrough
                // Partition by firstKey (through table foreign key) to get top N per parent
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

                // Window query: partition by first key and filter by parent IDs
                // Note: We use toporia_base.first_key because baseQuery includes it in SELECT
                $windowQuery = "SELECT * FROM (SELECT toporia_base.*, ROW_NUMBER() OVER (PARTITION BY toporia_base.{$wrappedFirstKey} ORDER BY {$orderByClause}) AS toporia_row FROM ({$baseQuerySql}) AS toporia_base WHERE toporia_base.{$wrappedFirstKey} IN ({$placeholders})) AS toporia_table WHERE {$rowFilter} ORDER BY toporia_row";

                // Combine bindings: base query bindings + foreign key values
                // PERFORMANCE: Use spread operator for better performance with small arrays
                $allBindings = [...$baseQueryBindings, ...$foreignKeyValues];

                // Execute optimized window function query
                $rows = $connection->select($windowQuery, $allBindings);

                if (empty($rows)) {
                    return new ModelCollection([]);
                }

                return $this->relatedClass::hydrate($rows);
            }
        }

        // Fallback to standard query execution
        // CRITICAL FIX: Must SELECT through key for buildDictionary() to work
        // Without through key, match() cannot group results by parent model
        $this->query->select("{$relatedTable}.*", "{$throughTable}.{$this->firstKey}");

        $rowCollection = $this->query->get();

        // If already a ModelCollection, return as is
        if ($rowCollection instanceof ModelCollection) {
            return $rowCollection;
        }

        // Convert RowCollection to array
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
     */
    public function addEagerConstraints(array $models): void
    {
        $throughTable = $this->getThroughTable();
        $relatedTable = $this->getRelatedTable();

        $keys = array_map(fn($m) => $m->getAttribute($this->localKey), $models);

        // Save original query to copy constraints from it
        $originalQuery = $this->query;

        // Create new query for eager loading
        $this->query = $this->query->newQuery()->table($relatedTable);

        $this->query->join(
            $throughTable,
            "{$throughTable}.{$this->secondLocalKey}",
            '=',
            "{$relatedTable}.{$this->foreignKey}"
        );

        // Temporarily set query back to original to copy constraints
        $tempQuery = $this->query;
        $this->query = $originalQuery;

        // Copy where constraints from original query (excluding through and parent-specific constraints)
        $this->copyWhereConstraints($tempQuery, [
            $this->firstKey,
            $this->foreignKey,
            fn($col) => Str::contains($col, $throughTable . '.') ||
                $col === $this->firstKey ||
                $col === $this->foreignKey ||
                Str::endsWith($col, '.' . $this->firstKey) ||
                Str::endsWith($col, '.' . $this->foreignKey)
        ]);

        // Copy ORDER BY from original query (for window function support)
        $customOrders = $originalQuery->getOrders();
        if (!empty($customOrders)) {
            foreach ($customOrders as $order) {
                if (!isset($order['column'])) continue;
                $tempQuery->orderBy($order['column'], $order['direction'] ?? 'ASC');
            }
        }

        // Copy limit and offset for per-parent limiting with window functions
        if ($originalQuery->getLimit() !== null) {
            $tempQuery->limit($originalQuery->getLimit());
        }
        if ($originalQuery->getOffset() !== null) {
            $tempQuery->offset($originalQuery->getOffset());
        }

        // Restore the new query
        $this->query = $tempQuery;

        // CRITICAL FIX: Wrap existing WHERE conditions to handle OR operator precedence
        // If user callback has OR conditions, we need to wrap them in parentheses
        // Example: (is_verified = 1 OR rating >= 4.0) AND cities.id IN (...)
        $existingWheres = $this->query->getWheres();
        if (!empty($existingWheres)) {
            // Check if any existing WHERE uses OR operator
            $hasOrOperator = false;
            foreach ($existingWheres as $where) {
                if (isset($where['boolean']) && strtoupper($where['boolean']) === 'OR') {
                    $hasOrOperator = true;
                    break;
                }
            }

            if ($hasOrOperator) {
                // Save existing wheres
                $originalWheres = $existingWheres;

                // PERFORMANCE FIX: Use public methods instead of reflection (10-20x faster)
                $whereBindings = $this->query->getBindingsByType('where');
                $this->query->setWheres([]);
                $this->query->setBindings([], 'where');

                // Wrap existing conditions in nested WHERE
                $this->query->where(function ($q) use ($originalWheres, $whereBindings) {
                    // Track binding index
                    $bindingIndex = 0;
                    $isFirst = true;

                    // Manually reconstruct WHERE conditions in nested closure
                    foreach ($originalWheres as $where) {
                        // Determine boolean operator
                        $boolean = $isFirst ? 'AND' : ($where['boolean'] ?? 'AND');
                        $useOr = !$isFirst && strtoupper($boolean) === 'OR';
                        $isFirst = false;

                        match ($where['type'] ?? '') {
                            'basic' => $useOr
                                ? $q->orWhere(
                                    $where['column'],
                                    $where['operator'] ?? '=',
                                    $whereBindings[$bindingIndex++] ?? ($where['value'] ?? null)
                                )
                                : $q->where(
                                    $where['column'],
                                    $where['operator'] ?? '=',
                                    $whereBindings[$bindingIndex++] ?? ($where['value'] ?? null)
                                ),
                            'Null' => $useOr ? $q->orWhereNull($where['column']) : $q->whereNull($where['column']),
                            'NotNull' => $useOr ? $q->orWhereNotNull($where['column']) : $q->whereNotNull($where['column']),
                            'In' => (function () use ($q, $where, &$whereBindings, &$bindingIndex, $useOr) {
                                $values = $where['values'] ?? [];
                                $count = count($values);
                                $boundValues = array_slice($whereBindings, $bindingIndex, $count);
                                $bindingIndex += $count;
                                $useOr
                                    ? $q->orWhereIn($where['column'], $boundValues ?: $values)
                                    : $q->whereIn($where['column'], $boundValues ?: $values);
                            })(),
                            'NotIn' => (function () use ($q, $where, &$whereBindings, &$bindingIndex, $useOr) {
                                $values = $where['values'] ?? [];
                                $count = count($values);
                                $boundValues = array_slice($whereBindings, $bindingIndex, $count);
                                $bindingIndex += $count;
                                $useOr
                                    ? $q->orWhereNotIn($where['column'], $boundValues ?: $values)
                                    : $q->whereNotIn($where['column'], $boundValues ?: $values);
                            })(),
                            'Raw' => $useOr
                                ? $q->orWhereRaw($where['sql'] ?? '', $where['bindings'] ?? [])
                                : $q->whereRaw($where['sql'] ?? '', $where['bindings'] ?? []),
                            default => null
                        };
                    }
                });
            }
        }

        $this->query->whereIn("{$throughTable}.{$this->firstKey}", $keys);

        // Set SELECT columns - check if original query has custom select
        $customColumns = $originalQuery->getColumns();
        if ($this->shouldUseDefaultSelect($customColumns)) {
            // No custom select - use default
            $this->query->select("{$relatedTable}.*", "{$throughTable}.{$this->firstKey}");
        } else {
            // Has custom select - use it and add through key if not already present
            $this->query->select($customColumns);
            $throughKeyColumn = "{$throughTable}.{$this->firstKey}";
            $hasKeyColumn = false;
            foreach ($customColumns as $col) {
                // Exact match or table-qualified match (authors.city_id)
                if ($col === $throughKeyColumn || $col === "{$throughTable}.`{$this->firstKey}`") {
                    $hasKeyColumn = true;
                    break;
                }
            }
            if (!$hasKeyColumn) {
                $this->query->selectRaw($throughKeyColumn);
            }
        }

        // Apply soft delete scope if related model uses soft deletes
        $this->applySoftDeleteScope($this->query, $this->relatedClass, $relatedTable);
    }

    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     *
     * PERFORMANCE: Optimized dictionary building for collection relations.
     * Collection relations (HasManyThrough) return empty ModelCollection when empty.
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
            $localValue = $model->getAttribute($this->localKey);
            // Use isset() for O(1) lookup instead of ?? operator with array access
            $related = isset($dictionary[$localValue]) ? $dictionary[$localValue] : [];
            $model->setRelation($relationName, new ModelCollection($related));
        }

        return $models;
    }

    /**
     * Build dictionary for eager loading matching.
     *
     * @return array<int|string, array<Model>>
     */
    protected function buildDictionary(ModelCollection $results): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->firstKey);
            if (!isset($dictionary[$key])) {
                $dictionary[$key] = [];
            }
            $dictionary[$key][] = $result;
        }
        return $dictionary;
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
            $this->throughClass,
            $this->firstKey,
            $this->foreignKey,
            $this->localKey,
            $this->secondLocalKey
        );

        // Set up the query with proper JOIN but without parent WHERE constraints
        $throughTable = $instance->getThroughTable();
        $relatedTable = $instance->getRelatedTable();

        $cleanQuery = $this->relatedClass::query()
            ->join(
                $throughTable,
                "{$throughTable}.{$this->secondLocalKey}",
                '=',
                "{$relatedTable}.{$this->foreignKey}"
            );

        // Copy select from freshQuery if provided
        $selects = $freshQuery->getColumns();
        if (!empty($selects)) {
            $cleanQuery->select($selects);
        } else {
            $cleanQuery->select("{$relatedTable}.*");
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

        $instance->setQuery($cleanQuery);

        // Copy where constraints from original query (excluding through and parent-specific constraints)
        $this->copyWhereConstraints($cleanQuery, [
            $this->firstKey,
            $this->foreignKey,
            fn($col) => Str::contains($col, $throughTable . '.') ||
                $col === $this->firstKey ||
                $col === $this->foreignKey ||
                Str::endsWith($col, '.' . $this->firstKey) ||
                Str::endsWith($col, '.' . $this->foreignKey)
        ]);

        return $instance;
    }

    // =========================================================================
    // QUERY METHODS
    // =========================================================================

    /**
     * Check if any related models exist.
     */
    public function exists(): bool
    {
        return $this->parent->exists() && $this->query->exists();
    }

    /**
     * Get the count of related models.
     */
    public function count(): int
    {
        return $this->parent->exists() ? $this->query->count() : 0;
    }

    // =========================================================================
    // CRUD OPERATIONS
    // =========================================================================

    /**
     * Update all related models through the intermediate relationship.
     */
    public function update(array $attributes): int
    {
        return $this->parent->exists() ? $this->query->update($attributes) : 0;
    }

    /**
     * Delete all related models through the intermediate relationship.
     */
    public function delete(): int
    {
        return $this->parent->exists() ? $this->query->delete() : 0;
    }

    /**
     * Get the first related model or fail.
     *
     * @throws \RuntimeException If no model found
     */
    public function firstOrFail(): Model
    {
        $results = $this->getResults();

        if ($results->isEmpty()) {
            throw new \RuntimeException('No related models found through intermediate relationship');
        }

        $first = $results->first();
        if (!$first instanceof Model) {
            throw new \RuntimeException('No related models found through intermediate relationship');
        }

        return $first;
    }

    // =========================================================================
    // CHUNKING METHODS
    // =========================================================================

    /**
     * Process records in chunks to optimize memory usage.
     * Note: Uses OFFSET/LIMIT which can be slow on large tables.
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
     * Process records in chunks ordered by ID for consistent results.
     */
    public function chunkById(int $count, callable $callback, string $column = 'id', ?string $alias = null): bool
    {
        $alias ??= $column;
        $lastId = null;
        $relatedTable = $this->getRelatedTable();

        do {
            $clone = clone $this->query;

            if ($lastId !== null) {
                $clone->where("{$relatedTable}.{$column}", '>', $lastId);
            }

            $results = $clone->orderBy("{$relatedTable}.{$column}")->limit($count)->get();

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

    // =========================================================================
    // AGGREGATION METHODS
    // =========================================================================

    /**
     * Get the sum of a column through the relationship.
     */
    public function sum(string $column): float|int
    {
        return $this->query->sum($column) ?? 0;
    }

    /**
     * Get the average of a column through the relationship.
     */
    public function avg(string $column): float|int
    {
        return $this->query->avg($column) ?? 0;
    }

    /**
     * Get the minimum value of a column through the relationship.
     */
    public function min(string $column): mixed
    {
        return $this->query->min($column);
    }

    /**
     * Get the maximum value of a column through the relationship.
     */
    public function max(string $column): mixed
    {
        return $this->query->max($column);
    }

    // =========================================================================
    // FINDER METHODS
    // =========================================================================

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
     * $paginator = $country->posts()->cursorPaginate(50);
     *
     * // Next page (using cursor from previous response)
     * $paginator = $country->posts()->cursorPaginate(50, $request->get('cursor'));
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
     */
    public function find(mixed $id, array $columns = ['*']): ?Model
    {
        $relatedTable = $this->getRelatedTable();
        return $this->query->where("{$relatedTable}.id", $id)->select($columns)->first();
    }

    /**
     * Find multiple related models by their primary keys.
     */
    public function findMany(array $ids, array $columns = ['*']): ModelCollection
    {
        if (empty($ids)) {
            return new ModelCollection([]);
        }

        $relatedTable = $this->getRelatedTable();
        $results = $this->query->whereIn("{$relatedTable}.id", $ids)->select($columns)->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    // =========================================================================
    // GETTER METHODS
    // =========================================================================

    /**
     * Get the through model class name.
     *
     * @return class-string<Model>
     */
    public function getThroughClass(): string
    {
        return $this->throughClass;
    }

    /**
     * Get the first key (foreign key on through table).
     */
    public function getFirstKey(): string
    {
        return $this->firstKey;
    }

    /**
     * Get the second local key (local key on through table).
     */
    public function getSecondLocalKey(): string
    {
        return $this->secondLocalKey;
    }

    // =========================================================================
    // THROUGH TABLE METHODS
    // =========================================================================

    /**
     * Add constraints on the through table.
     */
    public function whereThrough(string $column, mixed $operator, mixed $value = null): static
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $throughTable = $this->getThroughTable();
        $this->query->where("{$throughTable}.{$column}", $operator, $value);

        return $this;
    }

    /**
     * Add whereIn constraint on the through table.
     */
    public function whereThroughIn(string $column, array $values): static
    {
        $throughTable = $this->getThroughTable();
        $this->query->whereIn("{$throughTable}.{$column}", $values);

        return $this;
    }

    /**
     * Add order by clause on the through table.
     */
    public function orderByThrough(string $column, string $direction = 'asc'): static
    {
        $throughTable = $this->getThroughTable();
        $this->query->orderBy("{$throughTable}.{$column}", $direction);

        return $this;
    }

    /**
     * Select additional columns from the through table.
     */
    public function selectThrough(string ...$columns): static
    {
        $throughTable = $this->getThroughTable();
        $relatedTable = $this->getRelatedTable();

        $selectColumns = ["{$relatedTable}.*"];
        foreach ($columns as $column) {
            $selectColumns[] = "{$throughTable}.{$column} as through_{$column}";
        }

        $this->query->select($selectColumns);

        return $this;
    }

    /**
     * Get models with specific through table attributes.
     */
    public function getByThrough(array $throughAttributes, array $columns = ['*']): ModelCollection
    {
        $query = clone $this->query;
        $throughTable = $this->getThroughTable();

        foreach ($throughAttributes as $column => $value) {
            $query->where("{$throughTable}.{$column}", $value);
        }

        $results = $query->select($columns)->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    /**
     * Get distinct values from the through table.
     */
    public function distinctThrough(string $column): array
    {
        $throughTable = $this->getThroughTable();

        $connection = $this->query->getConnection();
        $qb = new QueryBuilder($connection);

        return $qb->table($throughTable)
            ->distinct()
            ->pluck($column)
            ->toArray();
    }

    /**
     * Get aggregated values from the through table.
     */
    public function aggregateThrough(string $function, string $column): mixed
    {
        $throughTable = $this->getThroughTable();

        $connection = $this->query->getConnection();
        $qb = new QueryBuilder($connection);

        $query = $qb->table($throughTable)
            ->where($this->firstKey, $this->parent->getAttribute($this->localKey));

        return match (strtolower($function)) {
            'sum' => $query->sum($column),
            'avg' => $query->avg($column),
            'min' => $query->min($column),
            'max' => $query->max($column),
            'count' => $query->count($column),
            default => throw new \InvalidArgumentException("Unsupported aggregation function: {$function}")
        };
    }

    // =========================================================================
    // QUERY EXTENSION METHODS
    // =========================================================================

    /**
     * Get related models with specific attributes.
     */
    public function getBy(array $attributes, array $columns = ['*']): ModelCollection
    {
        $query = clone $this->query;
        $relatedTable = $this->getRelatedTable();

        foreach ($attributes as $column => $value) {
            $query->where("{$relatedTable}.{$column}", $value);
        }

        $results = $query->select($columns)->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    /**
     * Get the latest related models.
     */
    public function latest(int $limit = 10, string $column = 'created_at'): ModelCollection
    {
        $relatedTable = $this->getRelatedTable();
        $results = $this->query->orderBy("{$relatedTable}.{$column}", 'desc')->limit($limit)->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    /**
     * Get the oldest related models.
     */
    public function oldest(int $limit = 10, string $column = 'created_at'): ModelCollection
    {
        $relatedTable = $this->getRelatedTable();
        $results = $this->query->orderBy("{$relatedTable}.{$column}", 'asc')->limit($limit)->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    /**
     * Get random related models.
     */
    public function random(int $limit = 1): ModelCollection
    {
        $results = $this->query->orderByRaw('RAND()')->limit($limit)->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    /**
     * Get models created within a date range.
     */
    public function createdBetween(string $startDate, string $endDate, string $column = 'created_at'): ModelCollection
    {
        $relatedTable = $this->getRelatedTable();
        $results = $this->query->whereBetween("{$relatedTable}.{$column}", [$startDate, $endDate])->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    /**
     * Get models created today.
     */
    public function createdToday(string $column = 'created_at'): ModelCollection
    {
        $today = now()->toDateString();
        $relatedTable = $this->getRelatedTable();
        $results = $this->query->whereRaw("DATE({$relatedTable}.{$column}) = ?", [$today])->get();
        return $this->relatedClass::hydrate($results->toArray());
    }

    // =========================================================================
    // MAGIC METHODS
    // =========================================================================

    /**
     * Magic method to delegate calls to the underlying query builder.
     *
     * Auto-qualifies column names for WHERE methods to avoid ambiguity.
     * HasManyThrough has JOIN with through table, so unqualified columns are ambiguous.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (method_exists($this->query, $method)) {
            // Auto-qualify column names for WHERE methods to avoid ambiguity
            $whereMethods = ['where', 'orWhere', 'whereColumn', 'orWhereColumn'];
            if (in_array($method, $whereMethods) && !empty($parameters[0]) && is_string($parameters[0])) {
                $column = $parameters[0];
                // Only qualify if column is not already qualified (no dot)
                if (!str_contains($column, '.')) {
                    $relatedTable = $this->getRelatedTable();
                    $parameters[0] = "{$relatedTable}.{$column}";
                }
            }

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
