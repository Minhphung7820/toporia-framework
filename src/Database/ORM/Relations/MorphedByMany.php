<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Relations;

use Toporia\Framework\Database\ORM\{Model, ModelCollection};
use Toporia\Framework\Database\Query\{QueryBuilder, RowCollection};
use Toporia\Framework\Support\Str;

/**
 * MorphedByMany Relationship
 *
 * Handles inverse polymorphic many-to-many relationships.
 * This is the inverse of MorphToMany.
 *
 * Example: Tag morphedByMany Posts/Videos
 * - Tag has many Posts through taggables pivot
 * - Tag has many Videos through taggables pivot
 *
 * Pivot table structure:
 * - taggable_type: The class name of the morph model (Post, Video)
 * - taggable_id: The ID of the morph model
 * - tag_id: The ID of the related model (Tag)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Relations
 * @since       2025-01-10
 */
class MorphedByMany extends Relation
{
    /** @var string Pivot table name */
    protected string $pivotTable;

    /** @var string Morph type column */
    protected string $morphType;

    /** @var string Parent pivot key (tag_id) */
    protected string $parentPivotKey;

    /** @var string Parent key (id on tags table) */
    protected string $parentKey;

    /** @var string Related key (id on posts/videos table) */
    protected string $relatedKey;

    /** @var array<string> Additional pivot columns to select */
    protected array $pivotColumns = [];

    /** @var bool Whether to include all pivot columns (lazy loaded) */
    protected bool $withPivotAll = false;

    /** @var bool Whether to include timestamps in pivot table */
    protected bool $withTimestamps = false;

    /** @var string Custom pivot accessor name */
    protected string $pivotAccessor = 'pivot';

    /** @var array<array{column: string, operator: string, value: mixed}> Pivot where constraints */
    protected array $pivotWheres = [];

    /** @var array<array{column: string, values: array}> Pivot whereIn constraints */
    protected array $pivotWhereIns = [];

    /** @var array<array{column: string, direction: string}> Pivot order by clauses */
    protected array $pivotOrderBy = [];

    /** @var class-string|null Custom pivot model class */
    protected ?string $pivotClass = null;

    /** @var string|null Cached related table name */
    private ?string $relatedTableCache = null;

    /** @var array<string, array{columns: array<string>, timestamp: int}> Schema cache for table columns */
    private static array $schemaCache = [];

    /** @var int Schema cache TTL in seconds (5 minutes) */
    private static int $schemaCacheTtl = 300;

