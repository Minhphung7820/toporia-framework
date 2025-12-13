<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Relations\Concerns;

use Toporia\Framework\Database\Query\QueryBuilder;
use Toporia\Framework\Support\Str;

/**
 * Trait HasPivotConstraints
 *
 * Provides shared pivot constraint methods for many-to-many relationships.
 * Used by BelongsToMany, MorphToMany, and MorphedByMany.
 *
 * Requirements for using classes:
 * - Must have $pivotTable property (string)
 * - Must have $pivotWheres property (array)
 * - Must have $pivotWhereIns property (array)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Relations/Concerns
 * @since       2025-01-10
 */
trait HasPivotConstraints
{
    /**
     * Qualify a column name with the pivot table prefix.
     *
     * @param string $column Column name
     * @return string Qualified column name (e.g., "pivot_table.column")
     */
    protected function qualifyPivotColumn(string $column): string
    {
        return "{$this->pivotTable}.{$column}";
    }

    /**
     * Ensure a column is qualified with the pivot table name.
     *
     * Handles three cases:
     * - Unqualified column: adds pivot table prefix
     * - Column qualified with wrong table: re-qualifies with pivot table
     * - Column already qualified with pivot table: returns as-is
     *
     * @param string $column Column name (may or may not be qualified)
     * @return string Qualified column name
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
     * Apply pivot constraints to a separate pivot query.
     *
     * This method applies wherePivot and wherePivotIn constraints to the given query.
     * Always qualifies pivot columns with table name to avoid ambiguous column errors.
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

            // Handle function-based columns (DATE, MONTH, YEAR, TIME, etc.)
            if (Str::contains($column, '(') && Str::contains($column, ')')) {
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
     * Apply a function-based pivot constraint (DATE, MONTH, YEAR, TIME, etc.).
     *
     * Handles SQL functions wrapping pivot columns:
     * - DATE(column), MONTH(column), YEAR(column), TIME(column)
     * - JSON_CONTAINS(column, value, path)
     * - JSON_LENGTH(column, path)
     * - Generic FUNCTION(column) patterns
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
     *
     * Helper method to avoid code duplication when working with external query builders.
     * Handles special operators like BETWEEN, IS NULL, etc.
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
}
