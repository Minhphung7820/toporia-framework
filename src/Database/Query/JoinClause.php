<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query;

/**
 * JoinClause - Fluent Interface for Complex JOIN Conditions
 *
 * Provides fluent interface for building complex JOIN clauses with multiple
 * conditions using AND/OR logic.
 *
 * Architecture:
 * - SOLID: Single Responsibility (only handles JOIN conditions)
 * - Clean Architecture: No external dependencies
 * - High Reusability: Can be used for any JOIN type
 *
 * Performance:
 * - O(1) for adding conditions
 * - Lazy evaluation - conditions compiled only when needed
 *
 * Usage:
 * ```php
 * $query->join('orders', function($join) {
 *     $join->on('users.id', '=', 'orders.user_id')
 *          ->where('orders.status', '=', 'active')
 *          ->orWhere('orders.priority', '>', 5);
 * });
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 */
class JoinClause
{
    /**
     * JOIN type (INNER, LEFT, RIGHT, CROSS)
     */
    private string $type;

    /**
     * Table being joined
     */
    private string $table;

    /**
     * JOIN conditions
     * Format: [
     *   ['type' => 'on', 'first' => 'col1', 'operator' => '=', 'second' => 'col2', 'boolean' => 'AND'],
     *   ['type' => 'where', 'column' => 'col', 'operator' => '=', 'value' => val, 'boolean' => 'OR']
     * ]
     */
    private array $clauses = [];

    /**
     * Parent QueryBuilder (for binding values)
     */
    private ?QueryBuilder $parentQuery = null;

    public function __construct(string $type, string $table)
    {
        $this->type = strtoupper($type);
        $this->table = $table;
    }

    /**
     * Set parent query (for binding values)
     *
     * Performance: O(1)
     */
    public function setParentQuery(QueryBuilder $query): self
    {
        $this->parentQuery = $query;
        return $this;
    }

    /**
     * Add ON condition (column = column)
     *
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * Usage:
     * ```php
     * $join->on('users.id', '=', 'orders.user_id');
     * $join->on(DB::raw('DATE(users.created_at)'), '=', DB::raw('DATE(orders.created_at)'));
     * ```
     *
     * Performance: O(1)
     * SOLID: Open/Closed - can add conditions without modifying existing code
     *
     * @param string|Expression $first First column or raw SQL expression
     * @param string $operator Comparison operator
     * @param string|Expression $second Second column or raw SQL expression
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function on(string|Expression $first, string $operator, string|Expression $second, string $boolean = 'AND'): self
    {
        $this->clauses[] = [
            'type' => 'on',
            'first' => $first instanceof Expression ? (string) $first : $first,
            'operator' => $operator,
            'second' => $second instanceof Expression ? (string) $second : $second,
            'boolean' => strtoupper($boolean)
        ];

        return $this;
    }

    /**
     * Add OR ON condition
     *
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * Performance: O(1)
     *
     * @param string|Expression $first First column or raw SQL expression
     * @param string $operator Comparison operator
     * @param string|Expression $second Second column or raw SQL expression
     * @return $this
     */
    public function orOn(string|Expression $first, string $operator, string|Expression $second): self
    {
        return $this->on($first, $operator, $second, 'OR');
    }

    /**
     * Add WHERE condition (column = value)
     *
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * Usage:
     * ```php
     * $join->where('orders.status', '=', 'active');
     * $join->where('orders.total', '>', 100);
     * $join->where(DB::raw('DATE(orders.created_at)'), '=', '2024-01-01');
     * ```
     *
     * Performance: O(1)
     * Clean Architecture: Separates WHERE from ON conditions
     *
     * @param string|Expression $column Column name or raw SQL expression
     * @param string $operator Comparison operator
     * @param mixed $value Value to compare
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function where(string|Expression $column, string $operator, mixed $value, string $boolean = 'AND'): self
    {
        $this->clauses[] = [
            'type' => 'where',
            'column' => $column instanceof Expression ? (string) $column : $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => strtoupper($boolean)
        ];

        // Add binding to parent query if available
        // FIXED: Specify 'join' type for proper binding categorization
        if ($this->parentQuery) {
            $this->parentQuery->addBinding($value, 'join');
        }

        return $this;
    }

    /**
     * Add OR WHERE condition
     *
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * Performance: O(1)
     *
     * @param string|Expression $column Column name or raw SQL expression
     * @param string $operator Comparison operator
     * @param mixed $value Value to compare
     * @return $this
     */
    public function orWhere(string|Expression $column, string $operator, mixed $value): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * Add WHERE NULL condition
     *
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * Performance: O(1)
     *
     * @param string|Expression $column Column name or raw SQL expression
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereNull(string|Expression $column, string $boolean = 'AND'): self
    {
        $this->clauses[] = [
            'type' => 'whereNull',
            'column' => $column instanceof Expression ? (string) $column : $column,
            'boolean' => strtoupper($boolean)
        ];

        return $this;
    }

    /**
     * Add OR WHERE NULL condition
     *
     * Performance: O(1)
     */
    public function orWhereNull(string $column): self
    {
        return $this->whereNull($column, 'OR');
    }

    /**
     * Add WHERE NOT NULL condition
     *
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * Performance: O(1)
     *
     * @param string|Expression $column Column name or raw SQL expression
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     */
    public function whereNotNull(string|Expression $column, string $boolean = 'AND'): self
    {
        $this->clauses[] = [
            'type' => 'whereNotNull',
            'column' => $column instanceof Expression ? (string) $column : $column,
            'boolean' => strtoupper($boolean)
        ];

        return $this;
    }

    /**
     * Add OR WHERE NOT NULL condition
     *
     * Performance: O(1)
     */
    public function orWhereNotNull(string $column): self
    {
        return $this->whereNotNull($column, 'OR');
    }

    // ==================== Getters ====================

    /**
     * Get JOIN type
     *
     * Performance: O(1)
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get table name
     *
     * Performance: O(1)
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get all clauses
     *
     * Performance: O(1)
     * High Reusability: Used by Grammar for compilation
     */
    public function getClauses(): array
    {
        return $this->clauses;
    }

    /**
     * Check if has any clauses
     *
     * Performance: O(1)
     */
    public function hasClauses(): bool
    {
        return !empty($this->clauses);
    }

    /**
     * Convert to array (for backward compatibility)
     *
     * Performance: O(n) where n = number of clauses
     * High Reusability: Maintains compatibility with existing code
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'table' => $this->table,
            'clauses' => $this->clauses
        ];
    }
}