    /**
     * Create a new MorphedByMany relationship instance.
     *
     * @param QueryBuilder $query Query builder
     * @param Model $parent Parent model (Tag)
     * @param class-string<Model> $relatedClass Related model class (Post)
     * @param string $morphName Morph name ('taggable')
     * @param string|null $pivotTable Pivot table name (taggables)
     * @param string|null $morphType Type column (taggable_type)
     * @param string|null $morphId ID column (taggable_id)
     * @param string|null $parentKey Parent key (tag_id in pivot)
     * @param string|null $localKey Local key (id on parent)
     * @param string|null $relatedPrimaryKey Related primary key (id on related)
     */
    public function __construct(
        QueryBuilder $query,
        Model $parent,
        protected string $relatedClass,
        protected string $morphName,
        ?string $pivotTable = null,
        ?string $morphType = null,
        ?string $morphId = null,
        ?string $parentKey = null,
        ?string $localKey = null,
        ?string $relatedPrimaryKey = null
    ) {
        $this->pivotTable = $pivotTable ?? $this->guessPivotTable();
        $this->morphType = $morphType ?? "{$morphName}_type";
        $this->foreignKey = $morphId ?? "{$morphName}_id"; // This is taggable_id (the morph model's ID)
        $this->parentPivotKey = $parentKey ?? $this->guessParentKey();
        $this->localKey = $localKey ?? $parent::getPrimaryKey();
        $this->relatedKey = $relatedPrimaryKey ?? $this->relatedClass::getPrimaryKey();
        $this->parentKey = $this->localKey;

        parent::__construct($query, $parent, $this->foreignKey, $this->localKey);

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
     * Get cached related table name.
     */
    public function getRelatedTable(): string
    {
        return $this->relatedTableCache ??= $this->relatedClass::getTableName();
    }

    /**
     * Guess pivot table name.
     */
    protected function guessPivotTable(): string
    {
        return $this->morphName . 's';
    }

    /**
     * Guess parent key name in pivot table.
     */
    protected function guessParentKey(): string
    {
        $parts = explode('\\', get_class($this->parent));
        $className = end($parts);

        // Strip "Model" suffix if present (e.g., TagModel -> tag_id)
        if (Str::endsWith($className, 'Model')) {
            $className = substr($className, 0, -5); // Remove "Model"
        }

        return strtolower($className) . '_id';
    }

    /**
     * Get morph class name/alias for related model.
     *
     * For MorphedByMany, we need the alias of the related class (not parent),
     * since we're looking up by the morphable type in the pivot table.
     *
     * @return string Morph alias or full namespace class name
     */
    protected function getMorphClass(): string
    {
        // Check global morph map for alias
        $alias = Relation::getMorphAlias($this->relatedClass);

        return $alias;
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Create a new pivot query builder.
     */
    protected function newPivotQuery(): QueryBuilder
    {
        return (new QueryBuilder($this->query->getConnection()))
            ->table($this->pivotTable)
            ->where($this->parentPivotKey, $this->parent->getAttribute($this->localKey))
            ->where($this->morphType, $this->getMorphClass());
    }

    /**
     * Build dictionary for eager loading matching.
     *
     * @param ModelCollection $results
     * @return array<string, array<Model>>
     */
    protected function buildDictionary(ModelCollection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            // Get parentPivotKey from pivot attributes (with pivot_ prefix)
            $parentId = $result->getAttribute("pivot_{$this->parentPivotKey}");

            if ($parentId !== null) {
                $key = (string) $parentId;
                if (!isset($dictionary[$key])) {
                    $dictionary[$key] = [];
                }
                $dictionary[$key][] = $result;
            }
        }

        return $dictionary;
    }

    // =========================================================================
    // CORE RELATION METHODS
    // =========================================================================

    /**
     * Perform join with pivot table.
     */
    protected function performJoin(): void
    {
        $relatedTable = $this->getRelatedTable();

        $this->query->join(
            $this->pivotTable,
            "{$relatedTable}.{$this->relatedKey}",
            '=',
            "{$this->pivotTable}.{$this->foreignKey}"
        );

        if ($this->parent->exists()) {
            $this->query->where("{$this->pivotTable}.{$this->morphType}", $this->getMorphClass());
            $this->query->where("{$this->pivotTable}.{$this->parentPivotKey}", $this->parent->getAttribute($this->localKey));
        }

        // FIXED: Apply pivot constraints AFTER pivot join is created
        // This ensures wherePivot from relationship definition works correctly
        $this->applyPivotConstraintsToQuery($this->query);

        // Apply soft delete scope if related model uses soft deletes
        $this->applySoftDeleteScope($this->query, $this->relatedClass, $relatedTable);
    }

    /**
     * {@inheritdoc}
     */
    public function getResults(): ModelCollection
    {
        $relatedTable = $this->getRelatedTable();

        // Check if this is eager loading with limit (needs window function optimization)
        $wheres = $this->query->getWheres();

        // Use base class helper method with recursion depth limit
        $isEagerLoading = $this->findWhereInRecursive($wheres, $this->pivotTable);

        // If eager loading with limit, use window function for optimal performance
        if ($isEagerLoading && !$this->parent->exists()) {
            $orders = $this->query->getOrders();
            $limit = $this->query->getLimit();
            $offset = $this->query->getOffset();

            if ($limit !== null && $limit > 0) {
                $grammar = $this->query->getConnection()->getGrammar();

                // Check if database supports window functions
                if (!$grammar->supportsFeature('window_functions')) {
                    // Fallback: Execute query without per-parent limit optimization
                    $this->query->select("{$relatedTable}.*");
                    $rows = $this->query->get();
                    if ($rows->isEmpty()) {
                        return new ModelCollection([]);
                    }
                    return $this->relatedClass::hydrate($rows->toArray());
                }

                // Build window function query
                // Use pivot alias (not table-qualified name) because baseQuery selects it as "pivot_*"
                $parentPivotKeyAlias = $grammar->wrapColumn("pivot_{$this->parentPivotKey}");

                // Build ORDER BY clause
                // IMPORTANT: When using pivot columns (orderByPivot), we need to reference them
                // using their pivot aliases (e.g., toporia_base.pivot_order instead of pivot_table.order)
                // because in the window function subquery, pivot columns are aliased as pivot_*
                $orderParts = [];
                if (!empty($orders)) {
                    foreach ($orders as $order) {
                        $col = $order['column'] ?? '';
                        $dir = strtoupper($order['direction'] ?? 'ASC');

                        // Check if this is a pivot table column
                        $isPivotColumn = false;
                        $pivotColumnName = null;

                        if (str_contains($col, '.')) {
                            $parts = explode('.', $col, 2);
                            $tableName = $parts[0];
                            $columnName = $parts[1];

                            // If column is from pivot table, use pivot alias
                            if ($tableName === $this->pivotTable) {
                                $isPivotColumn = true;
                                $pivotColumnName = $columnName;
                            }
                        }

                        if ($isPivotColumn) {
                            // For pivot columns, reference using toporia_base.pivot_* alias
                            $wrappedCol = 'toporia_base.' . $grammar->wrapColumn("pivot_{$pivotColumnName}");
                        } else {
                            // For regular columns, just wrap the column name
                            $wrappedCol = $grammar->wrapColumn($col);
                        }

                        $orderParts[] = "{$wrappedCol} {$dir}";
                    }
                } else {
                    $primaryKey = $this->relatedClass::getPrimaryKey();
                    $wrappedPrimaryKey = $grammar->wrapColumn($primaryKey);
                    $orderParts[] = "{$wrappedPrimaryKey} ASC";
                }
                $orderByClause = implode(', ', $orderParts);

                // Get parent pivot key values from WHERE IN clause
                $parentPivotKeyValues = [];
                foreach ($wheres as $where) {
                    if (strtolower($where['type'] ?? '') === 'in') {
                        $column = $where['column'] ?? '';
                        if (str_contains($column, $this->pivotTable) && str_contains($column, $this->parentPivotKey)) {
                            $parentPivotKeyValues = $where['values'] ?? [];
                            break;
                        }
                    }
                }

                // Build base query
                $connection = $this->query->getConnection();
                $baseQuery = new QueryBuilder($connection);
                $baseQuery->table($relatedTable)
                    ->join(
                        $this->pivotTable,
                        "{$relatedTable}.{$this->relatedKey}",
                        '=',
                        "{$this->pivotTable}.{$this->foreignKey}"
                    );

                // Copy all where conditions except WHERE IN for parent pivot key
                foreach ($wheres as $where) {
                    if (strtolower($where['type'] ?? '') === 'in') {
                        $column = $where['column'] ?? '';
                        if (str_contains($column, $this->pivotTable) && str_contains($column, $this->parentPivotKey)) {
                            continue; // Skip, handled in window function
                        }
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

                // Copy custom select or use default
                // Use base class helper to check if we should use default selection
                $customColumns = $this->query->getColumns();
                if ($this->shouldUseDefaultSelect($customColumns)) {
                    // Use table-qualified wildcard to select only from related table, not joined pivot table
                    $baseQuery->select("{$relatedTable}.*");
                } else {
                    $baseQuery->select($customColumns);
                }

                // Always need pivot key for matching
                $baseQuery->selectRaw("{$this->pivotTable}.{$this->parentPivotKey} as pivot_{$this->parentPivotKey}");

                // Include additional pivot columns if needed
                if ($this->shouldIncludePivot()) {
                    $baseQuery->selectRaw("{$this->pivotTable}.{$this->morphType} as pivot_{$this->morphType}");
                    $baseQuery->selectRaw("{$this->pivotTable}.{$this->foreignKey} as pivot_{$this->foreignKey}");
                    foreach ($this->pivotColumns as $column) {
                        if (!in_array($column, [$this->parentPivotKey, $this->morphType, $this->foreignKey])) {
                            $baseQuery->selectRaw("{$this->pivotTable}.{$column} as pivot_{$column}");
                        }
                    }
                }

                // Build base query SQL and bindings
                $baseQuerySql = $baseQuery->toSql();
                $baseQueryBindings = $baseQuery->getBindings();

                // Build window function query
                $placeholders = implode(',', array_fill(0, count($parentPivotKeyValues), '?'));

                if ($offset !== null && $offset > 0) {
                    $rowFilter = "toporia_row > {$offset} AND toporia_row <= " . ($offset + $limit);
                } else {
                    $rowFilter = "toporia_row <= {$limit}";
                }

                // Window query: partition by pivot alias and filter by parent IDs
                // Note: We use toporia_base.pivot_* aliases because baseQuery selects pivot columns as "pivot_*"
                $windowQuery = "SELECT * FROM (SELECT toporia_base.*, ROW_NUMBER() OVER (PARTITION BY toporia_base.{$parentPivotKeyAlias} ORDER BY {$orderByClause}) AS toporia_row FROM ({$baseQuerySql}) AS toporia_base WHERE toporia_base.{$parentPivotKeyAlias} IN ({$placeholders})) AS toporia_table WHERE {$rowFilter} ORDER BY toporia_row";

                // Combine bindings
                // PERFORMANCE: Use spread operator for better performance with small arrays
                $allBindings = [...$baseQueryBindings, ...$parentPivotKeyValues];

                // Execute window function query
                $rows = $connection->select($windowQuery, $allBindings);

                if (empty($rows)) {
                    return new ModelCollection([]);
                }

                return $this->relatedClass::hydrate($rows);
            }
        }

        // Standard execution path (lazy loading or eager without limit)
        if ($this->parent->exists()) {
            $freshQuery = $this->query->newQuery()->table($relatedTable);

            $freshQuery->join(
                $this->pivotTable,
                "{$relatedTable}.{$this->relatedKey}",
                '=',
                "{$this->pivotTable}.{$this->foreignKey}"
            );

            $freshQuery->where("{$this->pivotTable}.{$this->morphType}", $this->getMorphClass());
            $freshQuery->where("{$this->pivotTable}.{$this->parentPivotKey}", $this->parent->getAttribute($this->localKey));
            $freshQuery->select("{$relatedTable}.*");

            // Apply soft delete scope
            $this->applySoftDeleteScope($freshQuery, $this->relatedClass, $relatedTable);

            $rowCollection = $freshQuery->get();
        } else {
            $this->query->select("{$relatedTable}.*");
            $rowCollection = $this->query->get();
        }

        $rows = $rowCollection instanceof RowCollection ? $rowCollection->all() : $rowCollection;

        return empty($rows) ? new ModelCollection([]) : $this->relatedClass::hydrate($rows);
    }

    /**
     * {@inheritdoc}
     */
    public function addEagerConstraints(array $models): void
    {
        $relatedTable = $this->getRelatedTable();

        $parentIds = [];
        foreach ($models as $model) {
            $id = $model->getAttribute($this->localKey);
            if ($id !== null) {
                $parentIds[] = $id;
            }
        }

        $this->query = $this->query->newQuery()->table($relatedTable);

        $this->query->join(
            $this->pivotTable,
            "{$relatedTable}.{$this->relatedKey}",
            '=',
            "{$this->pivotTable}.{$this->foreignKey}"
        );

        // Filter by morph type
        $this->query->where("{$this->pivotTable}.{$this->morphType}", $this->getMorphClass());

        // CRITICAL FIX: Wrap existing WHERE conditions to handle OR operator precedence
        // If user callback has OR conditions, we need to wrap them in parentheses
        // Example: (is_active = 1 OR priority > 5) AND pivot.taggable_id IN (...)
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

        $this->query->whereIn("{$this->pivotTable}.{$this->parentPivotKey}", array_unique($parentIds));

        // Select related table columns
        $this->query->select("{$relatedTable}.*");

        // Always select parentPivotKey for matching
        $this->query->selectRaw("{$this->pivotTable}.{$this->parentPivotKey} as pivot_{$this->parentPivotKey}");

        // Select additional pivot columns if needed
        if ($this->shouldIncludePivot()) {
            // Lazy load pivot columns only when withPivot('*') was used
            if ($this->withPivotAll) {
                $this->ensurePivotColumnsLoaded();
            }

            $this->query->selectRaw("{$this->pivotTable}.{$this->morphType} as pivot_{$this->morphType}");
            $this->query->selectRaw("{$this->pivotTable}.{$this->foreignKey} as pivot_{$this->foreignKey}");

            foreach ($this->pivotColumns as $column) {
                if (!in_array($column, [$this->parentPivotKey, $this->morphType, $this->foreignKey])) {
                    $this->query->selectRaw("{$this->pivotTable}.{$column} as pivot_{$column}");
                }
            }
        }

        // Apply soft delete scope
        $this->applySoftDeleteScope($this->query, $this->relatedClass, $relatedTable);
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
    public function match(array $models, mixed $results, string $relationName): array
    {
        // Ensure results is a ModelCollection
        if (!$results instanceof ModelCollection) {
            $results = new ModelCollection(is_array($results) ? $results : []);
        }

        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = (string) $model->getAttribute($this->localKey);

            if (isset($dictionary[$key])) {
                $relatedModels = $dictionary[$key];

                // Remove pivot_ attributes from related models
                foreach ($relatedModels as $related) {
                    $this->removePivotAttributes($related);
                }

                $model->setRelation($relationName, new ModelCollection($relatedModels));
            } else {
                $model->setRelation($relationName, new ModelCollection([]));
            }
        }

        return $models;
    }

    /**
     * Remove pivot_ prefixed attributes from model.
     */
    protected function removePivotAttributes(Model $model): void
    {
        // Use Model's protected method to remove attributes efficiently
        // This avoids reflection overhead and is much faster
        $model->removeAttributesByPattern('pivot_');
    }

    /**
     * Determine if pivot data should be included.
     */
    protected function shouldIncludePivot(): bool
    {
        return !empty($this->pivotColumns) || $this->withPivotAll || $this->withTimestamps;
    }

    /**
     * Lazy load all pivot columns when actually needed (not during whereHas/exists).
     *
     * IMPORTANT: This method ONLY executes SHOW COLUMNS when:
     * 1. withPivot('*') was called (withPivotAll = true)
     * 2. AND pivot columns haven't been loaded yet (empty pivotColumns)
     *
     * When using withPivot('field1', 'field2'), this method is never called,
     * so SHOW COLUMNS is never executed.
     *
     * This avoids unnecessary SHOW COLUMNS queries.
     */
    protected function ensurePivotColumnsLoaded(): void
    {
        // Only load columns if withPivot('*') was used AND columns not loaded yet
        if ($this->withPivotAll && empty($this->pivotColumns)) {
            // Get all columns from pivot table (with caching for performance)
            $connection = $this->query->getConnection();
            $allColumns = $this->getCachedTableColumns($this->pivotTable, $connection);

            // Exclude parent pivot key, morph type, and foreign key (they're always selected separately)
            $excludeColumns = [$this->parentPivotKey, $this->morphType, $this->foreignKey];

            // Exclude timestamps if withTimestamps() will be called separately
            if (!$this->withTimestamps) {
                $excludeColumns[] = 'created_at';
                $excludeColumns[] = 'updated_at';
            }

            // Filter out excluded columns
            $pivotColumns = array_diff($allColumns ?? [], $excludeColumns);
            $this->pivotColumns = array_values($pivotColumns);
        }
    }

    // =========================================================================
    // PIVOT CONFIGURATION METHODS
    // =========================================================================

    /**
     * Specify additional pivot columns to include.
     */
    public function withPivot(string ...$columns): static
    {
        // Handle wildcard '*' to select all pivot columns
        if (in_array('*', $columns, true)) {
            // Lazy load: Mark that we need all columns, but don't fetch them yet
            // This avoids SHOW COLUMNS query when relation is only used for whereHas/exists checks
            $this->withPivotAll = true;

            // Remove '*' from columns array
            $columns = array_filter($columns, fn($col) => $col !== '*');

            // Merge with existing pivot columns
            $this->pivotColumns = array_values(array_unique([...$this->pivotColumns, ...$columns]));
        } else {
            // Normal case: just add specified columns
            // Deduplicate columns to avoid duplicate alias in SQL queries
            $this->pivotColumns = array_values(array_unique([...$this->pivotColumns, ...$columns]));
        }

        return $this;
    }

    /**
     * Include pivot timestamps.
     */
    public function withTimestamps(): static
    {
        $this->withTimestamps = true;
        $this->pivotColumns = array_unique([...$this->pivotColumns, 'created_at', 'updated_at']);
        return $this;
    }

    /**
     * Set custom pivot accessor name.
     */
    public function as(string $accessor): static
    {
        $this->pivotAccessor = $accessor;
        return $this;
    }

    /**
     * Use a custom pivot model.
     *
     * @param class-string $class
     */
    public function using(string $class): static
    {
        $this->pivotClass = $class;
        return $this;
    }

    // =========================================================================
    // PIVOT CONSTRAINT METHODS
    // =========================================================================

    /**
     * Add a where constraint on a pivot column.
     */
    public function wherePivot(string $column, mixed $operator = null, mixed $value = null): static
    {
        [$operator, $value] = $this->normalizeOperatorValue($operator, $value);

        $this->pivotWheres[] = compact('column', 'operator', 'value');

        // FIXED: Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $this->query->where($this->qualifyPivotColumn($column), $operator, $value);
        }

        return $this;
    }

    /**
     * Add a whereIn constraint on a pivot column.
     */
    public function wherePivotIn(string $column, array $values): static
    {
        $this->pivotWhereIns[] = ['column' => $column, 'values' => $values, 'not' => false];

        // FIXED: Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $this->query->whereIn($this->qualifyPivotColumn($column), $values);
        }

        return $this;
    }

    /**
     * Add a whereNotIn constraint on a pivot column.
     */
    public function wherePivotNotIn(string $column, array $values): static
    {
        $this->pivotWhereIns[] = ['column' => $column, 'values' => $values, 'not' => true];

        // FIXED: Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $this->query->whereNotIn($this->qualifyPivotColumn($column), $values);
        }

        return $this;
    }

