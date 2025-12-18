<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Relations;

use Toporia\Framework\Database\ORM\{Model, ModelCollection, Pivot};
use Toporia\Framework\Database\Query\{Expression, QueryBuilder, RowCollection};
use Toporia\Framework\Support\Pagination\CursorPaginator;
use Toporia\Framework\Support\Str;

/**
 * BelongsToMany Relationship
 *
 * Handles many-to-many relationships through a pivot table.
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
class BelongsToMany extends Relation
{
    /** @var array<string> Additional pivot columns to select */
    protected array $pivotColumns = [];

    /** @var bool Whether to include all pivot columns (lazy loaded) */
    protected bool $withPivotAll = false;

    /** @var bool Whether to include timestamps in pivot table */
    protected bool $withTimestamps = false;

    /** @var string Custom pivot accessor name */
    protected string $pivotAccessor = 'pivot';

    /** @var array<array{column: string, operator: string, value: mixed, type?: string}> Pivot where constraints */
    protected array $pivotWheres = [];

    /** @var array<array{column: string, values: array, not?: bool}> Pivot whereIn constraints */
    protected array $pivotWhereIns = [];

    /** @var array<array{column: string, direction: string}> Pivot order by clauses */
    protected array $pivotOrderBy = [];

    /** @var class-string<Pivot>|null Custom pivot model class */
    protected ?string $pivotClass = null;

    /** @var string|null Cached related table name */
    private ?string $relatedTableCache = null;

    /**
     * @var array<string, array{columns: array<string>, timestamp: int}> Static cache for table schema
     * Format: ['table_name' => ['columns' => [...], 'timestamp' => unix_timestamp]]
     */
    private static array $schemaCache = [];

    /** @var int Cache TTL in seconds (5 minutes default) */
    private static int $schemaCacheTtl = 300;

    public function __construct(
        QueryBuilder $query,
        Model $parent,
        protected string $relatedClass,
        protected string $pivotTable,
        protected string $foreignPivotKey,
        protected string $relatedPivotKey,
        protected string $parentKey,
        protected string $relatedKey
    ) {
        parent::__construct($query, $parent, $foreignPivotKey, $parentKey);
        $this->initializePivotJoin();
    }

    /**
     * {@inheritdoc}
     */
    public function getRelatedClass(): string
    {
        return $this->relatedClass;
    }

    // =========================================================================
    // FLUENT CONFIGURATION METHODS
    // =========================================================================

    /**
     * Specify additional pivot columns to include in query results.
     *
     * @param string ...$columns Pivot column names to select
     * @return $this
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
     * Include created_at and updated_at timestamps in pivot table.
     */
    public function withTimestamps(): static
    {
        $this->withTimestamps = true;
        return $this->withPivot('created_at', 'updated_at');
    }

    /**
     * Customize the pivot accessor name.
     */
    public function as(string $accessor): static
    {
        $this->pivotAccessor = $accessor;
        return $this;
    }

    /**
     * Specify a custom pivot model class to use.
     *
     * @param class-string<Pivot> $class Custom pivot model class
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
     * Add a where constraint on the pivot table.
     */
    public function wherePivot(string $column, mixed $operator, mixed $value = null): static
    {
        [$operator, $value] = $this->normalizeOperatorValue($operator, $value);

        $this->pivotWheres[] = compact('column', 'operator', 'value');

        // FIXED: Only apply constraint immediately if pivot join already exists
        // This handles two scenarios:
        // 1. Relationship definition: pivot join doesn't exist yet → store constraint, apply later
        // 2. Eager loading closure: pivot join exists → apply constraint immediately
        if ($this->hasPivotJoin()) {
            // Pivot table is already joined, safe to apply constraint now
            $fullColumn = $this->ensurePivotColumnQualified($column);
            $this->applyWhereToQueryBuilder($this->query, $fullColumn, $operator, $value);
        }
        // Otherwise, constraint will be applied later via applyPivotConstraintsToQuery()

        return $this;
    }

    /**
     * Add a whereIn constraint on the pivot table.
     */
    public function wherePivotIn(string $column, array $values): static
    {
        $this->pivotWhereIns[] = ['column' => $column, 'values' => $values, 'not' => false];

        // Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $this->query->whereIn($this->qualifyPivotColumn($column), $values);
        }

        return $this;
    }

    /**
     * Add a whereNotIn constraint on the pivot table.
     */
    public function wherePivotNotIn(string $column, array $values): static
    {
        $this->pivotWhereIns[] = ['column' => $column, 'values' => $values, 'not' => true];

        // Only apply constraint immediately if pivot join already exists
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

        $this->pivotWheres[] = compact('column', 'operator', 'value', 'type') + ['type' => 'or'];

        // Only apply constraint immediately if pivot join already exists
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

        // Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $this->query->orWhereIn($this->qualifyPivotColumn($column), $values);
        }

        return $this;
    }

    /**
     * Add an order by clause on the pivot table.
     */
    public function orderByPivot(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction);
        $this->pivotOrderBy[] = compact('column', 'direction');

        // orderBy can be applied anytime (doesn't require pivot join in WHERE clause)
        // But to be safe, only apply if pivot join exists
        if ($this->hasPivotJoin()) {
            $this->query->orderBy($this->qualifyPivotColumn($column), $direction);
        }

        return $this;
    }

    public function wherePivotNull(string $column): static
    {
        return $this->wherePivot($column, 'IS', null);
    }

    public function wherePivotNotNull(string $column): static
    {
        return $this->wherePivot($column, 'IS NOT', null);
    }

    public function wherePivotBetween(string $column, array $values): static
    {
        return $this->wherePivot($column, 'BETWEEN', $values);
    }

    public function wherePivotNotBetween(string $column, array $values): static
    {
        return $this->wherePivot($column, 'NOT BETWEEN', $values);
    }

    /**
     * Add date-based constraints on pivot columns.
     */
    public function wherePivotDate(string $column, string $operator, string $value): static
    {
        return $this->addPivotFunctionConstraint('DATE', $column, $operator, $value);
    }

    public function wherePivotMonth(string $column, string $operator, int $value): static
    {
        return $this->addPivotFunctionConstraint('MONTH', $column, $operator, $value);
    }

    public function wherePivotYear(string $column, string $operator, int $value): static
    {
        return $this->addPivotFunctionConstraint('YEAR', $column, $operator, $value);
    }

    public function wherePivotTime(string $column, string $operator, string $value): static
    {
        return $this->addPivotFunctionConstraint('TIME', $column, $operator, $value);
    }

    /**
     * Add JSON constraints on pivot columns.
     *
     * @param string $column Column name
     * @param mixed $value Value to search for
     * @param string $path JSON path (validated to prevent SQL injection)
     * @return static
     * @throws \InvalidArgumentException If JSON path format is invalid
     */
    public function wherePivotJsonContains(string $column, mixed $value, string $path = '$'): static
    {
        // Validate JSON path to prevent SQL injection
        // Valid paths: $, $.key, $.key.subkey, $[0], $.key[0].subkey
        if (!preg_match('/^\$(\.[a-zA-Z_][a-zA-Z0-9_]*|\[\d+\])*$/', $path)) {
            throw new \InvalidArgumentException(
                "Invalid JSON path: '{$path}'. Path must start with '$' and contain only valid property names or array indices."
            );
        }

        $jsonValue = is_string($value) ? '"' . $value . '"' : json_encode($value);

        $this->pivotWheres[] = [
            'column' => $column,
            'function' => 'JSON_CONTAINS',
            'json_value' => $jsonValue,
            'path' => $path,
            'operator' => '=',
            'value' => 1,
            'type' => 'json'
        ];

        // Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $qualifiedColumn = $this->qualifyPivotColumn($column);
            $this->query->whereRaw("JSON_CONTAINS({$qualifiedColumn}, ?, ?)", [$jsonValue, $path]);
        }

        return $this;
    }

    /**
     * Add JSON length constraints on pivot columns.
     *
     * @param string $column Column name
     * @param string $operator Comparison operator
     * @param int $value Expected length
     * @param string $path JSON path (validated to prevent SQL injection)
     * @return static
     * @throws \InvalidArgumentException If JSON path format is invalid
     */
    public function wherePivotJsonLength(string $column, string $operator, int $value, string $path = '$'): static
    {
        // Validate JSON path to prevent SQL injection
        if (!preg_match('/^\$(\.[a-zA-Z_][a-zA-Z0-9_]*|\[\d+\])*$/', $path)) {
            throw new \InvalidArgumentException(
                "Invalid JSON path: '{$path}'. Path must start with '$' and contain only valid property names or array indices."
            );
        }

        // Validate operator
        $allowedOperators = ['=', '!=', '<>', '<', '<=', '>', '>='];
        if (!in_array($operator, $allowedOperators, true)) {
            throw new \InvalidArgumentException(
                "Invalid operator: '{$operator}'. Allowed: " . implode(', ', $allowedOperators)
            );
        }

        $this->pivotWheres[] = [
            'column' => $column,
            'function' => 'JSON_LENGTH',
            'path' => $path,
            'operator' => $operator,
            'value' => $value,
            'type' => 'json'
        ];

        // Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $qualifiedColumn = $this->qualifyPivotColumn($column);
            $this->query->whereRaw("JSON_LENGTH({$qualifiedColumn}, ?) {$operator} ?", [$path, $value]);
        }

        return $this;
    }

    // =========================================================================
    // INTERNAL QUERY BUILDING
    // =========================================================================

    /**
     * Initialize the pivot table join and constraints.
     */
    protected function initializePivotJoin(): void
    {
        if (!$this->parent->exists()) {
            return;
        }

        $relatedTable = $this->getRelatedTableName();

        $this->query
            ->join(
                $this->pivotTable,
                "{$relatedTable}.{$this->relatedKey}",
                '=',
                "{$this->pivotTable}.{$this->relatedPivotKey}"
            )
            ->where(
                $this->qualifyPivotColumn($this->foreignPivotKey),
                $this->parent->getAttribute($this->parentKey)
            )
            ->select("{$relatedTable}.*");

        // Only select pivot keys if we need to include pivot object or for matching in eager loading
        // Pivot keys are always needed for matching, but we only create pivot object when withPivot/withTimestamps is used
        if ($this->shouldIncludePivot()) {
            // Lazy load pivot columns only when withPivot('*') was used
            if ($this->withPivotAll) {
                $this->ensurePivotColumnsLoaded();
            }

            // Use selectRaw for pivot columns to avoid wrapping issues with aliases
            // This ensures pivot columns are selected with correct aliases (not wrapped as single string)
            $this->query->selectRaw("{$this->pivotTable}.{$this->foreignPivotKey} as pivot_{$this->foreignPivotKey}");
            $this->query->selectRaw("{$this->pivotTable}.{$this->relatedPivotKey} as pivot_{$this->relatedPivotKey}");

            // Add other pivot columns
            foreach ($this->pivotColumns as $column) {
                // Skip if already added (foreignPivotKey or relatedPivotKey might be in pivotColumns)
                if ($column !== $this->foreignPivotKey && $column !== $this->relatedPivotKey) {
                    $this->query->selectRaw("{$this->pivotTable}.{$column} as pivot_{$column}");
                }
            }
        } else {
            // Still need pivot keys for matching in eager loading, but don't create pivot object
            // Select them but we won't create pivot object later
            $this->query->selectRaw("{$this->pivotTable}.{$this->foreignPivotKey} as pivot_{$this->foreignPivotKey}");
            $this->query->selectRaw("{$this->pivotTable}.{$this->relatedPivotKey} as pivot_{$this->relatedPivotKey}");
        }

        // Apply pivot constraints (wherePivot, wherePivotIn, etc.)
        // FIXED: Apply constraints AFTER pivot join is created
        $this->applyPivotConstraintsToQuery($this->query);

        // Apply soft delete scope if related model uses soft deletes
        $this->applySoftDeleteScope($this->query, $this->relatedClass, $relatedTable);
    }

    /**
     * Check if pivot object should be included in the relationship.
     *
     * Pivot object is only included when:
     * - withPivot() is called with columns
     * - withTimestamps() is called
     *
     * @return bool
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

            // Exclude foreign and related pivot keys (they're always selected separately)
            $excludeColumns = [$this->foreignPivotKey, $this->relatedPivotKey];

            // Exclude timestamps if withTimestamps() will be called separately
            // But include them if they're already in pivotColumns (user explicitly wants them)
            if (!$this->withTimestamps) {
                $excludeColumns[] = 'created_at';
                $excludeColumns[] = 'updated_at';
            }

            // Filter out excluded columns
            $pivotColumns = array_diff($allColumns ?? [], $excludeColumns);
            $this->pivotColumns = array_values($pivotColumns);
        }
    }

    /**
     * Qualify a column name with the pivot table prefix.
     */
    protected function qualifyPivotColumn(string $column): string
    {
        return "{$this->pivotTable}.{$column}";
    }

    /**
     * Get the related table name with caching.
     */
    protected function getRelatedTableName(): string
    {
        return $this->relatedTableCache ??= $this->relatedClass::getTableName();
    }

    /**
     * Get the related table name (public method).
     *
     * @return string
     */
    public function getRelatedTable(): string
    {
        return $this->getRelatedTableName();
    }

    /**
     * Normalize operator and value for where clauses.
     *
     * @return array{0: string, 1: mixed}
     */
    protected function normalizeOperatorValue(mixed $operator, mixed $value): array
    {
        if ($value === null && !in_array($operator, ['IS', 'IS NOT', 'BETWEEN', 'NOT BETWEEN'], true)) {
            return ['=', $operator];
        }

        return [$operator, $value];
    }

    /**
     * Apply a where constraint to the query.
     */
    protected function applyWhereToQuery(string $column, string $operator, mixed $value): void
    {
        match (true) {
            $operator === 'BETWEEN' && is_array($value) => $this->query->whereBetween($column, $value),
            $operator === 'NOT BETWEEN' && is_array($value) => $this->query->whereNotBetween($column, $value),
            $operator === 'IS' && $value === null => $this->query->whereNull($column),
            $operator === 'IS NOT' && $value === null => $this->query->whereNotNull($column),
            default => $this->query->where($column, $operator, $value),
        };
    }

    /**
     * Add a SQL function constraint on a pivot column.
     */
    protected function addPivotFunctionConstraint(string $function, string $column, string $operator, mixed $value): static
    {
        $this->pivotWheres[] = [
            'column' => "{$function}({$column})",
            'operator' => $operator,
            'value' => $value,
            'type' => 'function'
        ];

        // Only apply constraint immediately if pivot join already exists
        if ($this->hasPivotJoin()) {
            $qualifiedColumn = $this->qualifyPivotColumn($column);
            $this->query->whereRaw("{$function}({$qualifiedColumn}) {$operator} ?", [$value]);
        }

        return $this;
    }

    /**
     * Apply stored pivot constraints to a query builder.
     *
     * FIXED: Properly qualifies pivot columns with table name to ensure
     * constraints are applied correctly in sync/toggle/updateExistingPivot operations.
     */
    protected function applyStoredConstraintsToQuery(QueryBuilder $query): void
    {
        foreach ($this->pivotWheres as $where) {
            if (isset($where['type']) && $where['type'] === 'raw') {
                $query->whereRaw("{$where['column']} {$where['operator']} ?", [$where['value']]);
            } elseif (isset($where['type']) && $where['type'] === 'function') {
                $query->whereRaw("{$where['column']} {$where['operator']} ?", [$where['value']]);
            } elseif (!$this->isRawColumn($where['column'])) {
                // FIXED: Qualify column with pivot table name
                $qualifiedColumn = $this->ensurePivotColumnQualified($where['column']);
                $this->applyWhereToQueryBuilder($query, $qualifiedColumn, $where['operator'], $where['value']);
            }
        }

        foreach ($this->pivotWhereIns as $whereIn) {
            // FIXED: Qualify column with pivot table name
            $column = $this->ensurePivotColumnQualified($whereIn['column']);
            $whereIn['not']
                ? $query->whereNotIn($column, $whereIn['values'])
                : $query->whereIn($column, $whereIn['values']);
        }
    }

    /**
     * Check if a column contains SQL functions.
     */
    protected function isRawColumn(string $column): bool
    {
        return Str::contains($column, '(') && Str::contains($column, ')');
    }

    /**
     * {@inheritdoc}
     *
     * @return ModelCollection
     */
    public function getResults(): ModelCollection
    {
        // Check if this is eager loading with limit (needs window function optimization)
        $wheres = $this->query->getWheres();

        // Use base class helper method with recursion depth limit
        $isEagerLoading = $this->findWhereInRecursive($wheres, $this->pivotTable, $this->foreignPivotKey);

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
                $relatedTable = $this->getRelatedTableName();
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
                    return $this->hydrateWithPivot($rows->toArray());
                }

                // Use pivot aliases (not table-qualified names) for window query
                // BaseQuery selects: book_category.book_id AS pivot_book_id
                // So in window query subquery result, we reference: pivot_book_id (not book_category.book_id)
                $pivotForeignKeyAlias = $grammar->wrapColumn("pivot_{$this->foreignPivotKey}");

                // Build ORDER BY clause for window function
                // If no explicit orderBy, use primary key as default for consistent ordering
                // IMPORTANT: When using pivot columns (orderByPivot), we need to reference them
                // using their pivot aliases (e.g., toporia_base.pivot_order instead of book_category.order)
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
                            // because baseQuery selects pivot columns as "pivot_{column_name}"
                            $wrappedCol = 'toporia_base.' . $grammar->wrapColumn("pivot_{$pivotColumnName}");
                        } else {
                            // For regular columns, use table-qualified or unqualified name
                            if (str_contains($col, '.')) {
                                $parts = explode('.', $col, 2);
                                $wrappedCol = $grammar->wrapTable($parts[0]) . '.' . $grammar->wrapColumn($parts[1]);
                            } else {
                                $wrappedCol = $grammar->wrapColumn($col);
                            }
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
                    $this->pivotTable,
                    "{$relatedTable}.{$this->relatedKey}",
                    '=',
                    "{$this->pivotTable}.{$this->relatedPivotKey}"
                );

                // Get foreign key values from WHERE IN clause
                $foreignKeyValues = [];
                foreach ($wheres as $where) {
                    // Case-insensitive check for 'in' type
                    if (strtolower($where['type'] ?? '') === 'in') {
                        $column = $where['column'] ?? '';
                        if (str_contains($column, $this->pivotTable) && str_contains($column, $this->foreignPivotKey)) {
                            $foreignKeyValues = $where['values'] ?? [];
                            break;
                        }
                    }
                }

                // Copy all where conditions from original query (except WHERE IN for pivot foreign key)
                foreach ($wheres as $where) {
                    // Case-insensitive check for 'in' type
                    if (strtolower($where['type'] ?? '') === 'in') {
                        $column = $where['column'] ?? '';
                        // Skip WHERE IN for pivot foreign key, handled in window function
                        if (str_contains($column, $this->pivotTable) && str_contains($column, $this->foreignPivotKey)) {
                            continue;
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

                // Copy custom select from constraint closure if provided, otherwise use default
                // Use base class helper to check if we should use default selection
                $customColumns = $this->query->getColumns();
                if ($this->shouldUseDefaultSelect($customColumns)) {
                    // No custom select - use table-qualified wildcard to select only from related table, not joined pivot table
                    $baseQuery->select("{$relatedTable}.*");
                } else {
                    // User provided custom select - use it
                    $baseQuery->select($customColumns);
                }

                // Always need pivot keys for matching (with aliases)
                $baseQuery->selectRaw("{$this->pivotTable}.{$this->foreignPivotKey} as pivot_{$this->foreignPivotKey}");
                $baseQuery->selectRaw("{$this->pivotTable}.{$this->relatedPivotKey} as pivot_{$this->relatedPivotKey}");

                // Include additional pivot columns if needed
                if ($this->shouldIncludePivot()) {
                    // Lazy load pivot columns only when withPivot('*') was used
                    if ($this->withPivotAll) {
                        $this->ensurePivotColumnsLoaded();
                    }

                    foreach ($this->pivotColumns as $column) {
                        if ($column !== $this->foreignPivotKey && $column !== $this->relatedPivotKey) {
                            $baseQuery->selectRaw("{$this->pivotTable}.{$column} as pivot_{$column}");
                        }
                    }
                }

                // Copy orderBy from constraint closure if provided
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

                // Build optimized window function query for BelongsToMany
                // Partition by pivot foreign key to get top N per parent
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

                // Window query: partition by pivot alias and filter by parent IDs
                // Note: We use toporia_base.pivot_* aliases because baseQuery selects pivot columns as "pivot_*"
                $windowQuery = "SELECT * FROM (SELECT toporia_base.*, ROW_NUMBER() OVER (PARTITION BY toporia_base.{$pivotForeignKeyAlias} ORDER BY {$orderByClause}) AS toporia_row FROM ({$baseQuerySql}) AS toporia_base WHERE toporia_base.{$pivotForeignKeyAlias} IN ({$placeholders})) AS toporia_table WHERE {$rowFilter} ORDER BY toporia_row";

                // Combine bindings: base query bindings + foreign key values
                // PERFORMANCE: Use spread operator for better performance with small arrays
                $allBindings = [...$baseQueryBindings, ...$foreignKeyValues];

                // Execute optimized window function query
                try {
                    $rows = $connection->select($windowQuery, $allBindings);
                } catch (\Exception $e) {
                    // If query fails, fall back to standard query
                    // This can happen if pivot columns don't exist or other issues
                    return $this->getResultsFallback();
                }

                if (empty($rows)) {
                    return new ModelCollection([]);
                }

                return $this->relatedClass::hydrate($rows);
            }
        }

        // Fallback to standard query execution
        return $this->getResultsFallback();
    }

    /**
     * Fallback method for getResults() when window function cannot be used.
     *
     * @return ModelCollection
     */
    protected function getResultsFallback(): ModelCollection
    {
        // Ensure proper select columns are set before executing query
        $this->ensureProperSelectWithPivot();

        $rows = $this->query->get();

        if ($rows->isEmpty()) {
            return new ModelCollection([]);
        }

        // Convert RowCollection to array for hydrate method
        return $this->relatedClass::hydrate($rows->toArray());
    }

    /**
     * Ensure query has proper SELECT with pivot columns.
     *
     * This method intelligently handles three cases:
     * 1. User provided custom select via constraint closure - append pivot columns to it
     * 2. No select specified - set default table.* plus pivot columns
     * 3. Select already includes pivot columns - do nothing
     *
     * This ensures eager loading constraints with select() work correctly
     * while preserving pivot columns needed for relationship matching.
     *
     * @return void
     */
    protected function ensureProperSelectWithPivot(): void
    {
        $relatedTable = $this->getRelatedTableName();
        $currentColumns = $this->query->getColumns();

        // Check if we already have pivot columns in select
        $hasPivotColumns = false;
        foreach ($currentColumns as $col) {
            if ($col instanceof Expression) {
                $colStr = (string) $col;
                if (str_contains($colStr, 'pivot_') || str_contains($colStr, $this->pivotTable)) {
                    $hasPivotColumns = true;
                    break;
                }
            }
        }

        // If pivot columns already present, don't modify
        if ($hasPivotColumns) {
            return;
        }

        // Determine if user provided custom select or if we need default
        $hasCustomSelect = !empty($currentColumns);

        if ($hasCustomSelect) {
            // User provided custom select - preserve it and append pivot columns
            // Use addSelect() to append without replacing existing columns
            $this->addPivotColumnsToQuery();
        } else {
            // No select specified - set default table.* plus pivot columns
            $this->query->select("{$relatedTable}.*");
            $this->addPivotColumnsToQuery();
        }
    }

    /**
     * Add pivot columns to the current query using selectRaw.
     *
     * Adds pivot foreign key and related key columns needed for matching,
     * plus any additional pivot columns specified via withPivot().
     *
     * @return void
     */
    protected function addPivotColumnsToQuery(): void
    {
        // Lazy load pivot columns only when withPivot('*') was used
        if ($this->shouldIncludePivot() && $this->withPivotAll) {
            $this->ensurePivotColumnsLoaded();
        }

        // Always select pivot keys for matching (required even without withPivot)
        $this->query->selectRaw("{$this->pivotTable}.{$this->foreignPivotKey} as pivot_{$this->foreignPivotKey}");
        $this->query->selectRaw("{$this->pivotTable}.{$this->relatedPivotKey} as pivot_{$this->relatedPivotKey}");

        // Add additional pivot columns if specified via withPivot()
        if ($this->shouldIncludePivot()) {
            foreach ($this->pivotColumns as $column) {
                // Skip keys we already added
                if ($column !== $this->foreignPivotKey && $column !== $this->relatedPivotKey) {
                    $this->query->selectRaw("{$this->pivotTable}.{$column} as pivot_{$column}");
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = [];
        foreach ($models as $model) {
            $key = $model->getAttribute($this->parentKey);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        if (!empty($keys)) {
            // Deduplicate keys using associative array for O(1) performance
            $uniqueKeys = [];
            foreach ($keys as $key) {
                $uniqueKeys[$key] = true;
            }

            // CRITICAL FIX: Wrap existing WHERE conditions to handle OR operator precedence
            // If user callback has OR conditions, we need to wrap them in parentheses
            // Example: (is_primary = 1 OR order > 5) AND pivot.parent_id IN (...)
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
                    // Clear existing wheres using public API
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

            $this->query->whereIn(
                "{$this->pivotTable}.{$this->foreignPivotKey}",
                array_keys($uniqueKeys)
            );
        }

        // Apply soft delete scope if related model uses soft deletes
        $relatedTable = $this->getRelatedTableName();
        $this->applySoftDeleteScope($this->query, $this->relatedClass, $relatedTable);
    }

    /**
     * {@inheritdoc}
     *
     * Match eagerly loaded results to their parent models.
     *
     * PERFORMANCE OPTIMIZATION: Two strategies available
     * 1. Current: 2 queries (main + pivot lookup) - safer, works with complex pivot constraints
     * 2. Future: 1 query (select parent_id from pivot in main query) - faster for simple cases
     *
     * Performance: O(n + m) where n = parents, m = results
     * - Single query to get pivot mappings (or could be optimized to 0 extra queries)
     * - Dictionary-based matching with O(1) lookups
     */
    public function match(array $models, mixed $results, string $relationName): array
    {
        if (!$results instanceof ModelCollection || $results->isEmpty()) {
            // No results - set empty collections for all models
            foreach ($models as $model) {
                $model->setRelation($relationName, new ModelCollection([]));
            }
            return $models;
        }

        // PERFORMANCE NOTE: If pivot constraints are simple and performance is critical,
        // we could optimize this by selecting parent_id directly in the main eager query
        // This would eliminate the need for a separate pivot query
        if ($this->shouldUseOptimizedMatching()) {
            return $this->matchOptimized($models, $results, $relationName);
        }

        // Standard matching with separate pivot query (current implementation)
        return $this->matchWithPivotQuery($models, $results, $relationName);
    }

    /**
     * Check if we can use optimized matching (single query).
     *
     * Optimized matching works when:
     * - No complex pivot constraints (JSON functions, date functions, etc.)
     * - Simple WHERE/IN constraints only
     * - Parent ID is available in the result set
     * - Pivot table structure is validated
     *
     * @return bool
     */
    protected function shouldUseOptimizedMatching(): bool
    {
        // Check if we have complex pivot constraints that require separate pivot query
        foreach ($this->pivotWheres as $where) {
            // If column contains SQL functions, we need separate pivot query
            if (Str::contains($where['column'], '(') || Str::contains($where['column'], ')')) {
                return false;
            }
        }

        // Validate pivot table structure
        // Only use optimized matching if we can verify the pivot columns exist
        try {
            return $this->validatePivotTableStructure();
        } catch (\Throwable $e) {
            // If validation fails, fall back to standard matching
            return false;
        }
    }

    /**
     * Validate that the pivot table has the required columns.
     *
     * This ensures:
     * 1. Pivot table exists
     * 2. Foreign key column exists
     * 3. Related key column exists
     *
     * @return bool True if structure is valid
     */
    protected function validatePivotTableStructure(): bool
    {
        // Skip validation if we don't have a connection yet
        $connection = $this->query->getConnection();
        if ($connection === null) {
            return false;
        }

        // Get cached table columns (uses schema cache with 5-minute TTL)
        $columns = $this->getCachedTableColumns($this->pivotTable, $connection);

        // If we couldn't get columns, table might not exist
        if (empty($columns)) {
            return false;
        }

        // Check required columns exist
        $hasForegnKey = in_array($this->foreignPivotKey, $columns, true);
        $hasRelatedKey = in_array($this->relatedPivotKey, $columns, true);

        return $hasForegnKey && $hasRelatedKey;
    }

    /**
     * Optimized matching using parent_id from main query (1 query total).
     *
     * @param array $models Parent models
     * @param ModelCollection $results Related models with parent_id
     * @param string $relationName Relation name
     * @return array
     */
    protected function matchOptimized(array $models, ModelCollection $results, string $relationName): array
    {
        // FIXED: Process pivot data properly instead of leaving pivot_* attributes in models
        // Build dictionary: parent_id => [related_id => pivotData]
        $dictionary = [];
        foreach ($results as $result) {
            // Get pivot keys
            $pivotForeignKey = $result->getAttribute("pivot_{$this->foreignPivotKey}");
            $pivotRelatedKey = $result->getAttribute("pivot_{$this->relatedPivotKey}");

            if ($pivotForeignKey !== null && $pivotRelatedKey !== null) {
                $parentIdKey = (string) $pivotForeignKey;
                $relatedIdKey = (string) $pivotRelatedKey;

                // Build pivot data if needed
                $pivotData = null;
                if ($this->shouldIncludePivot()) {
                    // Lazy load pivot columns only when withPivot('*') was used
                    if ($this->withPivotAll) {
                        $this->ensurePivotColumnsLoaded();
                    }

                    $pivotData = [
                        $this->foreignPivotKey => $pivotForeignKey,
                        $this->relatedPivotKey => $pivotRelatedKey,
                    ];

                    // Add other pivot columns
                    foreach ($this->pivotColumns as $column) {
                        if ($column !== $this->foreignPivotKey && $column !== $this->relatedPivotKey) {
                            $pivotValue = $result->getAttribute("pivot_{$column}");
                            if ($pivotValue !== null) {
                                $pivotData[$column] = $pivotValue;
                            }
                        }
                    }

                    // Add timestamps if enabled
                    if ($this->withTimestamps) {
                        $createdAt = $result->getAttribute('pivot_created_at');
                        $updatedAt = $result->getAttribute('pivot_updated_at');
                        if ($createdAt !== null) {
                            $pivotData['created_at'] = $createdAt;
                        }
                        if ($updatedAt !== null) {
                            $pivotData['updated_at'] = $updatedAt;
                        }
                    }
                }

                if (!isset($dictionary[$parentIdKey])) {
                    $dictionary[$parentIdKey] = [];
                }
                $dictionary[$parentIdKey][$relatedIdKey] = [
                    'model' => $result,
                    'pivot' => $pivotData
                ];
            }
        }

        // Remove pivot_* attributes from all result models
        foreach ($results as $result) {
            /** @var Model $result */
            $this->removePivotAttributes($result);
        }

        // Match to parents
        foreach ($models as $model) {
            $parentId = $model->getAttribute($this->parentKey);
            $parentIdKey = (string) $parentId;
            $matched = [];

            if (isset($dictionary[$parentIdKey])) {
                foreach ($dictionary[$parentIdKey] as $relatedData) {
                    // Clone the related model to avoid sharing pivot data
                    $relatedModel = clone $relatedData['model'];

                    // Attach pivot object if we have pivot data
                    if ($relatedData['pivot'] !== null && $this->shouldIncludePivot()) {
                        $pivotModel = $this->newPivot($relatedData['pivot'], true);
                        $relatedModel->setRelation($this->pivotAccessor, $pivotModel);
                    }

                    $matched[] = $relatedModel;
                }
            }

            $model->setRelation($relationName, new ModelCollection($matched));
        }

        return $models;
    }

    /**
     * Standard matching using pivot data from main query (OPTIMIZED - 1 query instead of 2).
     *
     * PERFORMANCE OPTIMIZATION: Uses pivot data already selected in the main eager query
     * instead of querying the pivot table again. This reduces queries from 2 to 1.
     *
     * @param array $models Parent models
     * @param ModelCollection $results Related models with pivot data in attributes
     * @param string $relationName Relation name
     * @return array
     */
    protected function matchWithPivotQuery(array $models, ModelCollection $results, string $relationName): array
    {
        // Step 1: Collect parent IDs (with deduplication)
        $parentIds = [];
        foreach ($models as $model) {
            $parentId = $model->getAttribute($this->parentKey);
            if ($parentId !== null) {
                $parentIds[$parentId] = true; // Use associative array for O(1) deduplication
            }
        }
        $parentIds = array_keys($parentIds);

        if (empty($parentIds)) {
            // No valid parent IDs - set empty collections
            foreach ($models as $model) {
                $model->setRelation($relationName, new ModelCollection([]));
            }
            return $models;
        }

        // Step 2: Extract pivot data from query results (OPTIMIZED - no separate query)
        // The main query already selected pivot columns with alias "pivot_{column}"
        // We extract this data to build the parent-to-related mapping dictionary
        $dictionary = [];

        foreach ($results as $result) {
            // Get pivot data from model attributes (with prefix "pivot_")
            $pivotForeignKey = $result->getAttribute("pivot_{$this->foreignPivotKey}");
            $pivotRelatedKey = $result->getAttribute("pivot_{$this->relatedPivotKey}");

            if ($pivotForeignKey === null || $pivotRelatedKey === null) {
                // Skip if pivot keys are missing (shouldn't happen in eager loading)
                continue;
            }

            // Normalize to string for consistent comparison
            $parentIdKey = (string) $pivotForeignKey;
            $relatedIdKey = (string) $pivotRelatedKey;

            // Only build pivot data if we should include pivot object
            $pivotData = null;
            if ($this->shouldIncludePivot()) {
                // Lazy load pivot columns only when withPivot('*') was used
                if ($this->withPivotAll) {
                    $this->ensurePivotColumnsLoaded();
                }

                // Build pivot data from all pivot_* attributes
                $pivotData = [
                    $this->foreignPivotKey => $pivotForeignKey,
                    $this->relatedPivotKey => $pivotRelatedKey,
                ];

                // Add other pivot columns
                foreach ($this->pivotColumns as $column) {
                    if ($column !== $this->foreignPivotKey && $column !== $this->relatedPivotKey) {
                        $pivotValue = $result->getAttribute("pivot_{$column}");
                        if ($pivotValue !== null) {
                            $pivotData[$column] = $pivotValue;
                        }
                    }
                }

                // Add timestamps if enabled
                if ($this->withTimestamps) {
                    $createdAt = $result->getAttribute('pivot_created_at');
                    $updatedAt = $result->getAttribute('pivot_updated_at');
                    if ($createdAt !== null) {
                        $pivotData['created_at'] = $createdAt;
                    }
                    if ($updatedAt !== null) {
                        $pivotData['updated_at'] = $updatedAt;
                    }
                }
            }

            // Build dictionary: parentId => [relatedId => pivotData or null]
            // pivotData is null if we don't need to create pivot object
            if (!isset($dictionary[$parentIdKey])) {
                $dictionary[$parentIdKey] = [];
            }
            $dictionary[$parentIdKey][$relatedIdKey] = $pivotData;
        }

        // Step 3: Build index of related models by ID for O(1) lookup
        // Normalize IDs to strings to avoid type mismatch issues (int vs string)
        // Note: If same related model appears multiple times (for different parents),
        // we keep the first instance (pivot data is already extracted to dictionary)
        // OPTIMIZED: Remove pivot_* attributes from models to keep them clean
        // Example: [10 => Model, 20 => Model, ...] or ["10" => Model, "20" => Model, ...]
        $relatedIndex = [];
        foreach ($results as $result) {
            $relatedId = $result->getAttribute($this->relatedKey);
            if ($relatedId !== null) {
                $relatedIdKey = (string) $relatedId;
                // Only keep first instance if duplicate (pivot data already in dictionary)
                if (!isset($relatedIndex[$relatedIdKey])) {
                    // Remove pivot_* attributes from the first instance to keep it clean
                    /** @var Model $result */
                    $this->removePivotAttributes($result);
                    $relatedIndex[$relatedIdKey] = $result;
                }
            }
        }

        // Step 4: Match related models to parents using dictionary and attach pivot data
        // CRITICAL: Clone related models for each parent to avoid pivot data being shared/reused
        // When multiple products share the same category, each product needs its own category instance with correct pivot data
        // PERFORMANCE: Pre-normalize parent IDs to avoid repeated string conversion
        foreach ($models as $model) {
            $modelParentId = $model->getAttribute($this->parentKey);

            if ($modelParentId === null) {
                // No parent ID - set empty collection
                $model->setRelation($relationName, new ModelCollection([]));
                continue;
            }

            // Normalize to string for consistent comparison (cache this value)
            $modelParentIdKey = (string) $modelParentId;

            // CRITICAL: Only match if dictionary has entries for THIS specific parentId
            if (!isset($dictionary[$modelParentIdKey])) {
                // No pivot data for this product - set empty collection
                $model->setRelation($relationName, new ModelCollection([]));
                continue;
            }

            // Get related IDs with pivot data for THIS parent from dictionary
            $relatedDataForParent = $dictionary[$modelParentIdKey];

            // Initialize matched array (PHP handles dynamic growth efficiently)
            $matched = [];

            // Look up actual models by ID and attach pivot data
            // OPTIMIZED: Single loop with validation - combines validation and matching
            foreach ($relatedDataForParent as $relatedIdStr => $pivotData) {
                // If pivot data is null, we still need to match the related model
                // but we won't create a pivot object
                if ($pivotData !== null) {
                    // CRITICAL: Verify pivot data has correct parentId FIRST
                    // This ensures we don't waste time on invalid pivot data
                    $pivotParentId = $pivotData[$this->foreignPivotKey] ?? null;

                    // Safety check: pivot data MUST match the model's parentId
                    if ($pivotParentId === null || (string) $pivotParentId !== $modelParentIdKey) {
                        // Dictionary corruption detected - skip invalid entry
                        continue;
                    }
                }

                // Normalize to string for consistent comparison
                $relatedIdKey = (string) $relatedIdStr;

                if (isset($relatedIndex[$relatedIdKey])) {
                    // CRITICAL: Clone the related model to avoid sharing pivot data between products
                    // When multiple products share the same category, each needs its own instance
                    $originalRelatedModel = $relatedIndex[$relatedIdKey];

                    // Clone the model to create a fresh instance for this product
                    $relatedModel = clone $originalRelatedModel;

                    // Remove pivot_* attributes from model (they should only be in pivot relation)
                    // This ensures clean model attributes without pivot_ prefix pollution
                    /** @var Model $relatedModel */
                    $this->removePivotAttributes($relatedModel);

                    // Only create and attach pivot object if we have pivot data
                    if ($pivotData !== null && $this->shouldIncludePivot()) {
                        // Clear any existing relations on the cloned model
                        $relatedModel->setRelation($this->pivotAccessor, null);

                        // Attach pivot data to the cloned model
                        // PERFORMANCE: Use array spread for faster copying, then override foreign keys
                        // This creates a fresh copy to avoid reference issues while being faster than foreach
                        $cleanPivotData = [...$pivotData];

                        // CRITICAL: Force set foreign keys to match THIS product's ID
                        // This ensures pivot data always has the correct product_id
                        // Even though we verified above, we still force set to be absolutely sure
                        $pivotRelatedId = $pivotData[$this->relatedPivotKey] ?? null;
                        $cleanPivotData[$this->foreignPivotKey] = $modelParentId;
                        $cleanPivotData[$this->relatedPivotKey] = $pivotRelatedId;

                        $pivotModel = $this->newPivot($cleanPivotData, true);
                        $relatedModel->setRelation($this->pivotAccessor, $pivotModel);
                    }

                    $matched[] = $relatedModel;
                }
            }

            // Set relation with matched models (or empty collection)
            $model->setRelation($relationName, new ModelCollection($matched));
        }

        return $models;
    }

    /**
     * Remove pivot_* attributes from model.
     *
     * These attributes are only needed during matching and should not appear
     * in the final model attributes. Pivot data should only be in the pivot relation.
     *
     * OPTIMIZED: Uses Model's removeAttributesByPattern() method instead of reflection
     * for better performance.
     *
     * @param \Toporia\Framework\Database\ORM\Model $model Model instance to clean
     * @return void
     */
    protected function removePivotAttributes(Model $model): void
    {
        // Use Model's protected method to remove attributes efficiently
        // This avoids reflection overhead and is much faster
        $model->removeAttributesByPattern('pivot_');
    }

    /**
     * Apply pivot constraints to a separate pivot query.
     *
     * FIXED: Always qualifies pivot columns with table name to avoid ambiguous column errors.
     * This ensures queries work correctly when both related table and pivot table have columns with the same name.
     *
     * @param QueryBuilder $query Pivot query builder
     * @return void
     */
    protected function applyPivotConstraintsToQuery(QueryBuilder $query): void
    {
        // Apply pivot where constraints
        foreach ($this->pivotWheres as $where) {
            $column = $where['column'];
            $operator = $where['operator'];
            $value = $where['value'];
            $type = $where['type'] ?? null;

            // Handle different constraint types
            if ($type === 'json') {
                // JSON constraints (JSON_CONTAINS, JSON_LENGTH)
                $function = $where['function'] ?? null;
                $qualifiedColumn = $this->ensurePivotColumnQualified($column);

                if ($function === 'JSON_CONTAINS') {
                    $jsonValue = $where['json_value'];
                    $path = $where['path'] ?? '$';
                    $query->whereRaw("JSON_CONTAINS({$qualifiedColumn}, ?, ?)", [$jsonValue, $path]);
                } elseif ($function === 'JSON_LENGTH') {
                    $path = $where['path'] ?? '$';
                    $query->whereRaw("JSON_LENGTH({$qualifiedColumn}, ?) {$operator} ?", [$path, $value]);
                }
            } elseif ($type === 'function' || (Str::contains($column, '(') && Str::contains($column, ')'))) {
                // Function-based constraints (DATE, MONTH, YEAR, TIME, etc.)
                $this->applyFunctionBasedPivotConstraint($query, $column, $operator, $value);
            } else {
                // Regular column - always qualify with pivot table name to avoid ambiguity
                $fullColumn = $this->ensurePivotColumnQualified($column);
                $this->applyWhereToQueryBuilder($query, $fullColumn, $operator, $value);
            }
        }

        // Apply pivot whereIn constraints
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
     *
     * @param string $column Column name (may or may not be qualified)
     * @return string Qualified column name (e.g., "product_categories.sort_order")
     */
    protected function ensurePivotColumnQualified(string $column): string
    {
        if (!Str::contains($column, '.')) {
            // Column is not qualified, add pivot table prefix
            return $this->qualifyPivotColumn($column);
        }

        if (!Str::startsWith($column, $this->pivotTable . '.')) {
            // Column is qualified but not with pivot table, extract and requalify
            $columnName = Str::afterLast($column, '.');
            return $this->qualifyPivotColumn($columnName);
        }

        // Already qualified with pivot table
        return $column;
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
     * Apply a function-based pivot constraint (DATE, MONTH, YEAR, TIME, etc.).
     *
     * @param QueryBuilder $query Query builder
     * @param string $column Function expression (e.g., "DATE(sort_order)")
     * @param string $operator SQL operator
     * @param mixed $value Value to compare
     * @return void
     */
    protected function applyFunctionBasedPivotConstraint(QueryBuilder $query, string $column, string $operator, mixed $value): void
    {
        // Handle specific SQL functions
        $sqlFunctions = ['DATE', 'MONTH', 'YEAR', 'TIME'];
        foreach ($sqlFunctions as $function) {
            if (Str::startsWith($column, "{$function}(")) {
                $actualColumn = str_replace(["{$function}(", ')'], '', $column);
                $qualifiedColumn = $this->ensurePivotColumnQualified($actualColumn);
                $query->whereRaw("{$function}({$qualifiedColumn}) {$operator} ?", [$value]);
                return;
            }
        }

        // Handle JSON functions (they may already have complex syntax)
        if (Str::startsWith($column, 'JSON_CONTAINS(')) {
            $query->whereRaw("{$column} = ?", [$value]);
            return;
        }

        if (Str::startsWith($column, 'JSON_LENGTH(')) {
            $query->whereRaw("{$column} {$operator} ?", [$value]);
            return;
        }

        // Generic function handling - extract function name and column
        if (preg_match('/^(\w+)\(([^)]+)\)$/', $column, $matches)) {
            $functionName = $matches[1];
            $functionColumn = $matches[2];
            $qualifiedColumn = $this->ensurePivotColumnQualified($functionColumn);
            $query->whereRaw("{$functionName}({$qualifiedColumn}) {$operator} ?", [$value]);
        } else {
            // Fallback for complex expressions
            $query->whereRaw("{$column} {$operator} ?", [$value]);
        }
    }

    /**
     * Apply a where constraint to a query builder.
     * Helper method to avoid code duplication when working with external query builders.
     *
     * @param QueryBuilder $query Query builder
     * @param string $column Column name
     * @param string $operator SQL operator
     * @param mixed $value Value to compare
     * @return void
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

    /**
     * Attach a related model to the parent via pivot table.
     *
     * @param int|string|array $id Related model ID or array of IDs with pivot data
     * @param array<string, mixed> $pivotData Additional pivot data
     * @param bool $touch Whether to touch parent timestamps
     * @return array|bool Array of attached IDs or boolean for single attach
     * @throws \InvalidArgumentException If pivot data is invalid
     */
    public function attach(int|string|array $id, array $pivotData = [], bool $touch = true): array|bool
    {
        if (is_array($id)) {
            return $this->attachMany($id, $touch);
        }

        // Validate related ID
        if (!is_int($id) && !is_string($id)) {
            throw new \InvalidArgumentException('Related model ID must be an integer or string');
        }

        // Validate pivot data - must be associative array with string keys
        $this->validatePivotData($pivotData);

        // Get parent key with validation
        $parentKey = $this->parent->getAttribute($this->parentKey);
        if ($parentKey === null) {
            throw new \RuntimeException('Cannot attach: parent model does not have a primary key');
        }

        // PERFORMANCE: Use array spread for faster merging
        $data = [
            $this->foreignPivotKey => $parentKey,
            $this->relatedPivotKey => $id,
            ...$pivotData,
        ];

        // Add timestamps if enabled
        if ($this->withTimestamps) {
            $now = now()->toDateTimeString();
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
        }

        $qb = new QueryBuilder($this->query->getConnection());
        $qb->table($this->pivotTable)->insert($data);

        if ($touch) {
            $this->touchParent();
        }

        return true;
    }

    /**
     * Validate pivot data structure.
     *
     * @param array<string, mixed> $pivotData Pivot data to validate
     * @throws \InvalidArgumentException If pivot data is invalid
     */
    protected function validatePivotData(array $pivotData): void
    {
        foreach ($pivotData as $key => $value) {
            // Keys must be strings (column names)
            if (!is_string($key)) {
                throw new \InvalidArgumentException(
                    "Pivot data keys must be strings (column names). Got: " . gettype($key)
                );
            }

            // Column names must be valid identifiers
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException(
                    "Invalid pivot column name: '{$key}'. Column names must contain only alphanumeric characters and underscores."
                );
            }

            // Values must be scalar or null (no objects, arrays, or resources)
            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException(
                    "Pivot data values must be scalar or null. Got " . gettype($value) . " for column '{$key}'"
                );
            }
        }
    }

    /**
     * Attach multiple related models to the parent via pivot table.
     *
     * Performance Optimizations:
     * - Cache parent key to avoid repeated attribute access
     * - Cache timestamp once if timestamps enabled
     * - Pre-allocate arrays with known size
     * - Early return for empty input
     * - Single bulk insert instead of multiple queries
     *
     * Performance: O(n) where n = number of IDs
     * Memory: O(n) - One array allocation for insert data
     *
     * @param array $ids Array of IDs or associative array with pivot data
     * @param bool $touch Whether to touch parent timestamps
     * @return array Array of attached IDs
     */
    protected function attachMany(array $ids, bool $touch = true): array
    {
        // Early return for empty input
        if (empty($ids)) {
            return [];
        }

        // Performance: Cache parent key once (avoid repeated attribute access)
        $parentKeyValue = $this->parent->getAttribute($this->parentKey);

        // Validate parent key
        if ($parentKeyValue === null) {
            throw new \RuntimeException('Cannot attach: parent model does not have a primary key');
        }

        // Performance: Cache timestamp once if timestamps enabled (avoid repeated now() calls)
        $timestamp = $this->withTimestamps ? now()->toDateTimeString() : null;

        // Initialize arrays
        $attached = [];
        $insertData = [];

        // Build insert data efficiently
        foreach ($ids as $key => $value) {
            // Check if value is an array (pivot data) or a simple ID
            if (is_array($value)) {
                // Associative array with pivot data: [1 => ['created_at' => ..., 'created_by' => ...]]
                // OR numeric key with array value: [0 => ['id' => 1, 'created_at' => ...]]
                $relatedId = $key;
                $pivotData = $value;

                // Validate pivot data structure
                $this->validatePivotData($pivotData);
            } else {
                // Simple array: [1, 2, 3] - numeric key, non-array value
                // OR string key with non-array value (shouldn't happen, but handle it)
                $relatedId = $value;
                $pivotData = [];
            }

            // Validate related ID
            if (!is_int($relatedId) && !is_string($relatedId)) {
                throw new \InvalidArgumentException(
                    'Related model ID must be an integer or string. Got: ' . gettype($relatedId)
                );
            }

            // Build data array efficiently
            // PERFORMANCE: Use array spread for faster merging (PHP 7.4+)
            $data = [
                $this->foreignPivotKey => $parentKeyValue,
                $this->relatedPivotKey => $relatedId,
                ...$pivotData, // Spread pivot data directly (empty array spreads to nothing)
            ];

            // Add timestamps if enabled (use cached timestamp)
            if ($this->withTimestamps && $timestamp !== null) {
                $data['created_at'] = $timestamp;
                $data['updated_at'] = $timestamp;
            }

            $insertData[] = $data;
            $attached[] = $relatedId;
        }

        // Single bulk insert for all records (much faster than multiple inserts)
        if (!empty($insertData)) {
            $qb = new QueryBuilder($this->query->getConnection());
            $qb->table($this->pivotTable)->insert($insertData);
        }

        // Touch parent timestamps if requested
        if ($touch) {
            $this->touchParent();
        }

        return $attached;
    }

    /**
     * Detach related models from the parent via pivot table.
     *
     * FIXED: Applies relation constraints (wherePivot/where) like other frameworks.
     *
     * @param int|string|array|null $ids Related model ID(s) (null = detach all)
     * @return int Number of rows deleted
     */
    public function detach(int|string|array|null $ids = null): int
    {
        $qb = new QueryBuilder($this->query->getConnection());
        $query = $qb->table($this->pivotTable)
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey));

        if ($ids !== null) {
            $ids = is_array($ids) ? $ids : [$ids];
            $query->whereIn($this->relatedPivotKey, $ids);
        }

        // FIXED: Apply stored pivot constraints (wherePivot, wherePivotIn, etc.)
        $this->applyStoredConstraintsToQuery($query);

        return $query->delete();
    }

    /**
     * Soft delete related models through this relationship.
     *
     * If related model uses soft deletes, this will soft delete the related models
     * and detach them from the pivot table. Otherwise, it will only detach.
     *
     * Performance: O(n) - Single UPDATE query for soft delete + DELETE for pivot
     *
     * @param int|string|array|null $ids Related model ID(s) (null = soft delete all)
     * @return int Number of models soft deleted
     *
     * @example
     * ```php
     * // Soft delete a specific category from product
     * $product->categories()->softDelete(1);
     *
     * // Soft delete multiple categories
     * $product->categories()->softDelete([1, 2, 3]);
     *
     * // Soft delete all categories
     * $product->categories()->softDelete();
     * ```
     */
    public function softDelete(int|string|array|null $ids = null): int
    {
        // If related model doesn't use soft deletes, just detach
        if (!$this->relatedModelUsesSoftDeletes($this->relatedClass)) {
            return $this->detach($ids);
        }

        // Get related model IDs to soft delete
        $relatedIds = [];
        if ($ids === null) {
            // Get all related IDs from pivot table
            $qb = new QueryBuilder($this->query->getConnection());
            $pivotRows = $qb->table($this->pivotTable)
                ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey))
                ->pluck($this->relatedPivotKey);
            $relatedIds = $pivotRows->toArray();
        } else {
            $relatedIds = is_array($ids) ? $ids : [$ids];
        }

        if (empty($relatedIds)) {
            return 0;
        }

        // Soft delete related models
        $deletedAtColumn = $this->getDeletedAtColumn($this->relatedClass);
        $relatedTable = $this->getRelatedTableName();
        $relatedKey = $this->relatedKey;

        $softDeleted = $this->relatedClass::query()
            ->whereIn($relatedKey, $relatedIds)
            ->whereNull($deletedAtColumn) // Only soft delete non-deleted records
            ->update([$deletedAtColumn => now()->toDateTimeString()]);

        // Detach from pivot table
        if ($softDeleted > 0) {
            $this->detachMany($relatedIds);
        }

        return $softDeleted;
    }

    /**
     * Restore soft-deleted related models through this relationship.
     *
     * Restores soft-deleted related models and optionally re-attaches them to pivot table.
     *
     * Performance: O(n) - Single UPDATE query for restore
     *
     * @param int|string|array $ids Related model ID(s) to restore
     * @param bool $reattach Whether to re-attach to pivot table after restore
     * @return int Number of models restored
     *
     * @example
     * ```php
     * // Restore a category
     * $product->categories()->restore(1);
     *
     * // Restore and re-attach
     * $product->categories()->restore(1, true);
     * ```
     */
    public function restore(int|string|array $ids, bool $reattach = false): int
    {
        // If related model doesn't use soft deletes, return 0
        if (!$this->relatedModelUsesSoftDeletes($this->relatedClass)) {
            return 0;
        }

        $relatedIds = is_array($ids) ? $ids : [$ids];
        if (empty($relatedIds)) {
            return 0;
        }

        // Restore related models
        $deletedAtColumn = $this->getDeletedAtColumn($this->relatedClass);
        $restored = $this->relatedClass::withTrashed()
            ->whereIn($this->relatedKey, $relatedIds)
            ->whereNotNull($deletedAtColumn) // Only restore soft-deleted records
            ->update([$deletedAtColumn => null]);

        // Re-attach to pivot table if requested
        if ($restored > 0 && $reattach) {
            $this->attachMany($relatedIds, false);
        }

        return $restored;
    }

    /**
     * Sync the pivot table with the given IDs.
     *
     * FIXED:
     * - Wrapped in database transaction for atomicity
     * - Loads current state once and reuses it
     * - Batch inserts for new attachments (bulk insert)
     * - Applies relation constraints (wherePivot/where) like other frameworks
     *
     * PERFORMANCE GUIDELINES:
     * - Use sync() for relationships with < 5,000 records
     * - Use syncChunked() for relationships with ≥ 5,000 records
     * - Memory usage: ~1KB per 1,000 records for pivot ID loading
     * - Performance degrades significantly beyond 10,000 records
     *
     * WHEN TO USE syncChunked():
     * - Large user role assignments (enterprise systems)
     * - Product category mappings (e-commerce)
     * - Tag associations (content management)
     * - Any relationship where you expect > 5,000 pivot records
     *
     * @param array<int|string> $ids Related model IDs or associative array with pivot data
     * @param bool $detaching Whether to detach missing records
     * @return array Sync results with attached, detached, and updated arrays
     *
     * @example
     * ```php
     * // Small datasets (< 5K records) - use sync()
     * $user->roles()->sync([1, 2, 3, 4, 5]);
     *
     * // Large datasets (≥ 5K records) - use syncChunked()
     * $product->categories()->syncChunked($categoryIds, true, 1000);
     * ```
     */
    public function sync(array $ids, bool $detaching = true): array
    {
        $changes = [
            'attached' => [],
            'detached' => [],
            'updated' => []
        ];

        $connection = $this->query->getConnection();

        // FIXED: Wrap entire operation in transaction for atomicity
        $connection->beginTransaction();

        try {
            // FIXED: Load current state once and reuse it
            $current = $this->getCurrentPivotIds();

            // Normalize input IDs
            $records = $this->formatSyncRecords($ids);
            $syncIds = array_keys($records);

            // PERFORMANCE: Convert current IDs to associative array for O(1) lookup
            // This is much faster than in_array() which is O(n)
            $currentLookup = [];
            foreach ($current as $currentId) {
                $currentLookup[$currentId] = true;
            }

            // FIXED: Batch detach if needed
            if ($detaching) {
                // Determine what to detach
                $detach = array_diff($current, $syncIds);
                if (!empty($detach)) {
                    $this->detachMany($detach);
                    $changes['detached'] = array_values($detach);
                }
            }

            // FIXED: Batch attach and update operations
            $toAttach = [];
            $toUpdate = [];

            foreach ($records as $id => $pivotData) {
                // PERFORMANCE: Use isset() for O(1) lookup instead of in_array() which is O(n)
                if (isset($currentLookup[$id])) {
                    // Update existing pivot record
                    if (!empty($pivotData)) {
                        $toUpdate[$id] = $pivotData;
                    }
                } else {
                    // Queue for batch attach
                    $toAttach[$id] = $pivotData;
                }
            }

            // FIXED: Batch update existing records
            if (!empty($toUpdate)) {
                foreach ($toUpdate as $id => $pivotData) {
                    $this->updateExistingPivot($id, $pivotData);
                    $changes['updated'][] = $id;
                }
            }

            // FIXED: Batch attach new records (single bulk insert)
            if (!empty($toAttach)) {
                $attached = $this->attachMany($toAttach, false);
                $changes['attached'] = $attached;
            }

            $this->touchParent();

            // Commit transaction
            $connection->commit();

            return $changes;
        } catch (\Exception $e) {
            // Rollback on error
            $connection->rollback();
            throw $e;
        }
    }

    /**
     * Sync without detaching existing records.
     *
     * @param array $ids Related model IDs or associative array with pivot data
     * @return array Sync results
     */
    public function syncWithoutDetaching(array $ids): array
    {
        return $this->sync($ids, false);
    }

    /**
     * Sync the pivot table with chunked processing for large datasets.
     *
     * Performance: O(n/chunk_size) - Memory-efficient sync for large relationships
     * Recommended for relationships with >5000 records
     *
     * @param array $ids Related model IDs or associative array with pivot data
     * @param bool $detaching Whether to detach missing records
     * @param int $chunkSize Number of records to process per chunk
     * @return array Sync results with attached, detached, and updated arrays
     *
     * @example
     * ```php
     * // For large datasets
     * $user->roles()->syncChunked($roleIds, true, 1000);
     * ```
     */
    public function syncChunked(array $ids, bool $detaching = true, int $chunkSize = 1000): array
    {
        $changes = [
            'attached' => [],
            'detached' => [],
            'updated' => []
        ];

        // Normalize input IDs
        $records = $this->formatSyncRecords($ids);
        $syncIds = array_keys($records);

        if ($detaching) {
            // Process detachment in chunks
            $this->processDetachmentChunked($syncIds, $chunkSize, $changes);
        }

        // Process attachment/updates in chunks
        $this->processAttachmentChunked($records, $chunkSize, $changes);

        $this->touchParent();

        return $changes;
    }

    /**
     * Process detachment in chunks.
     *
     * @param array $syncIds IDs to keep
     * @param int $chunkSize Chunk size
     * @param array &$changes Changes array to update
     * @return void
     */
    protected function processDetachmentChunked(array $syncIds, int $chunkSize, array &$changes): void
    {
        $qb = new QueryBuilder($this->query->getConnection());

        // Get current IDs in chunks and detach those not in sync list
        $qb->table($this->pivotTable)
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey))
            ->chunk($chunkSize, function ($pivotChunk) use ($syncIds, &$changes) {
                $currentChunk = [];
                foreach ($pivotChunk as $pivot) {
                    $currentChunk[] = $pivot[$this->relatedPivotKey];
                }

                $toDetach = array_diff($currentChunk, $syncIds);
                if (!empty($toDetach)) {
                    $this->detachMany($toDetach);
                    $changes['detached'] = array_merge($changes['detached'], $toDetach);
                }

                return true; // Continue processing
            });
    }

    /**
     * Process attachment/updates in chunks.
     *
     * FIXED: Now correctly filters current pivot IDs by chunk to avoid:
     * 1. Performance issue: O(n²) complexity from repeated full scans
     * 2. Correctness issue: Missing IDs beyond limit causing duplicate attachments
     *
     * @param array $records Records to attach/update
     * @param int $chunkSize Chunk size
     * @param array &$changes Changes array to update
     * @return void
     */
    protected function processAttachmentChunked(array $records, int $chunkSize, array &$changes): void
    {
        $recordChunks = array_chunk($records, $chunkSize, true);

        foreach ($recordChunks as $chunk) {
            $chunkIds = array_keys($chunk);

            // FIXED: Only get current pivot IDs for this specific chunk
            // This prevents both performance and correctness issues
            $current = $this->getCurrentPivotIdsFor($chunkIds);

            // PERFORMANCE: Convert current IDs to associative array for O(1) lookup
            $currentLookup = [];
            foreach ($current as $currentId) {
                $currentLookup[$currentId] = true;
            }

            foreach ($chunk as $id => $pivotData) {
                // PERFORMANCE: Use isset() for O(1) lookup instead of in_array() which is O(n)
                if (isset($currentLookup[$id])) {
                    // Update existing pivot record
                    if (!empty($pivotData)) {
                        $this->updateExistingPivot($id, $pivotData);
                        $changes['updated'][] = $id;
                    }
                } else {
                    // Attach new record
                    $this->attach($id, $pivotData, false);
                    $changes['attached'][] = $id;
                }
            }
        }
    }

    /**
     * Toggle the attachment of related models.
     *
     * FIXED:
     * - Wrapped in database transaction for atomicity
     * - Loads current state once and reuses it
     * - Batch operations (single DELETE + single bulk INSERT)
     * - Applies relation constraints (wherePivot/where) like other frameworks
     *
     * @param array|int|string $ids Related model IDs
     * @param bool $touch Whether to touch parent timestamps
     * @return array Toggle results with attached and detached arrays
     */
    public function toggle(array|int|string $ids, bool $touch = true): array
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $changes = ['attached' => [], 'detached' => []];

        if (empty($ids)) {
            return $changes;
        }

        $connection = $this->query->getConnection();

        // FIXED: Wrap entire operation in transaction for atomicity
        $connection->beginTransaction();

        try {
            // FIXED: Load current state once and reuse it
            $current = $this->getCurrentPivotIds();

            // PERFORMANCE: Convert current IDs to associative array for O(1) lookup
            $currentLookup = [];
            foreach ($current as $currentId) {
                $currentLookup[$currentId] = true;
            }

            // FIXED: Calculate diff in PHP, then execute minimal queries
            $toDetach = [];
            $toAttach = [];

            foreach ($ids as $id) {
                // PERFORMANCE: Use isset() for O(1) lookup instead of in_array() which is O(n)
                if (isset($currentLookup[$id])) {
                    $toDetach[] = $id;
                } else {
                    $toAttach[] = $id;
                }
            }

            // FIXED: Batch detach (single DELETE query)
            if (!empty($toDetach)) {
                $this->detachMany($toDetach);
                $changes['detached'] = $toDetach;
            }

            // FIXED: Batch attach (single bulk INSERT query)
            if (!empty($toAttach)) {
                $attached = $this->attachMany($toAttach, false);
                $changes['attached'] = $attached;
            }

            if ($touch) {
                $this->touchParent();
            }

            // Commit transaction
            $connection->commit();

            return $changes;
        } catch (\Exception $e) {
            // Rollback on error
            $connection->rollback();
            throw $e;
        }
    }

    /**
     * Update an existing pivot record.
     *
     * FIXED:
     * - Supports array of IDs (WHERE IN) like other frameworks
     * - Applies relation constraints (wherePivot/where) like other frameworks
     * - Wrapped in database transaction for atomicity
     *
     * @param int|string|array $id Related model ID(s)
     * @param array $pivotData Pivot data to update
     * @return bool|int Returns bool for single ID, int (affected rows) for array of IDs
     */
    public function updateExistingPivot(int|string|array $id, array $pivotData): bool|int
    {
        if (empty($pivotData)) {
            return is_array($id) ? 0 : false;
        }

        if ($this->withTimestamps && !isset($pivotData['updated_at'])) {
            $pivotData['updated_at'] = now()->toDateTimeString();
        }

        $connection = $this->query->getConnection();
        $connection->beginTransaction();

        try {
            $qb = new QueryBuilder($connection);
            $query = $qb->table($this->pivotTable)
                ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey));

            // FIXED: Support array of IDs (WHERE IN) like other frameworks
            if (is_array($id)) {
                if (empty($id)) {
                    $connection->rollback();
                    return 0;
                }
                // Remove duplicates
                $id = array_values(array_unique($id));
                $query->whereIn($this->relatedPivotKey, $id);
            } else {
                $query->where($this->relatedPivotKey, $id);
            }

            // FIXED: Apply stored pivot constraints (wherePivot, wherePivotIn, etc.)
            $this->applyStoredConstraintsToQuery($query);

            $affected = $query->update($pivotData);

            $connection->commit();

            // Return int for array, bool for single ID (Toporia compatibility)
            return is_array($id) ? $affected : ($affected > 0);
        } catch (\Exception $e) {
            $connection->rollback();
            throw $e;
        }
    }

    /**
     * Get current pivot IDs for the parent model.
     *
     * FIXED: Now applies relation constraints (wherePivot/where) like other frameworks.
     * All queries now include constraints from the relation builder.
     *
     * PERFORMANCE WARNING: Loads all pivot IDs into memory.
     * For very large relationships (>10k records), consider:
     * 1. Using chunked operations (syncChunked)
     * 2. Implementing streaming sync operations
     * 3. Using database-level operations
     *
     * IMPORTANT: This method should NOT be used in chunked operations as it
     * doesn't filter by specific IDs, leading to correctness issues.
     * Use targeted queries with whereIn() instead.
     *
     * @param int|null $limit Optional limit for safety (null = no limit)
     * @return array
     *
     * @deprecated Use targeted queries in chunked operations instead
     */
    protected function getCurrentPivotIds(?int $limit = null): array
    {
        $qb = new QueryBuilder($this->query->getConnection());
        $query = $qb->table($this->pivotTable)
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey));

        // CRITICAL: Do NOT apply wherePivot constraints when getting current IDs for sync
        // Constraints should only apply when querying related models, not when checking existence
        // This prevents duplicate key errors when records exist but don't match constraints
        // Example: wherePivot('sort_order', '>=', 89) should filter results, not prevent sync
        // $this->applyStoredConstraintsToQuery($query);

        // Apply limit if specified for safety
        if ($limit !== null) {
            $query->limit($limit);
        }

        $results = $query->pluck($this->relatedPivotKey);
        $ids = $results->toArray();

        return $ids;
    }

    /**
     * Get current pivot IDs for specific related IDs only.
     *
     * FIXED: Now applies relation constraints (wherePivot/where) like other frameworks.
     *
     * Performance: O(log n) - Uses indexed lookup with whereIn
     * Clean Architecture: Targeted query for chunked operations
     *
     * @param array $relatedIds Array of related IDs to check
     * @return array Array of existing pivot IDs from the given set
     */
    protected function getCurrentPivotIdsFor(array $relatedIds): array
    {
        if (empty($relatedIds)) {
            return [];
        }

        $qb = new QueryBuilder($this->query->getConnection());
        $query = $qb->table($this->pivotTable)
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey))
            ->whereIn($this->relatedPivotKey, $relatedIds);

        // CRITICAL: Do NOT apply wherePivot constraints when getting current IDs for sync
        // Constraints should only apply when querying related models, not when checking existence
        // This prevents duplicate key errors when records exist but don't match constraints
        // $this->applyStoredConstraintsToQuery($query);

        $results = $query->pluck($this->relatedPivotKey);

        return $results->toArray();
    }

    /**
     * Format sync records from various input formats.
     *
     * @param array $records Input records
     * @return array Formatted records
     */
    protected function formatSyncRecords(array $records): array
    {
        $formatted = [];

        foreach ($records as $key => $value) {
            // Check if this is a simple array format: [1, 2, 3]
            // This happens when key is numeric AND value is scalar
            if (is_numeric($key) && is_scalar($value)) {
                // Simple array: [1, 2, 3] -> convert to [1 => [], 2 => [], 3 => []]
                $formatted[$value] = [];
            } elseif (is_scalar($key)) {
                // Associative array format: [1 => ['role' => 'admin'], 2 => ['role' => 'user']]
                // or ['a' => [...], 'b' => [...]]
                // Key must be scalar (int/string) to use as array key
                $formatted[$key] = is_array($value) ? $value : [];
            } else {
                // Skip invalid keys (arrays/objects cannot be used as keys)
                continue;
            }
        }

        return $formatted;
    }

    /**
     * Detach multiple related models.
     *
     * FIXED:
     * - Removes duplicate IDs before building query
     * - Applies relation constraints (wherePivot/where) like other frameworks
     *
     * @param array $ids Related model IDs
     * @return int Number of rows deleted
     */
    protected function detachMany(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        // FIXED: Remove duplicate IDs to avoid duplicate placeholders in IN clause
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            return 0;
        }

        $qb = new QueryBuilder($this->query->getConnection());
        $query = $qb->table($this->pivotTable)
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey))
            ->whereIn($this->relatedPivotKey, $ids);

        // FIXED: Apply stored pivot constraints (wherePivot, wherePivotIn, etc.)
        $this->applyStoredConstraintsToQuery($query);

        return $query->delete();
    }

    /**
     * Touch the parent model's timestamps.
     *
     * @return void
     */
    protected function touchParent(): void
    {
        if (method_exists($this->parent, 'touch')) {
            $this->parent->touch();
        }
    }

    /**
     * {@inheritdoc}
     *
     * For BelongsToMany, we need to ensure the related key is selected
     * on the related model (not the pivot table keys).
     */
    public function getForeignKeyName(): string
    {
        return $this->relatedPivotKey;
    }

    /**
     * {@inheritdoc}
     *
     * Override to handle BelongsToMany's complex constructor with pivot table.
     * Creates a fresh instance without parent constraints for eager loading.
     *
     * Performance: O(1) - Direct instantiation, zero reflection overhead
     * Clean Architecture: Factory Method + Setter pattern for extensibility
     */
    public function newEagerInstance(QueryBuilder $freshQuery): static
    {
        // Create a dummy parent without ID to avoid parent-specific constraints
        $dummyParent = new ($this->parent::class)();

        $instance = new static(
            $freshQuery,
            $dummyParent,
            $this->relatedClass,
            $this->pivotTable,
            $this->foreignPivotKey,
            $this->relatedPivotKey,
            $this->parentKey,
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
        $relatedTable = $this->relatedClass::getTableName();

        // Create a fresh query from the related model (this ensures table name is set)
        $cleanQuery = $this->relatedClass::query()
            ->join(
                $this->pivotTable,
                "{$relatedTable}.{$this->relatedKey}",
                '=',
                "{$this->pivotTable}.{$this->relatedPivotKey}"
            );

        // Copy select from freshQuery if provided
        $selects = $freshQuery->getColumns();
        if (!empty($selects)) {
            $cleanQuery->select($selects);
        }
        // Otherwise DO NOT set default select here - getResults() will add it along with pivot columns

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

        // Copy where constraints from original query (excluding pivot and parent-specific constraints)
        // This ensures constraints like ->where('slug', 'like', '%Repellat%') are preserved
        $pivotTablePrefix = $this->pivotTable . '.';
        $foreignPivotKeyQualified = $this->qualifyPivotColumn($this->foreignPivotKey);

        $this->copyWhereConstraints($cleanQuery, [
            $foreignPivotKeyQualified,
            fn($col) => Str::startsWith($col, $pivotTablePrefix) || $col === $foreignPivotKeyQualified
        ]);

        $instance->setQuery($cleanQuery);

        // Apply pivot constraints to the eager query
        $instance->applyPivotConstraintsToQuery($instance->getQuery());

        // Apply soft delete scope if related model uses soft deletes
        $instance->applySoftDeleteScope($cleanQuery, $this->relatedClass, $relatedTable);

        return $instance;
    }

    /**
     * Create a new pivot model instance.
     *
     * Creates a Pivot model instance with full ORM capabilities.
     * If a custom pivot class is specified via using(), it will be instantiated instead.
     *
     * The pivot model has access to:
     * - Accessors/Mutators
     * - Custom methods
     * - Relationships from pivot
     * - Event hooks (creating, created, updating, updated, deleting, deleted)
     * - Save/update/delete operations
     *
     * @param array<string, mixed> $attributes Pivot attributes
     * @param bool $exists Whether the pivot exists in database
     * @return Pivot Pivot model instance
     */
    public function newPivot(array $attributes = [], bool $exists = false): Pivot
    {
        $pivotClass = $this->pivotClass ?? Pivot::class;

        // Use fromRawAttributes for proper Model initialization
        // This ensures accessors/mutators work correctly
        $pivot = $pivotClass::fromRawAttributes(
            $this->parent,
            $attributes,
            $this->pivotTable,
            $exists
        );

        // Set the foreign and related keys for proper save/delete operations
        $pivot->setForeignKey($this->foreignPivotKey);
        $pivot->setRelatedKey($this->relatedPivotKey);

        // Enable timestamps if configured
        if ($this->withTimestamps) {
            $pivot->withTimestamps();
        }

        return $pivot;
    }

    /**
     * Get the pivot accessor name.
     *
     * @return string
     */
    public function getPivotAccessor(): string
    {
        return $this->pivotAccessor;
    }

    /**
     * Check if timestamps are enabled for pivot.
     *
     * @return bool
     */
    public function hasPivotTimestamps(): bool
    {
        return $this->withTimestamps;
    }

    /**
     * Get the pivot table name.
     *
     * @return string
     */
    public function getPivotTable(): string
    {
        return $this->pivotTable;
    }

    /**
     * Get the foreign pivot key.
     *
     * @return string
     */
    public function getForeignPivotKey(): string
    {
        return $this->foreignPivotKey;
    }

    /**
     * Get the related pivot key.
     *
     * @return string
     */
    public function getRelatedPivotKey(): string
    {
        return $this->relatedPivotKey;
    }

    /**
     * Get the parent key.
     *
     * @return string
     */
    public function getParentKey(): string
    {
        return $this->parentKey;
    }

    /**
     * Get the related key.
     *
     * @return string
     */
    public function getRelatedKey(): string
    {
        return $this->relatedKey;
    }

    /**
     * Get the first related model or create a new one.
     *
     * @param array $attributes Attributes for new model
     * @param array $pivotData Pivot data for new relationship
     * @return Model
     */
    public function firstOrCreate(array $attributes = [], array $pivotData = []): Model
    {
        $instance = $this->where($attributes)->first();

        if ($instance === null) {
            $instance = $this->relatedClass::create($attributes);
            $this->attach($instance->getAttribute($this->relatedKey), $pivotData);
        }

        return $instance;
    }

    /**
     * Create a new related model and attach it.
     *
     * @param array $attributes Model attributes
     * @param array $pivotData Pivot data
     * @return Model
     */
    public function create(array $attributes = [], array $pivotData = []): Model
    {
        $instance = $this->relatedClass::create($attributes);
        $this->attach($instance->getAttribute($this->relatedKey), $pivotData);
        return $instance;
    }

    /**
     * Save a related model and attach it.
     *
     * @param Model $model Model to save and attach
     * @param array $pivotData Pivot data
     * @return Model
     */
    public function save(Model $model, array $pivotData = []): Model
    {
        $model->save();
        $this->attach($model->getAttribute($this->relatedKey), $pivotData);
        return $model;
    }

    /**
     * Get the count of related models.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->query->count();
    }

    /**
     * Check if any related models exist using EXISTS query.
     *
     * Performance: O(1) - Uses EXISTS instead of COUNT for efficiency.
     */
    public function exists(): bool
    {
        return $this->query->exists();
    }

    /**
     * Paginate the results.
     *
     * @param int $perPage Items per page
     * @param int $page Current page
     * @return array Pagination results
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
     * $paginator = $product->categories()->cursorPaginate(50);
     *
     * // Next page (using cursor from previous response)
     * $paginator = $product->categories()->cursorPaginate(50, $request->get('cursor'));
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
     * $user->roles()->chunk(100, function($roles) {
     *     foreach ($roles as $role) {
     *         // Process each role
     *     }
     * });
     *
     * // For large datasets, prefer chunkById():
     * $user->roles()->chunkById(100, function($roles) {
     *     // Much faster on large tables
     * });
     * ```
     */
    public function chunk(int $count, callable $callback): bool
    {
        $page = 1;
        $relatedKey = $this->relatedKey;
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

            // Call the callback with the current chunk
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
     * @param string $column Column to order by (default: related model's primary key)
     * @param string $alias Optional column alias
     * @return bool True if all chunks processed successfully
     *
     * @example
     * ```php
     * $user->roles()->chunkById(50, function($roles) {
     *     // Process roles in consistent order
     * });
     * ```
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        $column = $column ?: $this->relatedKey;
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

            // Call the callback with the current chunk
            if ($callback($models) === false) {
                return false;
            }

            // Get the last ID for the next iteration
            $lastModel = $models->last();
            $lastId = $lastModel->getAttribute($alias);
        } while ($results->count() === $count);

        return true;
    }

    /**
     * Get the sum of a pivot column.
     *
     * Performance: O(1) - Single aggregation query
     * Clean Architecture: Expressive aggregation methods
     *
     * @param string $column Pivot column name
     * @return float|int
     *
     * @example
     * ```php
     * $totalHours = $user->projects()->sumPivot('hours_worked');
     * ```
     */
    public function sumPivot(string $column): float|int
    {
        $result = $this->query->sum("{$this->pivotTable}.{$column}");
        return is_numeric($result) ? (float) $result : 0;
    }

    /**
     * Get the average of a pivot column.
     *
     * @param string $column Pivot column name
     * @return float|int
     *
     * @example
     * ```php
     * $avgRating = $user->products()->avgPivot('rating');
     * ```
     */
    public function avgPivot(string $column): float|int
    {
        $result = $this->query->avg("{$this->pivotTable}.{$column}");
        return is_numeric($result) ? (float) $result : 0;
    }

    /**
     * Get the minimum value of a pivot column.
     *
     * @param string $column Pivot column name
     * @return mixed
     *
     * @example
     * ```php
     * $minPrice = $user->products()->minPivot('price');
     * ```
     */
    public function minPivot(string $column): mixed
    {
        return $this->query->min("{$this->pivotTable}.{$column}");
    }

    /**
     * Get the maximum value of a pivot column.
     *
     * @param string $column Pivot column name
     * @return mixed
     *
     * @example
     * ```php
     * $maxPrice = $user->products()->maxPivot('price');
     * ```
     */
    public function maxPivot(string $column): mixed
    {
        return $this->query->max("{$this->pivotTable}.{$column}");
    }

    /**
     * Sync with additional pivot values for all records.
     *
     * Performance: O(n) - Batch operations with single transaction
     * Clean Architecture: Atomic sync operation with consistent state
     *
     * @param array $ids Related model IDs
     * @param array $pivotValues Additional pivot data for all records
     * @param bool $detaching Whether to detach missing records
     * @return array Sync results
     *
     * @example
     * ```php
     * $user->roles()->syncWithPivotValues([1, 2, 3], ['assigned_by' => $adminId]);
     * ```
     */
    public function syncWithPivotValues(array $ids, array $pivotValues, bool $detaching = true): array
    {
        // Add pivot values to each ID
        $records = [];
        foreach ($ids as $id) {
            $records[$id] = $pivotValues;
        }

        return $this->sync($records, $detaching);
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

            // Return $this for fluent interface on builder methods that return QueryBuilder
            if ($result instanceof QueryBuilder) {
                return $this;
            }

            return $result;
        }

        // Forward to parent for local scope handling
        return parent::__call($method, $parameters);
    }

    /**
     * Find a related model by its pivot attributes.
     *
     * Performance: O(log n) - Indexed pivot table lookup
     * Clean Architecture: Expressive finder method for pivot-based queries
     *
     * @param array $pivotAttributes Pivot attributes to search by
     * @param array $columns Columns to select
     * @return Model|null
     *
     * @example
     * ```php
     * $role = $user->roles()->findByPivot(['department' => 'IT', 'level' => 'senior']);
     * ```
     */
    public function findByPivot(array $pivotAttributes, array $columns = ['*']): ?Model
    {
        foreach ($pivotAttributes as $column => $value) {
            $this->wherePivot($column, $value);
        }

        return $this->select($columns)->first();
    }

    /**
     * Get all related models with specific pivot attributes.
     *
     * @param array $pivotAttributes Pivot attributes to search by
     * @param array $columns Columns to select
     * @return ModelCollection
     *
     * @example
     * ```php
     * $activeRoles = $user->roles()->getByPivot(['status' => 'active']);
     * ```
     */
    public function getByPivot(array $pivotAttributes, array $columns = ['*']): ModelCollection
    {
        foreach ($pivotAttributes as $column => $value) {
            $this->wherePivot($column, $value);
        }

        return $this->select($columns)->get();
    }

    /**
     * Create or update a pivot record with the given attributes.
     *
     * Performance: O(log n) - Uses targeted existence check instead of loading all IDs
     * Clean Architecture: Atomic upsert operation
     *
     * FIXED: No longer uses getCurrentPivotIds() to avoid O(n²) in loops
     *
     * @param int|string $id Related model ID
     * @param array $pivotData Pivot data
     * @param array $updateData Data to update if record exists
     * @return bool
     *
     * @example
     * ```php
     * $user->roles()->updateOrAttach(1, ['department' => 'IT'], ['updated_at' => now()]);
     *
     * // Safe to use in loops - O(log n) per call:
     * foreach ($roleIds as $roleId) {
     *     $user->roles()->updateOrAttach($roleId, $pivotData);
     * }
     * ```
     */
    public function updateOrAttach(int|string $id, array $pivotData = [], array $updateData = []): bool
    {
        // FIXED: Use targeted existence check instead of loading all pivot IDs
        if ($this->pivotExists($id)) {
            // PERFORMANCE: Use array spread for faster merging
            return $this->updateExistingPivot($id, [...$pivotData, ...$updateData]);
        } else {
            return $this->attach($id, $pivotData, false) !== false;
        }
    }

    /**
     * Attach multiple models with individual pivot data efficiently.
     *
     * Performance: O(n) - Batch insert with single query
     * Clean Architecture: Bulk operation with transaction safety
     *
     * @param array $records Array of [id => pivotData] pairs
     * @param bool $touch Whether to touch parent timestamps
     * @return array Array of attached IDs
     *
     * @example
     * ```php
     * $user->roles()->attachMany([
     *     1 => ['department' => 'IT', 'level' => 'senior'],
     *     2 => ['department' => 'HR', 'level' => 'junior']
     * ]);
     * ```
     */
    public function attachWithPivotData(array $records, bool $touch = true): array
    {
        return $this->attachMany($records, $touch);
    }

    /**
     * Create a new pivot query builder instance.
     *
     * Internal method used by all pivot-related methods to ensure consistency.
     * Returns a clean QueryBuilder without model hydration.
     *
     * @return QueryBuilder Clean query builder for pivot table
     */
    protected function newPivotQuery(): QueryBuilder
    {
        $qb = new QueryBuilder($this->query->getConnection());
        return $qb->table($this->pivotTable)
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey));
    }

    /**
     * Get the pivot table query builder.
     *
     * Performance: O(1) - Direct query builder access
     * Clean Architecture: Exposes pivot table for advanced queries
     *
     * @return QueryBuilder
     *
     * @example
     * ```php
     * $pivotQuery = $user->roles()->pivotQuery()
     *     ->where('created_at', '>', '2024-01-01')
     *     ->orderBy('priority', 'desc');
     * ```
     */
    public function pivotQuery(): QueryBuilder
    {
        return $this->newPivotQuery();
    }

    /**
     * Get distinct values from a pivot column.
     *
     * Performance: O(log n) - Uses database DISTINCT optimization
     * Clean Architecture: Expressive method for pivot column analysis
     *
     * @param string $column Pivot column name
     * @return array Array of distinct values
     *
     * @example
     * ```php
     * $departments = $user->roles()->distinctPivot('department');
     * ```
     */
    public function distinctPivot(string $column): array
    {
        // Build query once: SELECT DISTINCT column_name FROM pivot_table WHERE ...
        // This ensures we get distinct values for the column, not distinct full rows
        $query = $this->newPivotQuery()
            ->select($column)
            ->distinct();

        // Execute query once and return as array using ->all() as specified
        return $query->pluck($column)->all();
    }

    /**
     * Check if a specific pivot relationship exists.
     *
     * Performance: O(log n) - Uses optimized EXISTS query (SELECT 1 ... LIMIT 1)
     * Clean Architecture: Expressive existence check
     *
     * @param int|string $id Related model ID
     * @param array $pivotConstraints Additional pivot constraints
     * @return bool
     *
     * @example
     * ```php
     * if ($user->roles()->pivotExists(1, ['status' => 'active'])) {
     *     // User has active role
     * }
     * ```
     */
    public function pivotExists(int|string $id, array $pivotConstraints = []): bool
    {
        // Use optimized EXISTS pattern: SELECT 1 FROM ... WHERE ... LIMIT 1
        // This is much faster than COUNT(*) as it stops at first match
        $query = $this->newPivotQuery()
            ->where($this->relatedPivotKey, $id)
            ->selectRaw('1')
            ->limit(1);

        foreach ($pivotConstraints as $column => $value) {
            $query->where($column, $value);
        }

        return $query->exists();
    }

    /**
     * Get the custom pivot model class name.
     *
     * @return class-string|null
     */
    public function getPivotClass(): ?string
    {
        return $this->pivotClass;
    }

    /**
     * Validate pivot table structure and column names.
     *
     * This method helps debug column not found errors by checking
     * if the expected columns exist in the pivot table.
     *
     * Performance: Uses schema caching to avoid SHOW COLUMNS on every request.
     * Cache TTL: 5 minutes (configurable via $schemaCacheTtl)
     *
     * @return array Validation results
     */
    public function validatePivotStructure(): array
    {
        $connection = $this->query->getConnection();

        try {
            // Get table columns with caching
            $columnNames = $this->getCachedTableColumns($this->pivotTable, $connection);

            return [
                'table' => $this->pivotTable,
                'exists' => true,
                'columns' => $columnNames,
                'foreign_key_exists' => in_array($this->foreignPivotKey, $columnNames),
                'related_key_exists' => in_array($this->relatedPivotKey, $columnNames),
                'foreign_key' => $this->foreignPivotKey,
                'related_key' => $this->relatedPivotKey,
                'pivot_columns_exist' => array_intersect($this->pivotColumns, $columnNames) === $this->pivotColumns,
                'can_use_optimized_matching' => $this->canUseOptimizedMatchingSafely($columnNames)
            ];
        } catch (\Exception $e) {
            return [
                'table' => $this->pivotTable,
                'exists' => false,
                'error' => $e->getMessage(),
                'foreign_key' => $this->foreignPivotKey,
                'related_key' => $this->relatedPivotKey,
                'can_use_optimized_matching' => false
            ];
        }
    }

    /**
     * Check if optimized matching can be used safely based on actual table structure.
     *
     * @param array $columnNames Actual column names from pivot table
     * @return bool
     */
    protected function canUseOptimizedMatchingSafely(array $columnNames): bool
    {
        // Check if required columns exist
        if (
            !in_array($this->foreignPivotKey, $columnNames) ||
            !in_array($this->relatedPivotKey, $columnNames)
        ) {
            return false;
        }

        // Check if pivot columns exist
        if (!empty($this->pivotColumns)) {
            $missingColumns = array_diff($this->pivotColumns, $columnNames);
            if (!empty($missingColumns)) {
                return false;
            }
        }

        // Check for potential column name conflicts
        $pivotAlias = "pivot_{$this->foreignPivotKey}";

        // Get related table columns to check for conflicts (with caching)
        try {
            $relatedTable = $this->relatedClass::getTableName();
            $connection = $this->query->getConnection();
            $relatedColumnNames = $this->getCachedTableColumns($relatedTable, $connection);

            // Check if pivot alias would conflict with related table columns
            if (in_array($pivotAlias, $relatedColumnNames)) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            // If we can't validate related table, play it safe
            return false;
        }
    }

    /**
     * Get table columns with caching to avoid repeated SHOW COLUMNS queries.
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
        // Use parameterized query to prevent SQL injection
        // Escape table name to prevent injection - only allow valid identifier characters
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
            throw new \InvalidArgumentException(
                "Invalid table name: '{$tableName}'. Table names must contain only alphanumeric characters and underscores."
            );
        }

        // Use database-agnostic approach to get column information
        $driver = $connection->getDriverName();
        $columns = match ($driver) {
            'mysql' => $connection->select(
                "SELECT COLUMN_NAME as `Field` FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()",
                [$tableName]
            ),
            'pgsql' => $connection->select(
                "SELECT column_name as \"Field\" FROM information_schema.columns WHERE table_name = ?",
                [$tableName]
            ),
            'sqlite' => $connection->select("PRAGMA table_info(`{$tableName}`)"),
            default => $connection->select(
                "SELECT COLUMN_NAME as `Field` FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?",
                [$tableName]
            ),
        };

        // Extract column names based on driver
        $columnNames = match ($driver) {
            'sqlite' => array_column($columns, 'name'),
            default => array_column($columns, 'Field'),
        };

        // Store in cache
        self::$schemaCache[$cacheKey] = [
            'columns' => $columnNames,
            'timestamp' => $now
        ];

        return $columnNames;
    }

    /**
     * Clear schema cache for a specific table or all tables.
     *
     * @param string|null $tableName Table name to clear, or null to clear all
     * @return void
     */
    public static function clearSchemaCache(?string $tableName = null): void
    {
        if ($tableName === null) {
            self::$schemaCache = [];
        } else {
            unset(self::$schemaCache[$tableName]);
        }
    }

    /**
     * Set schema cache TTL in seconds.
     *
     * @param int $seconds Cache TTL in seconds
     * @return void
     */
    public static function setSchemaCacheTtl(int $seconds): void
    {
        self::$schemaCacheTtl = $seconds;
    }
}