    /**
     * Add an "or where" clause for a pivot table column.
     *
     * @param string $column Pivot column name
     * @param mixed $operator Comparison operator or value if no operator provided
     * @param mixed $value Value to compare (optional if operator is omitted)
     * @return $this
     */
    public function orWherePivot(string $column, mixed $operator, mixed $value = null): static
    {
        [$operator, $value] = $this->normalizeOperatorValue($operator, $value);

        $this->pivotWheres[] = compact('column', 'operator', 'value') + ['type' => 'or'];

        // FIXED: Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $this->query->orWhere($this->qualifyPivotColumn($column), $operator, $value);
        }

        return $this;
    }

    /**
     * Add an "or where in" clause for a pivot table column.
     *
     * @param string $column Pivot column name
     * @param array $values Array of values to match
     * @return $this
     */
    public function orWherePivotIn(string $column, array $values): static
    {
        $this->pivotWhereIns[] = ['column' => $column, 'values' => $values, 'not' => false, 'type' => 'or'];

        // FIXED: Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $this->query->orWhereIn($this->qualifyPivotColumn($column), $values);
        }

        return $this;
    }

    /**
     * Add an order by clause on a pivot column.
     */
    public function orderByPivot(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction);
        $this->pivotOrderBy[] = compact('column', 'direction');

        // FIXED: Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $this->query->orderBy($this->qualifyPivotColumn($column), $direction);
        }

        return $this;
    }

    /**
     * Add a date-based constraint on a pivot column.
     *
     * @param string $column Pivot column name
     * @param string $operator Comparison operator
     * @param string $value Date value (Y-m-d format)
     * @return $this
     */
    public function wherePivotDate(string $column, string $operator, string $value): static
    {
        return $this->addPivotFunctionConstraint('DATE', $column, $operator, $value);
    }

    /**
     * Add a month-based constraint on a pivot column.
     *
     * @param string $column Pivot column name
     * @param string $operator Comparison operator
     * @param int $value Month value (1-12)
     * @return $this
     */
    public function wherePivotMonth(string $column, string $operator, int $value): static
    {
        return $this->addPivotFunctionConstraint('MONTH', $column, $operator, $value);
    }

    /**
     * Add a year-based constraint on a pivot column.
     *
     * @param string $column Pivot column name
     * @param string $operator Comparison operator
     * @param int $value Year value
     * @return $this
     */
    public function wherePivotYear(string $column, string $operator, int $value): static
    {
        return $this->addPivotFunctionConstraint('YEAR', $column, $operator, $value);
    }

    /**
     * Add a time-based constraint on a pivot column.
     *
     * @param string $column Pivot column name
     * @param string $operator Comparison operator
     * @param string $value Time value (H:i:s format)
     * @return $this
     */
    public function wherePivotTime(string $column, string $operator, string $value): static
    {
        return $this->addPivotFunctionConstraint('TIME', $column, $operator, $value);
    }

    /**
     * Normalize operator and value for where clauses.
     */
    protected function normalizeOperatorValue(mixed $operator, mixed $value): array
    {
        if ($value === null && $operator !== null && !in_array(strtoupper((string) $operator), ['IS', 'IS NOT', '=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'BETWEEN', 'NOT BETWEEN'])) {
            return ['=', $operator];
        }
        return [$operator ?? '=', $value];
    }

    // =========================================================================
    // PIVOT HELPER METHODS
    // =========================================================================

    /**
     * Qualify a column name with the pivot table prefix.
     */
    protected function qualifyPivotColumn(string $column): string
    {
        return "{$this->pivotTable}.{$column}";
    }

    /**
     * Add a SQL function-based constraint on a pivot column.
     *
     * @param string $function SQL function name (DATE, MONTH, YEAR, TIME, etc.)
     * @param string $column Pivot column name
     * @param string $operator Comparison operator
     * @param mixed $value Value to compare
     * @return $this
     */
    protected function addPivotFunctionConstraint(string $function, string $column, string $operator, mixed $value): static
    {
        $this->pivotWheres[] = [
            'column' => "{$function}({$column})",
            'operator' => $operator,
            'value' => $value,
            'type' => 'function'
        ];

        // FIXED: Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $qualifiedColumn = $this->qualifyPivotColumn($column);
            $this->query->whereRaw("{$function}({$qualifiedColumn}) {$operator} ?", [$value]);
        }

        return $this;
    }

    /**
     * Check if pivot table has already been joined to the query.
     *
     * This is used to determine if wherePivot constraints can be applied immediately
     * or if they need to be stored and applied later.
     *
     * @return bool True if pivot join exists, false otherwise
     */
    protected function hasPivotJoin(): bool
    {
        $joins = $this->query->getJoins();

        if (empty($joins)) {
            return false;
        }

        // Check if any join references the pivot table
        foreach ($joins as $join) {
            if (isset($join['table']) && $join['table'] === $this->pivotTable) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply pivot constraints to a query.
     */
    protected function applyPivotConstraintsToQuery(QueryBuilder $query): void
    {
        foreach ($this->pivotWheres as $where) {
            $column = $where['column'];
            $operator = $where['operator'];
            $value = $where['value'];

            if (Str::contains($column, '(') && Str::contains($column, ')')) {
                $this->applyFunctionBasedPivotConstraint($query, $column, $operator, $value);
            } else {
                $fullColumn = $this->ensurePivotColumnQualified($column);
                $this->applyWhereToQueryBuilder($query, $fullColumn, $operator, $value);
            }
        }

        foreach ($this->pivotWhereIns as $whereIn) {
            $column = $this->ensurePivotColumnQualified($whereIn['column']);

            if (isset($whereIn['not']) && $whereIn['not']) {
                $query->whereNotIn($column, $whereIn['values']);
            } else {
                $query->whereIn($column, $whereIn['values']);
            }
        }
    }

    /**
     * Ensure a column is qualified with the pivot table name.
     */
    protected function ensurePivotColumnQualified(string $column): string
    {
        if (!Str::contains($column, '.')) {
            return $this->qualifyPivotColumn($column);
        }

        if (!Str::startsWith($column, $this->pivotTable . '.')) {
            $columnName = Str::afterLast($column, '.');
            return $this->qualifyPivotColumn($columnName);
        }

        return $column;
    }

    /**
     * Apply a function-based pivot constraint.
     */
    protected function applyFunctionBasedPivotConstraint(QueryBuilder $query, string $column, string $operator, mixed $value): void
    {
        $sqlFunctions = ['DATE', 'MONTH', 'YEAR', 'TIME'];
        foreach ($sqlFunctions as $function) {
            if (Str::startsWith($column, "{$function}(")) {
                $actualColumn = str_replace(["{$function}(", ')'], '', $column);
                $qualifiedColumn = $this->ensurePivotColumnQualified($actualColumn);
                $query->whereRaw("{$function}({$qualifiedColumn}) {$operator} ?", [$value]);
                return;
            }
        }

        if (preg_match('/^(\w+)\(([^)]+)\)$/', $column, $matches)) {
            $functionName = $matches[1];
            $functionColumn = $matches[2];
            $qualifiedColumn = $this->ensurePivotColumnQualified($functionColumn);
            $query->whereRaw("{$functionName}({$qualifiedColumn}) {$operator} ?", [$value]);
        } else {
            $query->whereRaw("{$column} {$operator} ?", [$value]);
        }
    }

    /**
     * Apply a where constraint to a query builder.
     */
    protected function applyWhereToQueryBuilder(QueryBuilder $query, string $column, string $operator, mixed $value): void
    {
        match (true) {
            $operator === 'BETWEEN' && is_array($value) => $query->whereBetween($column, $value),
            $operator === 'NOT BETWEEN' && is_array($value) => $query->whereNotBetween($column, $value),
            $operator === 'IS' && $value === null => $query->whereNull($column),
            $operator === 'IS NOT' && $value === null => $query->whereNotNull($column),
            default => $query->where($column, $operator, $value),
        };
    }

    // =========================================================================
    // ATTACH/DETACH METHODS
    // =========================================================================

    /**
     * Attach a model to the parent via pivot table.
     *
     * @param int|string|array $id Model ID or array of IDs with pivot data
     * @param array<string, mixed> $pivotData Additional pivot data
     * @return bool
     */
    public function attach(int|string|array $id, array $pivotData = []): bool
    {
        if (is_array($id) && !isset($id[0])) {
            // Associative array: [id => [pivot_data]]
            foreach ($id as $relatedId => $data) {
                $this->attachSingle($relatedId, is_array($data) ? $data : []);
            }
            return true;
        }

        if (is_array($id)) {
            foreach ($id as $relatedId) {
                $this->attachSingle($relatedId, $pivotData);
            }
            return true;
        }

        return $this->attachSingle($id, $pivotData);
    }

    /**
     * Attach a single model.
     */
    protected function attachSingle(int|string $id, array $pivotData = []): bool
    {
        $data = [
            $this->parentPivotKey => $this->parent->getAttribute($this->localKey),
            $this->foreignKey => $id,
            $this->morphType => $this->getMorphClass(),
            ...$pivotData,
        ];

        if ($this->withTimestamps) {
            $now = now()->toDateTimeString();
            $data['created_at'] ??= $now;
            $data['updated_at'] ??= $now;
        }

        (new QueryBuilder($this->query->getConnection()))
            ->table($this->pivotTable)
            ->insert($data);

        return true;
    }

    /**
     * Detach models from the parent.
     *
     * @param int|string|array|null $ids IDs to detach, or null for all
     * @return int Number of detached records
     */
    public function detach(int|string|array|null $ids = null): int
    {
        $query = $this->newPivotQuery();

        if ($ids !== null) {
            $ids = is_array($ids) ? $ids : [$ids];
            $query->whereIn($this->foreignKey, $ids);
        }

        return $query->delete();
    }

    /**
     * Sync the relationship with the given IDs.
     *
     * @param array $ids Array of IDs or [id => pivot_data]
     * @param bool $detaching Whether to detach models not in the list
     * @return array{attached: array, detached: array, updated: array}
     */
    public function sync(array $ids, bool $detaching = true): array
    {
        $changes = ['attached' => [], 'detached' => [], 'updated' => []];

        // Get current attached IDs
        $current = $this->newPivotQuery()
            ->select($this->foreignKey)
            ->get()
            ->pluck($this->foreignKey)
            ->toArray();

        // Normalize input
        $records = [];
        foreach ($ids as $key => $value) {
            if (is_numeric($key)) {
                $records[$value] = [];
            } else {
                $records[$key] = is_array($value) ? $value : [];
            }
        }

        // Detach removed IDs
        if ($detaching) {
            $toDetach = array_diff($current, array_keys($records));
            if (!empty($toDetach)) {
                $this->detach($toDetach);
                $changes['detached'] = array_values($toDetach);
            }
        }

        // Attach new IDs
        $toAttach = array_diff(array_keys($records), $current);
        foreach ($toAttach as $id) {
            $this->attachSingle($id, $records[$id]);
            $changes['attached'][] = $id;
        }

        return $changes;
    }

    /**
     * Toggle the attachment of the given IDs.
     *
     * @param array $ids Array of IDs to toggle
     * @return array{attached: array, detached: array}
     */
    public function toggle(array $ids): array
    {
        $changes = ['attached' => [], 'detached' => []];

        $current = $this->newPivotQuery()
            ->select($this->foreignKey)
            ->get()
            ->pluck($this->foreignKey)
            ->toArray();

        $toAttach = array_diff($ids, $current);
        $toDetach = array_intersect($ids, $current);

        if (!empty($toDetach)) {
            $this->detach($toDetach);
            $changes['detached'] = array_values($toDetach);
        }

        foreach ($toAttach as $id) {
            $this->attachSingle($id, []);
            $changes['attached'][] = $id;
        }

        return $changes;
    }

    // =========================================================================
    // QUERY METHODS
    // =========================================================================

    /**
     * Get the first result or null.
     */
    public function first(): ?Model
    {
        $results = $this->getResults();
        return $results->first();
    }

    /**
     * Get results count.
     */
    public function count(): int
    {
        return $this->newPivotQuery()->count();
    }

    /**
     * Check if any related models exist.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Check if related models don't exist.
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    // =========================================================================
    // EAGER LOADING SUPPORT
    // =========================================================================

    /**
     * Create new instance for eager loading.
     */
    public function newEagerInstance(QueryBuilder $freshQuery): static
    {
        // Create a dummy parent without ID to avoid parent-specific constraints
        $dummyParent = new ($this->parent::class)();

        $instance = new static(
            $freshQuery,
            $dummyParent,
            $this->relatedClass,
            $this->morphName,
            $this->pivotTable,
            $this->morphType,
            $this->foreignKey,
            $this->parentPivotKey,
            $this->localKey,
            $this->relatedKey
        );

        // Preserve all pivot settings from original relation
        $instance->pivotColumns = $this->pivotColumns;
        $instance->withPivotAll = $this->withPivotAll;
        $instance->withTimestamps = $this->withTimestamps;
        $instance->pivotAccessor = $this->pivotAccessor;
        $instance->pivotWheres = $this->pivotWheres;
        $instance->pivotWhereIns = $this->pivotWhereIns;
        $instance->pivotOrderBy = $this->pivotOrderBy;
        $instance->pivotClass = $this->pivotClass;

        // Set up the query with proper JOIN but without parent WHERE constraints
        $relatedTable = $this->getRelatedTable();

        // Create a fresh query from the related model (this ensures table name is set)
        $cleanQuery = $this->relatedClass::query()
            ->join(
                $this->pivotTable,
                "{$relatedTable}.{$this->relatedKey}",
                '=',
                "{$this->pivotTable}.{$this->foreignKey}"
            );

        // Copy select from freshQuery if provided
        $selects = $freshQuery->getColumns();
        if (!empty($selects)) {
            $cleanQuery->select($selects);
        } else {
            // Build SELECT clause with pivot columns using selectRaw for proper alias handling
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
        // freshQuery contains constraints added by the eager loading callback like ->where('is_active', true)
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

        // Always select pivot keys for matching in eager loading
        if ($this->shouldIncludePivot()) {
            // Lazy load pivot columns only when withPivot('*') was used
            if ($this->withPivotAll) {
                $this->ensurePivotColumnsLoaded();
            }

            // Always select parentPivotKey, morphType, and foreignKey from pivot table
            $cleanQuery->selectRaw("{$this->pivotTable}.{$this->parentPivotKey} as pivot_{$this->parentPivotKey}");
            $cleanQuery->selectRaw("{$this->pivotTable}.{$this->morphType} as pivot_{$this->morphType}");
            $cleanQuery->selectRaw("{$this->pivotTable}.{$this->foreignKey} as pivot_{$this->foreignKey}");

            // Add other pivot columns
            foreach ($this->pivotColumns as $column) {
                // Skip if already added
                if ($column !== $this->parentPivotKey && $column !== $this->morphType && $column !== $this->foreignKey) {
                    $cleanQuery->selectRaw("{$this->pivotTable}.{$column} as pivot_{$column}");
                }
            }
        } else {
            // Still need pivot keys for matching in eager loading
            $cleanQuery->selectRaw("{$this->pivotTable}.{$this->parentPivotKey} as pivot_{$this->parentPivotKey}");
            $cleanQuery->selectRaw("{$this->pivotTable}.{$this->morphType} as pivot_{$this->morphType}");
            $cleanQuery->selectRaw("{$this->pivotTable}.{$this->foreignKey} as pivot_{$this->foreignKey}");
        }

        // CRITICAL: Copy all where constraints from original query (relationship method)
        // This ensures constraints like where('is_active', true) are preserved during eager loading
        // Exclude pivot and parent-specific constraints as they will be added by addEagerConstraints()
        $pivotTablePrefix = $this->pivotTable . '.';
        $this->copyWhereConstraints($cleanQuery, [
            $this->parentPivotKey,
            $this->morphType,
            $this->foreignKey,
            fn($col) => Str::startsWith($col, $pivotTablePrefix) ||
                $col === $this->parentPivotKey ||
                $col === $this->morphType ||
                $col === $this->foreignKey ||
                Str::endsWith($col, '.' . $this->parentPivotKey) ||
                Str::endsWith($col, '.' . $this->morphType) ||
                Str::endsWith($col, '.' . $this->foreignKey)
        ]);

        $instance->setQuery($cleanQuery);

        // Apply pivot constraints separately
        $instance->applyPivotConstraintsToQuery($instance->getQuery());

        // Apply soft delete scope if related model uses soft deletes
        $instance->applySoftDeleteScope($cleanQuery, $this->relatedClass, $relatedTable);

        return $instance;
    }

    /**
     * Get table columns with caching to avoid repeated SHOW COLUMNS queries.
     *
     * Performance: Uses static cache with 5-minute TTL to avoid repeated database queries
     * Clean Architecture: Reusable helper method for schema introspection
     *
     * @param string $tableName Table name
     * @param mixed $connection Database connection
     * @return array<string> Array of column names
     */
    protected function getCachedTableColumns(string $tableName, $connection): array
    {
        $cacheKey = $tableName;
        $now = now()->getTimestamp();

        // Check cache
        if (isset(self::$schemaCache[$cacheKey])) {
            $cached = self::$schemaCache[$cacheKey];
            // Return cached data if still valid
            if (($now - $cached['timestamp']) < self::$schemaCacheTtl) {
                return $cached['columns'];
            }
        }

        // Cache miss or expired - fetch from database
        $columns = $connection->select("SHOW COLUMNS FROM `{$tableName}`");
        $columnNames = array_column($columns, 'Field');

        // Store in cache
        self::$schemaCache[$cacheKey] = [
            'columns' => $columnNames,
            'timestamp' => $now
        ];

        return $columnNames;
    }

    // =========================================================================
    // GETTER METHODS FOR AGGREGATES
    // =========================================================================

    /**
     * Get the pivot table name.
     */
    public function getPivotTable(): string
    {
        return $this->pivotTable;
    }

    /**
     * Get the morph type column name.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Get the foreign pivot key (morph id column in pivot table).
     */
    public function getForeignPivotKey(): string
    {
        return $this->foreignKey; // This is taggable_id
    }

    /**
     * Get the parent pivot key (parent column in pivot table).
     */
    public function getParentPivotKey(): string
    {
        return $this->parentPivotKey; // This is tag_id
    }

    /**
     * Get the parent key (local key on parent model).
     */
    public function getParentKey(): string
    {
        return $this->parentKey;
    }

    /**
     * Get the related key (primary key on related model).
     */
    public function getRelatedKey(): string
    {
        return $this->relatedKey;
    }

    /**
     * Get the related pivot key (same as parent pivot key for MorphedByMany).
     */
    public function getRelatedPivotKey(): string
    {
        return $this->parentPivotKey; // tag_id in pivot
    }

    // =========================================================================
    // MAGIC METHODS
    // =========================================================================

    /**
     * Magic method to delegate calls to the query builder.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (method_exists($this->query, $method)) {
            $result = $this->query->{$method}(...$parameters);

            return $result instanceof QueryBuilder ? $this : $result;
        }

        throw new \BadMethodCallException(
            sprintf('Method %s::%s does not exist.', static::class, $method)
        );
    }
}
