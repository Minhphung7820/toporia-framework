<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query;

use Toporia\Framework\Database\Contracts\{ConnectionInterface, QueryBuilderInterface};
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Database\Query\{Expression, RowCollection};
use Toporia\Framework\Database\DatabaseCollection;
use Toporia\Framework\Support\Collection\LazyCollection;
use Toporia\Framework\Support\Macroable;
use Toporia\Framework\Support\Pagination\CursorPaginator;
use Toporia\Framework\Support\Pagination\Paginator;


/**
 * Class QueryBuilder
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
 * @subpackage  Query
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class QueryBuilder implements QueryBuilderInterface
{
    use Macroable;
    use Concerns\BuildsWhereClausesAdvanced;
    use Concerns\BuildsWhereClausesExtended;
    use Concerns\BuildsSubqueries;
    use Concerns\BuildsConditionalClauses;
    use Concerns\BuildsUnions;
    use Concerns\BuildsLocks;
    use Concerns\BuildsAggregates;
    use Concerns\BuildsChunking;
    use Concerns\BuildsAdvancedQueries;
    use Concerns\BuildsJsonQueries;
    use Concerns\RaceConditionProtection;
    /**
     * Target table name.
     *
     * @var string|null
     */
    private ?string $table = null;

    /**
     * Selected columns.
     *
     * @var array<string>
     */
    private array $columns = ['*'];

    /**
     * WHERE clauses (internal representation).
     *
     * @var array<array>
     */
    private array $wheres = [];

    /**
     * JOIN clauses (internal representation).
     *
     * @var array<array>
     */
    private array $joins = [];

    /**
     * ORDER BY clauses (internal representation).
     *
     * @var array<array>
     */
    private array $orders = [];

    /**
     * LIMIT value.
     *
     * @var int|null
     */
    private ?int $limit = null;

    /**
     * OFFSET value.
     *
     * @var int|null
     */
    private ?int $offset = null;

    /**
     * GROUP BY columns.
     *
     * @var array<string>
     */
    private array $groups = [];

    /**
     * HAVING clauses.
     *
     * @var array<array>
     */
    private array $havings = [];

    /**
     * DISTINCT flag.
     *
     * @var bool
     */
    private bool $distinct = false;

    /**
     * Positional bindings for prepared statements, organized by type.
     * This ensures bindings are merged in the same order as SQL components are compiled.
     *
     * Types match SQL component order:
     * - 'select' => SELECT clause bindings (from raw expressions)
     * - 'join'   => JOIN clause bindings
     * - 'where'  => WHERE clause bindings
     * - 'group'  => GROUP BY clause bindings (from raw expressions)
     * - 'having' => HAVING clause bindings
     * - 'order'  => ORDER BY clause bindings (from raw expressions)
     * - 'union'  => UNION clause bindings
     *
     * @var array<string, array<mixed>>
     */
    private array $bindings = [
        'select' => [],
        'join'   => [],
        'where'  => [],
        'group'  => [],
        'having' => [],
        'order'  => [],
        'union'  => [],
    ];


    /**
     * Cached SQL string to avoid recompilation.
     *
     * @var string|null
     */
    private ?string $cachedSql = null;

    /**
     * Whether query caching is enabled.
     * Default: true (enabled for performance)
     *
     * @var bool
     */
    private static bool $cachingEnabled = true;

    /**
     * Whether query logging is enabled.
     *
     * @var bool
     */
    private static bool $loggingEnabled = false;

    /**
     * Query log storage.
     *
     * @var array<array{query: string, bindings: array, time: float}>
     */
    private static array $queryLog = [];

    /**
     * Query hints for performance optimization.
     *
     * @var array<string, array>
     */
    private array $queryHints = [];

    /**
     * @param ConnectionInterface $connection Database connection used to execute statements.
     */
    public function __construct(
        private ConnectionInterface $connection
    ) {}

    /**
     * Safely quote a value for use in SQL (security: prevents SQL injection).
     *
     * Uses PDO::quote() which properly escapes and quotes values.
     * This is safer than addslashes() which can be bypassed.
     *
     * @param mixed $value Value to quote
     * @return string Quoted value safe for SQL
     */
    protected function quoteValue(mixed $value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        // Use PDO::quote() for strings (properly escapes and quotes)
        return $this->connection->getPdo()->quote((string) $value, \PDO::PARAM_STR);
    }

    /**
     * Escape an identifier (column name, table name) for safe SQL use.
     *
     * CRITICAL SECURITY: Prevents SQL injection via column/table names.
     * Uses backticks for MySQL, double quotes for PostgreSQL/SQLite.
     *
     * Handles:
     * - Simple names: 'column' -> `column`
     * - Qualified names: 'table.column' -> `table`.`column`
     * - Already quoted: `column` -> `column` (no double escaping)
     * - Expressions: DB::raw() expressions pass through unchanged
     *
     * @param string $identifier Column or table name
     * @return string Safely escaped identifier
     *
     * @example
     * ```php
     * $this->escapeIdentifier('user_name');     // `user_name`
     * $this->escapeIdentifier('users.name');    // `users`.`name`
     * $this->escapeIdentifier('id); DROP--');   // `id); DROP--` (safe!)
     * ```
     */
    protected function escapeIdentifier(string $identifier): string
    {
        // Skip if already contains backticks/quotes (already escaped) or is a raw expression
        if (str_contains($identifier, '`') || str_contains($identifier, '"')) {
            return $identifier;
        }

        // Skip special cases: *, expressions with parentheses, AS aliases
        if (
            $identifier === '*' ||
            str_contains($identifier, '(') ||
            stripos($identifier, ' as ') !== false ||
            str_contains($identifier, ' AS ')
        ) {
            return $identifier;
        }

        $driver = $this->connection->getDriverName();
        $quote = match ($driver) {
            'mysql' => '`',
            'pgsql', 'sqlite' => '"',
            default => '`',
        };

        // Handle qualified names (table.column)
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map(
                fn($part) => $part === '*' ? '*' : $quote . str_replace($quote, $quote . $quote, $part) . $quote,
                $parts
            ));
        }

        // Escape the quote character within the identifier (double it)
        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    /**
     * Escape multiple identifiers.
     *
     * @param array<string> $identifiers Column or table names
     * @return array<string> Escaped identifiers
     */
    protected function escapeIdentifiers(array $identifiers): array
    {
        return array_map(fn($id) => $this->escapeIdentifier($id), $identifiers);
    }

    /**
     * Validate and normalize ORDER BY direction.
     *
     * SECURITY: Prevents SQL injection via direction parameter.
     * Only allows 'ASC' or 'DESC'.
     *
     * @param string $direction Direction string
     * @return string Normalized direction ('ASC' or 'DESC')
     * @throws \InvalidArgumentException If direction is invalid
     */
    protected function validateOrderDirection(string $direction): string
    {
        $normalized = strtoupper(trim($direction));

        if (!in_array($normalized, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid ORDER BY direction: "%s". Must be ASC or DESC.', $direction)
            );
        }

        return $normalized;
    }

    /**
     * Set the working table for the query.
     */
    public function table(string $table): self
    {
        $this->table = $table;
        $this->invalidateCache();
        return $this;
    }

    /**
     * Set selected columns.
     *
     * Accepts either an array of columns or varargs: select('id', 'name').
     * Also accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * @param string|array<int,string|Expression>|Expression $columns Column names or Expression objects
     */
    public function select(string|array|Expression $columns = ['*']): self
    {
        if ($columns instanceof Expression) {
            $this->columns = [$columns];
        } else {
            $this->columns = is_array($columns) ? $columns : func_get_args();
        }
        $this->invalidateCache();
        return $this;
    }

    /**
     * Add a raw SELECT expression.
     *
     * @param string $expression Raw SQL expression (e.g., "COUNT(*) AS count")
     * @param array<mixed> $bindings Optional bindings for the expression
     * @return $this
     */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        // Wrap in Expression to mark as raw SQL (should not be quoted)
        $this->columns[] = new Expression($expression);

        foreach ($bindings as $binding) {
            $this->addBinding($binding, 'select');
        }

        return $this;
    }

    /**
     * Add columns to the existing select clause.
     *
     * Unlike select() which replaces all columns, addSelect() appends to existing columns.
     * If no columns have been selected yet, it will preserve the default '*' behavior
     * by first selecting all columns from the table.
     *
     * Performance: O(n) where n = number of columns being added
     *
     * @param string|array<int, string|Expression>|Expression $columns Column names or Expression objects
     * @return $this
     *
     * @example
     * ```php
     * // Add columns to existing selection
     * $query->select('id', 'name')->addSelect('email');
     * // SELECT id, name, email FROM ...
     *
     * // Add multiple columns
     * $query->select('id')->addSelect(['name', 'email', 'created_at']);
     * // SELECT id, name, email, created_at FROM ...
     *
     * // Add to default '*' selection - automatically expands to table.*
     * $query->addSelect('custom_column');
     * // SELECT users.*, custom_column FROM users ...
     *
     * // Add with Expression
     * $query->select('id')->addSelect(DB::raw('COUNT(*) as total'));
     * // SELECT id, COUNT(*) as total FROM ...
     * ```
     */
    public function addSelect(string|array|Expression $columns): self
    {
        // Normalize to array
        if ($columns instanceof Expression) {
            $columnsArray = [$columns];
        } else {
            $columnsArray = is_array($columns) ? $columns : func_get_args();
        }

        // If current columns is default ['*'], replace with table.* to preserve all columns
        // This ensures addSelect adds to all columns, not replaces them
        if ($this->columns === ['*'] && $this->table !== null) {
            $this->columns = [$this->table . '.*'];
        }

        // Append new columns
        foreach ($columnsArray as $column) {
            $this->columns[] = $column;
        }

        $this->invalidateCache();
        return $this;
    }

    /**
     * Add a raw SELECT expression to existing columns.
     *
     * Unlike selectRaw() which can be called multiple times but starts fresh with select(),
     * addSelectRaw() explicitly preserves existing columns and appends the raw expression.
     *
     * Performance: O(1) - Single array push operation
     *
     * @param string $expression Raw SQL expression (e.g., "COUNT(*) AS count")
     * @param array<mixed> $bindings Optional bindings for the expression
     * @return $this
     *
     * @example
     * ```php
     * // Add raw expression to existing selection
     * $query->select('id', 'name')
     *       ->addSelectRaw('(SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id) as orders_count');
     * // SELECT id, name, (SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id) as orders_count FROM users
     *
     * // With bindings
     * $query->select('*')
     *       ->addSelectRaw('price * ? as discounted_price', [0.9]);
     * // SELECT *, price * 0.9 as discounted_price FROM ...
     *
     * // Multiple raw expressions
     * $query->select('id')
     *       ->addSelectRaw('UPPER(name) as upper_name')
     *       ->addSelectRaw('LOWER(email) as lower_email');
     * // SELECT id, UPPER(name) as upper_name, LOWER(email) as lower_email FROM ...
     * ```
     */
    public function addSelectRaw(string $expression, array $bindings = []): self
    {
        // If current columns is default ['*'], replace with table.* to preserve all columns
        if ($this->columns === ['*'] && $this->table !== null) {
            $this->columns = [$this->table . '.*'];
        }

        // Append raw expression
        $this->columns[] = new Expression($expression);

        // Add bindings
        foreach ($bindings as $binding) {
            $this->addBinding($binding, 'select');
        }

        $this->invalidateCache();
        return $this;
    }

    /**
     * Add a subselect to the query.
     *
     * Convenient method for adding correlated subqueries as select columns.
     *
     * Performance: O(1) - Delegates to addSelectRaw
     *
     * @param string|\Closure|self $query Subquery as string, closure, or QueryBuilder instance
     * @param string $alias Column alias for the subquery result
     * @return $this
     *
     * @example
     * ```php
     * // With closure
     * $query->select('id', 'name')
     *       ->addSelectSub(function($q) {
     *           $q->from('orders')
     *             ->selectRaw('COUNT(*)')
     *             ->whereRaw('orders.user_id = users.id');
     *       }, 'orders_count');
     *
     * // With QueryBuilder instance
     * $subQuery = DB::table('orders')
     *     ->selectRaw('SUM(total)')
     *     ->whereRaw('orders.user_id = users.id');
     * $query->select('id')->addSelectSub($subQuery, 'total_spent');
     *
     * // With raw SQL string
     * $query->addSelectSub('SELECT MAX(created_at) FROM logins WHERE logins.user_id = users.id', 'last_login');
     * ```
     */
    public function addSelectSub(string|\Closure|self $query, string $alias): self
    {
        // Handle closure
        if ($query instanceof \Closure) {
            $subQuery = new self($this->connection);
            $query($subQuery);
            $query = $subQuery;
        }

        // Handle QueryBuilder instance
        if ($query instanceof self) {
            $sql = $query->toSql();
            $bindings = $query->getBindings();

            return $this->addSelectRaw("({$sql}) AS {$alias}", $bindings);
        }

        // Handle raw SQL string
        return $this->addSelectRaw("({$query}) AS {$alias}");
    }

    /**
     * Get the table name for this query.
     *
     * @return string|null
     */
    public function getTable(): ?string
    {
        return $this->table;
    }

    /**
     * Get the columns for this query.
     *
     * @return array<string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Add a binding to the query.
     *
     * @param mixed $value Binding value
     * @param string $type Binding type (select, join, where, having)
     * @return void
     */
    public function addBinding(mixed $value, string $type = 'where'): void
    {
        if (!array_key_exists($type, $this->bindings)) {
            throw new \InvalidArgumentException("Invalid binding type: {$type}");
        }

        $this->bindings[$type][] = $value;
    }

    /**
     * Add multiple bindings to a specific type.
     *
     * @param array<mixed> $values Binding values
     * @param string $type Binding type (select, join, where, having)
     * @return void
     */
    private function addBindings(array $values, string $type = 'where'): void
    {
        foreach ($values as $value) {
            $this->addBinding($value, $type);
        }
    }

    /**
     * Add a WHERE clause.
     *
     * Supports multiple syntaxes:
     * - where('col', '=', 10)         // Basic comparison
     * - where('col', 10)              // Operator defaults to '='
     * - where(function($q) { ... })   // Nested closure
     *
     * Nested closures allow complex conditions:
     * ```php
     * $query->where('status', 'active')
     *       ->where(function($q) {
     *           $q->where('price', '>', 100)
     *             ->orWhere('featured', true);
     *       });
     * // WHERE status = 'active' AND (price > 100 OR featured = true)
     * ```
     *
     * Performance: O(1) - Closures are compiled to SQL, not executed repeatedly
     *
     * @param string|\Closure $column Column name or closure
     * @param mixed           $operator Operator or value
     * @param mixed           $value Value (optional)
     */
    /**
     * Add a WHERE clause.
     *
     * Accepts column names, Expression objects from DB::raw(), or closures for nested conditions.
     *
     * @param string|Expression|\Closure $column Column name, raw SQL expression, or closure
     * @param mixed $operator Comparison operator or value
     * @param mixed $value Value to compare (optional)
     * @return $this
     *
     * @example
     * ```php
     * // Simple where
     * $query->where('status', 'active');
     *
     * // With raw SQL expression
     * $query->where(DB::raw('DATE(created_at)'), '=', '2024-01-01');
     * $query->where(DB::raw('YEAR(created_at)'), '>', 2023);
     * ```
     */
    public function where(string|Expression|\Closure $column, mixed $operator = null, mixed $value = null): self
    {
        // Handle closure-based WHERE (nested conditions)
        if ($column instanceof \Closure) {
            $result = $this->whereNested($column, 'AND');
            $this->invalidateCache();
            return $result;
        }

        // Handle where($column, $value) syntax
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column instanceof Expression ? (string) $column : $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND'
        ];

        $this->addBinding($value, 'where');
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE clause.
     *
     * Supports both:
     * - orWhere('col', '=', 10)       // Basic OR comparison
     * - orWhere('col', 10)            // Operator defaults to '='
     * - orWhere(function($q) { ... }) // Nested OR closure
     * - orWhere(DB::raw('...'), ...)  // Raw SQL expression
     *
     * Example:
     * ```php
     * $query->where('status', 'active')
     *       ->orWhere(function($q) {
     *           $q->where('role', 'admin')
     *             ->where('verified', true);
     *       });
     * // WHERE status = 'active' OR (role = 'admin' AND verified = true)
     *
     * // With raw SQL expression
     * $query->orWhere(DB::raw('DATE(created_at)'), '=', '2024-01-01');
     * ```
     *
     * @param string|Expression|\Closure $column Column name, raw SQL expression, or closure
     * @param mixed           $operator Operator or value
     * @param mixed           $value Value (optional)
     */
    public function orWhere(string|Expression|\Closure $column, mixed $operator = null, mixed $value = null): self
    {
        // Handle closure-based OR WHERE
        if ($column instanceof \Closure) {
            return $this->whereNested($column, 'OR');
        }

        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR'
        ];

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Add a WHERE IN clause.
     *
     * Performance optimization: If values array is empty, adds WHERE 1=0
     * to return empty result set instead of SQL syntax error.
     * Add WHERE IN clause with array or subquery
     *
     * Supports two syntaxes:
     * 1. Array: whereIn('user_id', [1, 2, 3, 4, 5])
     * 2. Subquery with Closure:
     *    whereIn('user_id', function($query) {
     *        $query->select('id')->from('active_users')->where('status', '=', 'active');
     *    })
     *
     * Architecture:
     * - SOLID: Open/Closed - extensible for subqueries
     * - Clean Architecture: Separates array and subquery logic
     * - High Reusability: Subquery builder reused
     *
     * Performance:
     * - Array: O(n) where n = number of values
     * - Subquery: O(1) + subquery complexity
     *
     * @param string|Expression $column Column name or raw SQL expression
     * @param array|\Closure $values Array of values OR Closure for subquery
     * @param string $boolean Boolean operator (AND/OR)
     * @param bool $not Whether to negate (NOT IN)
     */
    public function whereIn(string|Expression $column, array|\Closure $values, string $boolean = 'AND', bool $not = false): self
    {
        $type = $not ? 'notIn' : 'in';

        // Subquery with Closure
        if ($values instanceof \Closure) {
            // Create subquery builder
            $subQuery = new self($this->connection);

            // Execute closure to build subquery
            $values($subQuery);

            // Store QueryBuilder instance (not SQL string) so compileInSubWhere can call toSql()
            // This ensures bindings are handled correctly and subquery is compiled at the right time
            $this->wheres[] = [
                'type' => $type . 'Sub',
                'column' => $column instanceof Expression ? (string) $column : $column,
                'query' => $subQuery, // Store QueryBuilder instance, not SQL string
                'boolean' => strtoupper($boolean)
            ];

            // CRITICAL FIX: Immediately merge bindings from subquery
            // Bug: When whereIn(closure) is used inside nested where(closure), bindings were not
            // included in getBindings() because they were only merged during compilation.
            // This caused "number of bound variables does not match" errors in whereHasMorph.
            //
            // Fix: Merge bindings immediately so they are available in getBindings() calls.
            foreach ($subQuery->getBindings() as $binding) {
                $this->addBinding($binding, 'where');
            }
        }
        // Array of values
        else {
            // Optimization: Empty array returns no results instead of SQL error
            if (empty($values)) {
                $this->wheres[] = [
                    'type' => 'Raw',
                    'sql' => $not ? '1 = 1' : '1 = 0',  // NOT IN () = always true, IN () = always false
                    'boolean' => strtoupper($boolean)
                ];
                $this->invalidateCache();
                return $this;
            }

            $this->wheres[] = [
                'type' => $type,
                'column' => $column instanceof Expression ? (string) $column : $column,
                'values' => $values,
                'boolean' => strtoupper($boolean)
            ];

            // Add value bindings
            foreach ($values as $value) {
                $this->addBinding($value, 'where');
            }
        }

        $this->invalidateCache();
        return $this;
    }

    /**
     * Add WHERE NOT IN clause
     *
     * Performance: Same as whereIn()
     */
    public function whereNotIn(string $column, array|\Closure $values, string $boolean = 'AND'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add OR WHERE IN clause
     *
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * Performance: Same as whereIn()
     *
     * @param string|Expression $column Column name or raw SQL expression
     * @param array|\Closure $values Array of values or Closure for subquery
     * @return $this
     */
    public function orWhereIn(string|Expression $column, array|\Closure $values): self
    {
        return $this->whereIn($column, $values, 'OR', false);
    }

    /**
     * Add OR WHERE NOT IN clause
     *
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * Performance: Same as whereIn()
     *
     * @param string|Expression $column Column name or raw SQL expression
     * @param array|\Closure $values Array of values or Closure for subquery
     * @return $this
     */
    public function orWhereNotIn(string|Expression $column, array|\Closure $values): self
    {
        return $this->whereIn($column, $values, 'OR', true);
    }

    /**
     * Add a WHERE IS NULL clause.
     *
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * @param string|Expression $column Column name or raw SQL expression
     * @return $this
     *
     * @example
     * ```php
     * $query->whereNull('deleted_at');
     * $query->whereNull(DB::raw('DATE(created_at)'));
     * ```
     */
    public function whereNull(string|Expression $column): self
    {
        $this->wheres[] = [
            'type' => 'Null',
            'column' => $column instanceof Expression ? (string) $column : $column,
            'boolean' => 'AND'
        ];

        return $this;
    }

    /**
     * Add a nested WHERE clause group.
     *
     * This method is called internally by where() and orWhere() when a closure is passed.
     * It creates a sub-query builder, passes it to the closure, then wraps the result in parentheses.
     *
     * Architecture:
     * - Single Responsibility: Only handles nested WHERE logic
     * - Open/Closed: Closures can build any complexity without changing this method
     * - Dependency Inversion: Depends on QueryBuilder abstraction
     *
     * Performance: O(1) - Creates one nested query group regardless of closure complexity
     *
     * @param \Closure $callback Callback receiving a fresh QueryBuilder
     * @param string   $boolean Boolean operator (AND/OR)
     * @return $this
     *
     * @internal
     */
    protected function whereNested(\Closure $callback, string $boolean = 'AND'): self
    {
        // Create a fresh query builder for the nested conditions
        $query = $this->newQuery();
        $query->table($this->table);

        // Execute closure to build nested conditions
        $callback($query);

        // PERFORMANCE & CORRECTNESS FIX: Skip empty nested WHERE clauses
        // If closure didn't add any conditions, don't add empty WHERE ()
        // This prevents SQL syntax errors like "WHERE () ORDER BY ..."
        $nestedWheres = $query->getWheres();
        if (empty($nestedWheres)) {
            return $this; // Skip empty nested WHERE
        }

        // Add the nested query to our wheres
        $this->wheres[] = [
            'type' => 'nested',
            'query' => $query,
            'boolean' => $boolean
        ];

        // Merge bindings from nested query
        foreach ($query->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * Add a raw WHERE clause.
     *
     * @param string $sql Raw SQL condition (e.g., "price > ? AND stock < ?")
     * @param array<mixed> $bindings Bindings for the placeholders
     * @return $this
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = [
            'type' => 'Raw',
            'sql' => $sql,
            'boolean' => 'AND'
        ];

        foreach ($bindings as $binding) {
            $this->addBinding($binding, 'where');
        }

        // CRITICAL FIX: Invalidate SQL cache when WHERE clause is modified
        // Bug: whereRaw() was not invalidating cache, causing toSql() to return stale SQL
        // This caused whereHasMorph to query wrong table when orderBy had no table prefix
        $this->invalidateCache();

        return $this;
    }

    /**
     * Add a WHERE IS NOT NULL clause.
     */
    /**
     * Add a WHERE IS NOT NULL clause.
     *
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * @param string|Expression $column Column name or raw SQL expression
     * @return $this
     *
     * @example
     * ```php
     * $query->whereNotNull('deleted_at');
     * $query->whereNotNull(DB::raw('DATE(created_at)'));
     * ```
     */
    public function whereNotNull(string|Expression $column): self
    {
        $this->wheres[] = [
            'type' => 'NotNull',
            'column' => $column instanceof Expression ? (string) $column : $column,
            'boolean' => 'AND'
        ];

        return $this;
    }

    /**
     * Add a WHERE clause comparing two columns.
     *
     * This is essential for queries comparing columns against each other,
     * not against literal values.
     *
     * @param string|Expression $first First column name or expression
     * @param string $operator Comparison operator (=, <, >, <=, >=, <>, !=)
     * @param string|Expression|null $second Second column name or expression (defaults to $operator if null)
     * @param string $boolean Boolean operator (AND/OR)
     * @return $this
     *
     * @example
     * ```php
     * // Find users where updated_at > created_at
     * $query->whereColumn('updated_at', '>', 'created_at');
     *
     * // Short syntax (operator defaults to =)
     * $query->whereColumn('first_name', 'last_name');
     *
     * // With table prefixes
     * $query->whereColumn('posts.user_id', '=', 'users.id');
     *
     * // Multiple column comparisons using array
     * $query->whereColumn([
     *     ['first_name', '=', 'last_name'],
     *     ['updated_at', '>', 'created_at'],
     * ]);
     * ```
     */
    public function whereColumn(
        string|Expression|array $first,
        ?string $operator = null,
        string|Expression|null $second = null,
        string $boolean = 'AND'
    ): self {
        // Handle array of column comparisons
        if (is_array($first)) {
            foreach ($first as $comparison) {
                if (count($comparison) === 2) {
                    $this->whereColumn($comparison[0], '=', $comparison[1], $boolean);
                } elseif (count($comparison) >= 3) {
                    $this->whereColumn($comparison[0], $comparison[1], $comparison[2], $boolean);
                }
            }
            return $this;
        }

        // Handle short syntax: whereColumn('col1', 'col2') -> whereColumn('col1', '=', 'col2')
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }

        // Validate operator
        $validOperators = ['=', '<', '>', '<=', '>=', '<>', '!=', 'LIKE', 'NOT LIKE'];
        $normalizedOperator = strtoupper($operator ?? '=');
        if (!in_array($normalizedOperator, $validOperators, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid whereColumn operator: "%s"', $operator)
            );
        }

        $this->wheres[] = [
            'type' => 'Column',
            'first' => $first instanceof Expression ? (string) $first : $first,
            'operator' => $normalizedOperator,
            'second' => $second instanceof Expression ? (string) $second : $second,
            'boolean' => strtoupper($boolean),
        ];

        $this->invalidateCache();

        return $this;
    }

    /**
     * Add an OR WHERE clause comparing two columns.
     *
     * @param string|Expression|array $first First column name or expression
     * @param string|null $operator Comparison operator
     * @param string|Expression|null $second Second column name
     * @return $this
     *
     * @example
     * ```php
     * $query->where('status', 'active')
     *       ->orWhereColumn('updated_at', '>', 'created_at');
     * ```
     */
    public function orWhereColumn(
        string|Expression|array $first,
        ?string $operator = null,
        string|Expression|null $second = null
    ): self {
        return $this->whereColumn($first, $operator, $second, 'OR');
    }

    /**
     * Add an ORDER BY clause.
     *
     * Accepts column names or Expression objects from DB::raw().
     * SECURITY: Direction is validated to prevent SQL injection.
     *
     * @param string|Expression $column Column name or raw SQL expression
     * @param string $direction Sort direction (ASC or DESC)
     * @return $this
     * @throws \InvalidArgumentException If direction is not ASC or DESC
     *
     * @example
     * ```php
     * // Simple column
     * $query->orderBy('created_at', 'DESC');
     *
     * // Raw SQL expression
     * $query->orderBy(DB::raw('RAND()'));
     * $query->orderBy(DB::raw('FIELD(status, "active", "pending", "inactive")'));
     * ```
     */
    public function orderBy(string|Expression $column, string $direction = 'ASC'): self
    {
        // Validate direction to prevent SQL injection
        $validatedDirection = $this->validateOrderDirection($direction);

        $this->orders[] = [
            'column' => $column instanceof Expression ? (string) $column : $column,
            'direction' => $validatedDirection,
            'isExpression' => $column instanceof Expression,
        ];
        $this->invalidateCache();
        return $this;
    }

    /**
     * Add a raw ORDER BY clause with parameter bindings.
     *
     * Useful for complex ORDER BY expressions that require parameter binding
     * for security (prevent SQL injection when sorting by user input).
     *
     * @param string $sql Raw SQL expression for ORDER BY
     * @param array<mixed> $bindings Parameter bindings for the expression
     * @return $this
     *
     * @example
     * ```php
     * // Order by FIELD with parameters (custom sort order)
     * $query->orderByRaw('FIELD(status, ?, ?, ?)', ['active', 'pending', 'inactive']);
     *
     * // Order by distance calculation
     * $query->orderByRaw('SQRT(POW(lat - ?, 2) + POW(lng - ?, 2))', [51.5074, -0.1278]);
     *
     * // Order by conditional expression
     * $query->orderByRaw('CASE WHEN priority = ? THEN 0 ELSE 1 END, created_at DESC', ['high']);
     *
     * // Order by JSON field extraction
     * $query->orderByRaw('JSON_EXTRACT(metadata, ?) DESC', ['$.score']);
     * ```
     */
    public function orderByRaw(string $sql, array $bindings = []): self
    {
        $this->orders[] = [
            'column' => $sql,
            'direction' => '', // Raw SQL already includes direction
            'isExpression' => true,
        ];

        foreach ($bindings as $binding) {
            $this->addBinding($binding, 'order');
        }

        $this->invalidateCache();
        return $this;
    }

    /**
     * Set LIMIT.
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        $this->invalidateCache();
        return $this;
    }

    /**
     * Set OFFSET.
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        $this->invalidateCache();
        return $this;
    }

    /**
     * Add a JOIN clause with simple or complex conditions
     *
     * Supports two syntaxes:
     * 1. Simple: join('orders', 'users.id', '=', 'orders.user_id')
     * 2. Complex with Closure:
     *    join('orders', function($join) {
     *        $join->on('users.id', '=', 'orders.user_id')
     *             ->where('orders.status', '=', 'active');
     *    })
     *
     * Architecture:
     * - SOLID: Open/Closed - extensible without modification
     * - Clean Architecture: Separates simple and complex JOIN logic
     * - High Reusability: JoinClause can be reused for all JOIN types
     *
     * Performance:
     * - Simple JOIN: O(1)
     * - Complex JOIN: O(n) where n = number of conditions
     *
     * @param string $table Table to join
     * @param \Closure|string $first Closure for complex conditions OR first column
     * @param string|null $operator Comparison operator (=, !=, <, >, etc.)
     * @param string|null $second Second column
     * @param string $type JOIN type (INNER, LEFT, RIGHT, CROSS)
     */
    /**
     * Add a JOIN clause.
     *
     * Accepts Expression objects from DB::raw() for raw SQL in join conditions.
     *
     * @param string $table Table to join
     * @param \Closure|string|Expression $first Closure for complex conditions, first column, or raw SQL expression
     * @param string|null $operator Comparison operator
     * @param string|Expression|null $second Second column or raw SQL expression
     * @param string $type JOIN type (INNER, LEFT, RIGHT, etc.)
     * @return $this
     *
     * @example
     * ```php
     * // Simple join
     * $query->join('orders', 'users.id', '=', 'orders.user_id');
     *
     * // With raw SQL expressions
     * $query->join('orders', DB::raw('users.id'), '=', DB::raw('orders.user_id'));
     * $query->join('orders', DB::raw('DATE(users.created_at)'), '=', DB::raw('DATE(orders.created_at)'));
     *
     * // Complex join with closure
     * $query->join('orders', function($join) {
     *     $join->on('users.id', '=', 'orders.user_id')
     *          ->on(DB::raw('YEAR(users.created_at)'), '=', DB::raw('YEAR(orders.created_at)'));
     * });
     * ```
     */
    public function join(
        string $table,
        \Closure|string|Expression $first,
        ?string $operator = null,
        string|Expression|null $second = null,
        string $type = 'INNER'
    ): self {
        // Complex JOIN with Closure
        if ($first instanceof \Closure) {
            $joinClause = new JoinClause($type, $table);
            $joinClause->setParentQuery($this);

            // Execute closure to build conditions
            $first($joinClause);

            // Store as JoinClause object
            $this->joins[] = $joinClause;
        }
        // Simple JOIN with string parameters
        else {
            // Backward compatibility: store as array
            $this->joins[] = [
                'type' => strtoupper($type),
                'table' => $table,
                'first' => $first instanceof Expression ? (string) $first : $first,
                'operator' => $operator ?? '=',
                'second' => $second instanceof Expression ? (string) $second : $second
            ];
        }

        $this->invalidateCache();
        return $this;
    }

    /**
     * Add a LEFT JOIN clause
     *
     * Supports both simple and complex syntax like join()
     *
     * Performance: Same as join()
     */
    /**
     * Add a LEFT JOIN clause.
     *
     * Accepts Expression objects from DB::raw() for raw SQL in join conditions.
     *
     * @param string $table Table to join
     * @param \Closure|string|Expression $first Closure, first column, or raw SQL expression
     * @param string|null $operator Comparison operator
     * @param string|Expression|null $second Second column or raw SQL expression
     * @return $this
     */
    public function leftJoin(
        string $table,
        \Closure|string|Expression $first,
        ?string $operator = null,
        string|Expression|null $second = null
    ): self {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * Add a RIGHT JOIN clause
     *
     * Supports both simple and complex syntax like join()
     *
     * Performance: Same as join()
     */
    /**
     * Add a RIGHT JOIN clause.
     *
     * Accepts Expression objects from DB::raw() for raw SQL in join conditions.
     *
     * @param string $table Table to join
     * @param \Closure|string|Expression $first Closure, first column, or raw SQL expression
     * @param string|null $operator Comparison operator
     * @param string|Expression|null $second Second column or raw SQL expression
     * @return $this
     */
    public function rightJoin(
        string $table,
        \Closure|string|Expression $first,
        ?string $operator = null,
        string|Expression|null $second = null
    ): self {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    /**
     * Add a CROSS JOIN clause
     *
     * Cross joins don't have ON conditions, only table name
     *
     * Performance: O(1)
     */
    public function crossJoin(string $table): self
    {
        $this->joins[] = [
            'type' => 'CROSS',
            'table' => $table
        ];

        $this->invalidateCache();
        return $this;
    }

    /**
     * Add a FULL OUTER JOIN clause.
     *
     * Returns all rows from both tables, matching where possible.
     * Supported by PostgreSQL and SQL Server. MySQL doesn't support FULL OUTER JOIN.
     *
     * Example:
     * ```php
     * $query->fullOuterJoin('orders', 'users.id', '=', 'orders.user_id');
     * // FULL OUTER JOIN orders ON users.id = orders.user_id
     * ```
     *
     * Performance: O(1) - Single JOIN clause addition
     *
     * @param string $table Table to join
     * @param \Closure|string $first Closure for complex conditions OR first column
     * @param string|null $operator Comparison operator
     * @param string|null $second Second column
     * @return $this
     */
    /**
     * Add a FULL OUTER JOIN clause.
     *
     * Accepts Expression objects from DB::raw() for raw SQL in join conditions.
     *
     * @param string $table Table to join
     * @param \Closure|string|Expression $first Closure, first column, or raw SQL expression
     * @param string|null $operator Comparison operator
     * @param string|Expression|null $second Second column or raw SQL expression
     * @return $this
     */
    public function fullOuterJoin(
        string $table,
        \Closure|string|Expression $first,
        ?string $operator = null,
        string|Expression|null $second = null
    ): self {
        return $this->join($table, $first, $operator, $second, 'FULL OUTER');
    }

    /**
     * FULL OUTER JOIN with subquery.
     *
     * Accepts Expression objects from DB::raw() for raw SQL in join conditions.
     *
     * @param QueryBuilder|string $query Subquery QueryBuilder or raw SQL
     * @param string $as Alias for the derived table
     * @param \Closure|string|Expression $first Closure, first column, or raw SQL expression
     * @param string|null $operator Comparison operator
     * @param string|Expression|null $second Second column or raw SQL expression
     * @return $this
     *
     * @example
     * ```php
     * $query->fullOuterJoinSub($subQuery, 'stats', 'users.id', '=', 'stats.user_id');
     * $query->fullOuterJoinSub($subQuery, 'stats', DB::raw('users.id'), '=', DB::raw('stats.user_id'));
     * ```
     */
    public function fullOuterJoinSub(
        QueryBuilder|string $query,
        string $as,
        \Closure|string|Expression $first,
        ?string $operator = null,
        string|Expression|null $second = null
    ): self {
        return $this->joinSub($query, $as, $first, $operator, $second, 'FULL OUTER');
    }

    /**
     * Join with a subquery (derived table)
     *
     * Accepts Expression objects from DB::raw() for raw SQL in join conditions.
     *
     * Usage:
     * ```php
     * $subQuery = (new QueryBuilder($connection))
     *     ->select('user_id', 'COUNT(*) as order_count')
     *     ->from('orders')
     *     ->groupBy('user_id');
     *
     * $query->joinSub($subQuery, 'order_stats', 'users.id', '=', 'order_stats.user_id');
     * ```
     *
     * Or with Closure:
     * ```php
     * $query->joinSub($subQuery, 'order_stats', function($join) {
     *     $join->on('users.id', '=', 'order_stats.user_id')
     *          ->where('order_stats.order_count', '>', 5);
     * });
     * ```
     *
     * With raw SQL expressions:
     * ```php
     * $query->joinSub($subQuery, 'order_stats',
     *     DB::raw('users.id'), '=', DB::raw('order_stats.user_id'));
     *
     * $query->joinSub($subQuery, 'order_stats', function($join) {
     *     $join->on(DB::raw('DATE(users.created_at)'), '=', DB::raw('DATE(order_stats.created_at)'));
     * });
     * ```
     *
     * Architecture:
     * - SOLID: Single Responsibility (handles subquery joins only)
     * - Clean Architecture: Separates subquery logic from simple joins
     * - High Reusability: Works with any QueryBuilder instance
     *
     * Performance:
     * - O(1) for storing join + O(subquery complexity)
     * - Subquery compiled lazily when toSql() is called
     *
     * @param QueryBuilder|string $query Subquery QueryBuilder or raw SQL
     * @param string $as Alias for the derived table
     * @param \Closure|string|Expression $first Closure for complex conditions, first column, or raw SQL expression
     * @param string|null $operator Comparison operator
     * @param string|Expression|null $second Second column or raw SQL expression
     * @param string $type JOIN type (INNER, LEFT, RIGHT, FULL OUTER)
     * @return $this
     */
    public function joinSub(
        QueryBuilder|string $query,
        string $as,
        \Closure|string|Expression $first,
        ?string $operator = null,
        string|Expression|null $second = null,
        string $type = 'INNER'
    ): self {
        // Convert QueryBuilder to SQL
        $subquerySql = $query instanceof QueryBuilder ? $query->toSql() : $query;

        // Get bindings from subquery
        if ($query instanceof QueryBuilder) {
            foreach ($query->getBindings() as $binding) {
                $this->addBinding($binding, 'join');
            }
        }

        // Complex JOIN with Closure
        if ($first instanceof \Closure) {
            $joinClause = new JoinClause($type, "($subquerySql) AS $as");
            $joinClause->setParentQuery($this);

            $first($joinClause);

            $this->joins[] = $joinClause;
        }
        // Simple JOIN
        else {
            $this->joins[] = [
                'type' => strtoupper($type),
                'table' => "($subquerySql) AS $as",
                'first' => $first instanceof Expression ? (string) $first : $first,
                'operator' => $operator ?? '=',
                'second' => $second instanceof Expression ? (string) $second : $second,
                'isSubquery' => true
            ];
        }

        $this->invalidateCache();
        return $this;
    }

    /**
     * LEFT JOIN with subquery.
     *
     * Accepts Expression objects from DB::raw() for raw SQL in join conditions.
     *
     * Performance: Same as joinSub()
     *
     * @param QueryBuilder|string $query Subquery QueryBuilder or raw SQL
     * @param string $as Alias for the derived table
     * @param \Closure|string|Expression $first Closure, first column, or raw SQL expression
     * @param string|null $operator Comparison operator
     * @param string|Expression|null $second Second column or raw SQL expression
     * @return $this
     *
     * @example
     * ```php
     * $query->leftJoinSub($subQuery, 'stats', 'users.id', '=', 'stats.user_id');
     * $query->leftJoinSub($subQuery, 'stats', DB::raw('users.id'), '=', DB::raw('stats.user_id'));
     * ```
     */
    public function leftJoinSub(
        QueryBuilder|string $query,
        string $as,
        \Closure|string|Expression $first,
        ?string $operator = null,
        string|Expression|null $second = null
    ): self {
        return $this->joinSub($query, $as, $first, $operator, $second, 'LEFT');
    }

    /**
     * RIGHT JOIN with subquery.
     *
     * Accepts Expression objects from DB::raw() for raw SQL in join conditions.
     *
     * Performance: Same as joinSub()
     *
     * @param QueryBuilder|string $query Subquery QueryBuilder or raw SQL
     * @param string $as Alias for the derived table
     * @param \Closure|string|Expression $first Closure, first column, or raw SQL expression
     * @param string|null $operator Comparison operator
     * @param string|Expression|null $second Second column or raw SQL expression
     * @return $this
     *
     * @example
     * ```php
     * $query->rightJoinSub($subQuery, 'stats', 'users.id', '=', 'stats.user_id');
     * $query->rightJoinSub($subQuery, 'stats', DB::raw('users.id'), '=', DB::raw('stats.user_id'));
     * ```
     */
    public function rightJoinSub(
        QueryBuilder|string $query,
        string $as,
        \Closure|string|Expression $first,
        ?string $operator = null,
        string|Expression|null $second = null
    ): self {
        return $this->joinSub($query, $as, $first, $operator, $second, 'RIGHT');
    }

    /**
     * Add a GROUP BY clause.
     *
     * Supports multiple syntaxes:
     * - groupBy('category')                  // Single column
     * - groupBy('category', 'status')        // Multiple columns (varargs)
     * - groupBy(['category', 'status'])      // Array of columns
     *
     * Clean Architecture:
     * - Simple, focused method (Single Responsibility)
     * - Fluent interface for chaining
     * - No side effects beyond state update
     *
     * Performance: O(1) - Just appends to array
     *
     * @param string|array<string> $columns Column(s) to group by
     * @return $this
     *
     * @example
     * // Group by single column
     * $query->groupBy('category');
     *
     * // Group by multiple columns
     * $query->groupBy('category', 'status');
     * $query->groupBy(['category', 'status']);
     */
    /**
     * Add GROUP BY clause.
     *
     * Accepts column names, arrays, or Expression objects from DB::raw().
     *
     * @param string|array|Expression ...$columns Column names, arrays, or raw SQL expressions
     * @return $this
     *
     * @example
     * ```php
     * // Simple columns
     * $query->groupBy('category', 'status');
     *
     * // With raw SQL expression
     * $query->groupBy(DB::raw('DATE(created_at)'));
     * $query->groupBy('category', DB::raw('YEAR(created_at)'));
     * ```
     */
    public function groupBy(string|array|Expression ...$columns): self
    {
        // Flatten arguments: groupBy('a', 'b') or groupBy(['a', 'b'])
        foreach ($columns as $column) {
            if (is_array($column)) {
                foreach ($column as $col) {
                    $this->groups[] = $col instanceof Expression ? (string) $col : $col;
                }
            } else {
                $this->groups[] = $column instanceof Expression ? (string) $column : $column;
            }
        }

        return $this;
    }

    /**
     * Add a raw GROUP BY clause with parameter bindings.
     *
     * Useful for complex GROUP BY expressions that require parameter binding
     * for security (prevent SQL injection).
     *
     * @param string $sql Raw SQL expression for GROUP BY
     * @param array<mixed> $bindings Parameter bindings for the expression
     * @return $this
     *
     * @example
     * ```php
     * // Group by date format with parameter
     * $query->groupByRaw('DATE_FORMAT(created_at, ?)', ['%Y-%m']);
     *
     * // Group by conditional expression
     * $query->groupByRaw('CASE WHEN price > ? THEN "expensive" ELSE "cheap" END', [100]);
     *
     * // Group by extracted JSON field
     * $query->groupByRaw('JSON_EXTRACT(metadata, ?)', ['$.category']);
     * ```
     */
    public function groupByRaw(string $sql, array $bindings = []): self
    {
        $this->groups[] = $sql;

        foreach ($bindings as $binding) {
            $this->addBinding($binding, 'group');
        }

        return $this;
    }

    /**
     * Add a HAVING clause.
     *
     * Syntax: having('column', 'operator', 'value')
     * Example: having('COUNT(*)', '>', 5)
     *
     * HAVING is used with GROUP BY to filter aggregated results.
     * Accepts Expression objects from DB::raw() for raw SQL expressions.
     *
     * @param string|Expression $column Column or aggregate expression
     * @param string $operator Comparison operator
     * @param mixed  $value Value to compare
     * @return $this
     *
     * @example
     * ```php
     * // Simple having
     * $query->select(['category', 'COUNT(*) as count'])
     *       ->groupBy('category')
     *       ->having('count', '>', 10);
     *
     * // With raw SQL expression
     * $query->having(DB::raw('COUNT(*)'), '>', 5);
     * ```
     */
    public function having(string|Expression $column, string $operator, mixed $value): self
    {
        $this->havings[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND'
        ];

        $this->addBinding($value, 'having');

        return $this;
    }

    /**
     * Add a raw HAVING clause.
     *
     * @param string $sql Raw SQL expression
     * @param array $bindings Optional bindings for the expression
     * @return $this
     *
     * @example
     * $query->havingRaw('AVG(rating) >= ?', [4.5]);
     * $query->havingRaw('COUNT(*) > 10');
     */
    public function havingRaw(string $sql, array $bindings = []): self
    {
        $this->havings[] = [
            'type' => 'Raw',
            'sql' => $sql,
            'boolean' => 'AND'
        ];

        foreach ($bindings as $binding) {
            $this->addBinding($binding, 'having');
        }

        return $this;
    }

    /**
     * Add an OR HAVING clause.
     *
     * @param string $column Column or aggregate expression
     * @param string $operator Comparison operator
     * @param mixed  $value Value to compare
     * @return $this
     */
    public function orHaving(string $column, string $operator, mixed $value): self
    {
        $this->havings[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR'
        ];

        $this->addBinding($value, 'having');

        return $this;
    }

    /**
     * Add DISTINCT to the SELECT query.
     *
     * Returns only unique rows based on ALL selected columns.
     *
     * Performance:
     * - Database handles DISTINCT efficiently with indexes
     * - More efficient than manual array_unique() in PHP
     *
     * @return $this
     *
     * @example
     * // Get unique categories
     * $query->select('category')->distinct()->get();
     *
     * // Get unique combinations
     * $query->select(['category', 'status'])->distinct()->get();
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Order results by a column in ascending order (oldest first).
     *
     * Shortcut for: orderBy($column, 'ASC')
     *
     * @param string $column Column to order by (default: 'created_at')
     * @return $this
     *
     * @example
     * // Oldest posts first
     * $query->oldest('created_at')->get();
     *
     * // Oldest by custom column
     * $query->oldest('published_at')->get();
     */
    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    /**
     * Order results by a column in descending order (latest first).
     *
     * Shortcut for: orderBy($column, 'DESC')
     *
     * @param string $column Column to order by (default: 'created_at')
     * @return $this
     *
     * @example
     * // Latest posts first
     * $query->latest('created_at')->get();
     *
     * // Latest by custom column
     * $query->latest('updated_at')->get();
     */
    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Randomize the order of results.
     *
     * Uses RAND() for MySQL-compatible databases.
     *
     * Performance Warning:
     * - RANDOM() can be slow on large tables
     * - Consider using LIMIT with inRandomOrder() for better performance
     *
     * @return $this
     *
     * @example
     * // Get 10 random products
     * $query->inRandomOrder()->limit(10)->get();
     */
    public function inRandomOrder(): self
    {
        // Get database-specific random function from Grammar
        // - MySQL/MariaDB: RAND()
        // - PostgreSQL/SQLite: RANDOM()
        // - MongoDB: Uses $sample aggregation (handled separately)
        $randomFunction = $this->connection->getGrammar()->compileRandomOrderFunction();

        $this->orders[] = [
            'column' => $randomFunction,
            'direction' => '' // No direction for RANDOM()
        ];

        return $this;
    }

    /**
     * Shortcut for limit().
     *
     * @param int $limit Number of records to take
     * @return $this
     */
    public function take(int $limit): self
    {
        return $this->limit($limit);
    }

    /**
     * Shortcut for offset().
     *
     * @param int $offset Number of records to skip
     * @return $this
     */
    public function skip(int $offset): self
    {
        return $this->offset($offset);
    }

    /**
     * Execute the built SELECT and return rows.
     *
     * @return DatabaseCollection<int, array<string,mixed>>
     */
    public function get(): DatabaseCollection
    {
        $sql = $this->toSql();

        // Connection::select() will log the query automatically
        // No need to log here to avoid duplicate logs
        $rows = $this->connection->select($sql, $this->getBindings()); // array<array>

        return new RowCollection($rows);
    }

    /**
     * Execute the built SELECT with LIMIT 1 and return the first row or null.
     *
     * Return type is mixed to allow ModelQueryBuilder to override with Model return type.
     *
     * @return array<string,mixed>|null
     */
    public function first(): mixed
    {
        $this->limit(1);
        $collection = $this->get();
        $first = $collection->first();
        return is_array($first) ? $first : null;
    }

    /**
     * Alias of get() for a more collection-oriented naming.
     *
     * @return RowCollection<int, array<string,mixed>>
     */
    public function collect(): RowCollection
    {
        /** @var RowCollection<int, array<string,mixed>> $result */
        $result = $this->get();
        return $result;
    }

    /**
     * Backward-compatible helper to return raw array results.
     *
     * @return array<int, array<string,mixed>>
     */
    public function getArray(): array
    {
        /** @var RowCollection<int, array<string,mixed>> $collection */
        $collection = $this->get();
        return $collection->toArray();
    }

    /**
     * Get a collection of a given column's values.
     *
     * Executes the query and extracts the specified column from each row.
     *
     * @param string|array $column Column name (or dot-separated path) to extract from each row.
     * @return DatabaseCollection Collection containing the plucked values.
     */
    public function pluck(string|array $column): DatabaseCollection
    {
        $collection = $this->get();
        return $collection->pluck($column);
    }

    /**
     * Find a row by primary key column.
     *
     * Return type is mixed to allow ModelQueryBuilder to override with Model return type.
     *
     * @param int|string $id
     * @param string     $column Primary key column (default: 'id').
     * @return array<string,mixed>|null
     */
    public function find(int|string $id, string $column = 'id'): mixed
    {
        return $this->where($column, $id)->first();
    }

    /**
     * Get results as a LazyCollection for memory-efficient processing.
     *
     * Returns a LazyCollection that uses PDO cursor to stream results one at a time.
     * This is ideal for processing large datasets without loading everything into memory.
     *
     * The LazyCollection supports all collection methods (map, filter, etc.) and can be
     * chained seamlessly, just like regular collections.
     *
     * Example:
     * ```php
     * $users = DB::table('users')
     *     ->where('active', true)
     *     ->toLazyCollection()
     *     ->map(fn($user) => $user['name'])
     *     ->filter(fn($name) => strlen($name) > 5)
     *     ->take(100);
     *
     * foreach ($users as $name) {
     *     echo $name;
     * }
     * ```
     *
     * Performance:
     * - Memory: O(1) - Only one record in memory at a time
     * - Time: O(N) - Processes all records
     * - Database: Single query, PDO streams results
     *
     * Note: Cursor keeps database connection open during iteration.
     * Don't use for long-running processes that need connection pooling.
     *
     * @return \Toporia\Framework\Support\Collection\LazyCollection<int, array<string, mixed>>
     */
    public function toLazyCollection(): LazyCollection
    {
        return LazyCollection::make(function () {
            yield from $this->cursor();
        });
    }

    /**
     * Get results as a LazyCollection using chunked pagination.
     *
     * Alternative to toLazyCollection() that uses chunked queries instead of cursor.
     * This is useful when cursor() is not available or when you need more control
     * over memory usage with chunked processing.
     *
     * Example:
     * ```php
     * $users = DB::table('users')
     *     ->toLazyCollectionByChunk(1000)
     *     ->map(fn($user) => processUser($user))
     *     ->filter(fn($user) => $user['active']);
     * ```
     *
     * Performance:
     * - Memory: O(chunkSize) - Only chunkSize records in memory at a time
     * - Time: O(N) - Processes all records
     * - Database: Multiple queries with LIMIT/OFFSET pagination
     *
     * @param int $chunkSize Number of records to fetch per database query (default: 1000)
     * @return \Toporia\Framework\Support\Collection\LazyCollection<int, array<string, mixed>>
     */
    public function toLazyCollectionByChunk(int $chunkSize = 1000): LazyCollection
    {
        return LazyCollection::make(function () use ($chunkSize) {
            yield from $this->lazy($chunkSize);
        });
    }

    /**
     * Find multiple rows by primary key values.
     *
     * @param array<int|string> $ids Array of primary key values
     * @param string $column Primary key column (default: 'id')
     * @return RowCollection Collection of matching rows
     *
     * @example
     * ```php
     * $users = DB::table('users')->findMany([1, 2, 3]);
     * // SELECT * FROM users WHERE id IN (1, 2, 3)
     * ```
     */
    public function findMany(array $ids, string $column = 'id'): RowCollection
    {
        if (empty($ids)) {
            return new RowCollection([]);
        }

        return $this->whereIn($column, $ids)->get();
    }

    /**
     * Get the first row or return a default value.
     *
     * Useful when you want a fallback instead of null.
     *
     * @param mixed $default Default value or callable that returns default
     * @return mixed First row or default value
     *
     * @example
     * ```php
     * // With default value
     * $user = DB::table('users')->where('id', 999)->firstOr(['name' => 'Guest']);
     *
     * // With callback
     * $user = DB::table('users')->where('id', 999)->firstOr(function() {
     *     return ['name' => 'New User', 'role' => 'guest'];
     * });
     * ```
     */
    public function firstOr(mixed $default = null): mixed
    {
        $result = $this->first();

        if ($result !== null) {
            return $result;
        }

        return is_callable($default) ? $default() : $default;
    }

    /**
     * Get the first row or throw an exception.
     *
     * Use this when you expect the row to exist and want to fail fast if it doesn't.
     *
     * @return array<string,mixed> First row
     * @throws \RuntimeException If no rows found
     *
     * @example
     * ```php
     * try {
     *     $user = DB::table('users')->where('id', 1)->firstOrFail();
     * } catch (\RuntimeException $e) {
     *     // Handle not found
     * }
     * ```
     */
    public function firstOrFail(): array
    {
        $result = $this->first();

        if ($result === null) {
            throw new \RuntimeException(
                sprintf('No query results for table [%s].', $this->table ?? 'unknown')
            );
        }

        return $result;
    }

    /**
     * Get the only row matching the query or throw an exception.
     *
     * This method is strict: it throws if there are zero OR more than one results.
     * Use when you expect EXACTLY one match.
     *
     * @return array<string,mixed> The single matching row
     * @throws \RuntimeException If no rows or more than one row found
     *
     * @example
     * ```php
     * // Expect exactly one admin user
     * $admin = DB::table('users')->where('role', 'admin')->sole();
     *
     * // Throws if:
     * // - No admins exist (no matching records)
     * // - Multiple admins exist (more than one record)
     * ```
     */
    public function sole(): array
    {
        $this->limit(2); // Only fetch 2 to detect "more than one"

        $collection = $this->get();
        $count = $collection->count();

        if ($count === 0) {
            throw new \RuntimeException(
                sprintf('No query results for table [%s].', $this->table ?? 'unknown')
            );
        }

        if ($count > 1) {
            throw new \RuntimeException(
                sprintf('Multiple records found for table [%s] where only one was expected.', $this->table ?? 'unknown')
            );
        }

        $first = $collection->first();
        return is_array($first) ? $first : [];
    }

    /**
     * Get the sole matching row or return a default value.
     *
     * Unlike sole(), this doesn't throw on zero results.
     * Still throws if more than one result is found.
     *
     * @param mixed $default Default value if no rows found
     * @return mixed The single row or default
     * @throws \RuntimeException If more than one row found
     *
     * @example
     * ```php
     * $config = DB::table('settings')
     *     ->where('key', 'app_name')
     *     ->soleOr(['value' => 'My App']);
     * ```
     */
    public function soleOr(mixed $default = null): mixed
    {
        $this->limit(2);

        $collection = $this->get();
        $count = $collection->count();

        if ($count === 0) {
            return is_callable($default) ? $default() : $default;
        }

        if ($count > 1) {
            throw new \RuntimeException(
                sprintf('Multiple records found for table [%s] where only one was expected.', $this->table ?? 'unknown')
            );
        }

        $first = $collection->first();
        return is_array($first) ? $first : $default;
    }

    /**
     * Insert a single row and return the last inserted id.
     *
     * Uses Grammar pattern for database-specific SQL compilation.
     *
     * @param array<string,mixed> $data
     */
    public function insert(array $data): int
    {
        // Use Grammar for INSERT compilation
        $grammar = $this->connection->getGrammar();
        $sql = $grammar->compileInsert($this, $data);

        // Flatten bindings for bulk insert
        // If $data is array of arrays (multiple rows), flatten it
        // If $data is single row, just use array_values
        if (isset($data[0]) && is_array($data[0])) {
            // Multiple rows: flatten all values
            $bindings = [];
            foreach ($data as $row) {
                $bindings = array_merge($bindings, array_values($row));
            }
        } else {
            // Single row
            $bindings = array_values($data);
        }

        // Execute INSERT query
        // Note: Connection::execute() doesn't log, but we log INSERT specifically
        // because it's important for query log visibility
        $startTime = null;
        if (self::$loggingEnabled) {
            $startTime = microtime(true);
        }

        $this->connection->execute($sql, $bindings);
        $insertId = (int) $this->connection->lastInsertId();

        // Log INSERT query execution
        if ($startTime !== null) {
            $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            self::logQuery($sql, $bindings, $executionTime);
        }

        return $insertId;
    }

    /**
     * Update rows matching the WHERE clauses.
     *
     * Supports Expression objects from DB::raw() in values (Toporia style).
     *
     * Uses Grammar pattern for database-specific SQL compilation.
     *
     * @param array<string,mixed|Expression> $data Column => value pairs, values can be Expression objects
     * @return int Number of affected rows
     *
     * @example
     * ```php
     * // Simple update
     * DB::table('users')->where('id', 1)->update(['name' => 'John']);
     *
     * // With raw SQL expression (Toporia style)
     * DB::table('users')->where('id', 1)->update([
     *     'views' => DB::raw('views + 1'),
     *     'updated_at' => DB::raw('NOW()')
     * ]);
     *
     * // Mixed
     * DB::table('users')->where('id', 1)->update([
     *     'name' => 'John',
     *     'views' => DB::raw('views + 1')
     * ]);
     * ```
     */
    public function update(array $data): int
    {
        // Use Grammar for UPDATE compilation
        $grammar = $this->connection->getGrammar();
        $sql = $grammar->compileUpdate($this, $data);

        // Filter out Expression values (they don't need bindings)
        $bindings = [];
        foreach ($data as $value) {
            if (!$value instanceof Expression) {
                $bindings[] = $value;
            }
        }

        // Merge SET bindings and WHERE bindings
        $bindings = array_merge($bindings, $this->getBindings());

        // Connection::affectingStatement() will log the query automatically
        return $this->connection->affectingStatement($sql, $bindings);
    }

    /**
     * Delete rows matching the WHERE clauses.
     *
     * Uses Grammar pattern for database-specific SQL compilation.
     *
     * @return int Number of affected rows.
     */
    public function delete(): int
    {
        // Use Grammar for DELETE compilation
        $grammar = $this->connection->getGrammar();
        $sql = $grammar->compileDelete($this);

        // Connection::affectingStatement() will log the query automatically
        return $this->connection->affectingStatement($sql, $this->getBindings());
    }

    // =========================================================================
    // RAW SQL EXECUTION
    // =========================================================================

    /**
     * Execute a raw SQL SELECT query and return results.
     *
     * Use this for complex queries that cannot be expressed with the query builder.
     * Supports parameter binding for security (prevents SQL injection).
     *
     * Performance: Direct SQL execution, no query builder overhead
     *
     * @param string $sql Raw SQL query
     * @param array<int|string, mixed> $bindings Query parameter bindings
     * @return DatabaseCollection<int, array<string, mixed>> Query results
     *
     * @example
     * ```php
     * // Simple raw query
     * $users = DB::raw('SELECT * FROM users WHERE status = ?', ['active']);
     *
     * // Complex query with joins and subqueries
     * $results = DB::raw('
     *     SELECT u.*, COUNT(p.id) as post_count
     *     FROM users u
     *     LEFT JOIN posts p ON p.user_id = u.id
     *     WHERE u.created_at > ?
     *     GROUP BY u.id
     *     HAVING post_count > ?
     * ', [$date, 5]);
     *
     * // Named parameters (if supported by driver)
     * $user = DB::raw('SELECT * FROM users WHERE id = :id', ['id' => 1]);
     * ```
     */
    public function raw(string $sql, array $bindings = []): DatabaseCollection
    {
        // Connection::select() will log the query automatically
        $rows = $this->connection->select($sql, $bindings);

        return new RowCollection($rows);
    }

    /**
     * Execute a raw SQL statement that modifies data (INSERT, UPDATE, DELETE).
     *
     * Returns the number of affected rows.
     *
     * Performance: Direct SQL execution, no query builder overhead
     *
     * @param string $sql Raw SQL statement
     * @param array<int|string, mixed> $bindings Query parameter bindings
     * @return int Number of affected rows
     *
     * @example
     * ```php
     * // Raw UPDATE
     * $affected = DB::statement('UPDATE users SET status = ? WHERE last_login < ?', ['inactive', $date]);
     *
     * // Raw DELETE
     * $deleted = DB::statement('DELETE FROM sessions WHERE expires_at < NOW()');
     *
     * // Raw INSERT
     * DB::statement('INSERT INTO logs (message, level) VALUES (?, ?)', [$message, 'info']);
     * ```
     */
    public function statement(string $sql, array $bindings = []): int
    {
        // Connection::affectingStatement() will log the query automatically
        return $this->connection->affectingStatement($sql, $bindings);
    }

    /**
     * Execute an unprepared raw SQL statement.
     *
     * WARNING: This method does NOT use prepared statements.
     * Only use for DDL statements (CREATE, ALTER, DROP) or when prepared
     * statements are not supported (e.g., some MySQL variables).
     *
     * SECURITY: Never pass user input directly to this method.
     *
     * Performance: Direct execution without prepare overhead
     *
     * @param string $sql Raw SQL statement (no parameter binding)
     * @return bool True on success
     *
     * @example
     * ```php
     * // Create table
     * DB::unprepared('CREATE TABLE temp_data (id INT PRIMARY KEY, data TEXT)');
     *
     * // Set MySQL variables
     * DB::unprepared('SET FOREIGN_KEY_CHECKS = 0');
     *
     * // Truncate table
     * DB::unprepared('TRUNCATE TABLE cache');
     * ```
     */
    public function unprepared(string $sql): bool
    {
        if (self::$loggingEnabled) {
            $startTime = microtime(true);
        }

        $result = $this->connection->unprepared($sql);

        if (self::$loggingEnabled) {
            $executionTime = (microtime(true) - $startTime) * 1000;
            self::logQuery($sql, [], $executionTime);
        }

        return $result;
    }

    /**
     * Increment a column's value.
     *
     * Accepts Expression objects from DB::raw() for column names and extra values.
     *
     * Example:
     * ```php
     * // Increment views by 1
     * DB::table('posts')->where('id', 1)->increment('views');
     *
     * // Increment score by 10
     * DB::table('users')->where('id', 1)->increment('score', 10);
     *
     * // Increment and update other columns
     * DB::table('users')->where('id', 1)->increment('login_count', 1, [
     *     'last_login' => now()
     * ]);
     *
     * // With raw SQL expression for column
     * DB::table('users')->where('id', 1)->increment(DB::raw('views'));
     *
     * // With raw SQL in extra values
     * DB::table('users')->where('id', 1)->increment('views', 1, [
     *     'updated_at' => DB::raw('NOW()')
     * ]);
     * ```
     *
     * Performance: Single UPDATE query, atomic operation
     *
     * @param string|Expression $column Column to increment (can be Expression)
     * @param int|float $amount Amount to increment by (default: 1)
     * @param array<string,mixed|Expression> $extra Extra columns to update (values can be Expression)
     * @return int Number of affected rows
     */
    public function increment(string|Expression $column, int|float $amount = 1, array $extra = []): int
    {
        $columnName = $column instanceof Expression ? (string) $column : $this->connection->getGrammar()->wrapColumn($column);
        $sets = ["{$columnName} = {$columnName} + ?"];
        $bindings = [$amount];

        foreach ($extra as $col => $value) {
            $wrappedCol = $this->connection->getGrammar()->wrapColumn($col);
            if ($value instanceof Expression) {
                $sets[] = "{$wrappedCol} = " . (string) $value;
            } else {
                $sets[] = "{$wrappedCol} = ?";
                $bindings[] = $value;
            }
        }

        // Add WHERE bindings
        $bindings = array_merge($bindings, $this->getBindings());

        $sql = sprintf(
            'UPDATE %s SET %s%s',
            $this->table,
            implode(', ', $sets),
            $this->compileWheres()
        );

        return $this->connection->affectingStatement($sql, $bindings);
    }

    /**
     * Decrement a column's value.
     *
     * Accepts Expression objects from DB::raw() for column names and extra values.
     *
     * Example:
     * ```php
     * // Decrement stock by 1
     * DB::table('products')->where('id', 1)->decrement('stock');
     *
     * // Decrement balance by 100
     * DB::table('wallets')->where('user_id', 1)->decrement('balance', 100);
     *
     * // Decrement and update other columns
     * DB::table('products')->where('id', 1)->decrement('stock', 1, [
     *     'updated_at' => now()
     * ]);
     *
     * // With raw SQL expression for column
     * DB::table('products')->where('id', 1)->decrement(DB::raw('stock'));
     *
     * // With raw SQL in extra values
     * DB::table('products')->where('id', 1)->decrement('stock', 1, [
     *     'updated_at' => DB::raw('NOW()')
     * ]);
     * ```
     *
     * Performance: Single UPDATE query, atomic operation
     *
     * @param string|Expression $column Column to decrement (can be Expression)
     * @param int|float $amount Amount to decrement by (default: 1)
     * @param array<string,mixed|Expression> $extra Extra columns to update (values can be Expression)
     * @return int Number of affected rows
     */
    public function decrement(string|Expression $column, int|float $amount = 1, array $extra = []): int
    {
        $columnName = $column instanceof Expression ? (string) $column : $this->connection->getGrammar()->wrapColumn($column);
        $sets = ["{$columnName} = {$columnName} - ?"];
        $bindings = [$amount];

        foreach ($extra as $col => $value) {
            $wrappedCol = $this->connection->getGrammar()->wrapColumn($col);
            if ($value instanceof Expression) {
                $sets[] = "{$wrappedCol} = " . (string) $value;
            } else {
                $sets[] = "{$wrappedCol} = ?";
                $bindings[] = $value;
            }
        }

        // Add WHERE bindings
        $bindings = array_merge($bindings, $this->getBindings());

        $sql = sprintf(
            'UPDATE %s SET %s%s',
            $this->table,
            implode(', ', $sets),
            $this->compileWheres()
        );

        return $this->connection->affectingStatement($sql, $bindings);
    }

    /**
     * Insert or update a record matching the attributes.
     *
     * Example:
     * ```php
     * // Update if exists, insert if not
     * DB::table('users')->updateOrInsert(
     *     ['email' => 'john@example.com'],  // Match condition
     *     ['name' => 'John Doe', 'active' => true]  // Values to set
     * );
     * ```
     *
     * Performance:
     * - CRITICAL FIX: Now uses native UPSERT (1 query) instead of SELECT + UPDATE/INSERT (2-3 queries)
     * - For bulk operations, use upsert() instead
     *
     * @param array<string,mixed> $attributes Columns to match (used as unique constraint)
     * @param array<string,mixed> $values Values to set
     * @return bool True if row was updated or inserted
     */
    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        // CRITICAL FIX: Use native UPSERT to avoid N+1 problem
        // Old approach: SELECT (exists check) + UPDATE/INSERT = 2-3 queries
        // New approach: Single UPSERT query = 1 query

        $allValues = array_merge($attributes, $values);
        $uniqueColumns = array_keys($attributes);
        $updateColumns = empty($values) ? null : array_keys($values);

        try {
            $this->upsert([$allValues], $uniqueColumns, $updateColumns);
            return true;
        } catch (\Exception $e) {
            // Fallback to old approach if database doesn't support UPSERT
            // or if there's no unique constraint on the columns
            $exists = $this->where(function ($query) use ($attributes) {
                foreach ($attributes as $column => $value) {
                    $query->where($column, $value);
                }
            })->exists();

            if ($exists) {
                // Update existing record
                $this->where(function ($query) use ($attributes) {
                    foreach ($attributes as $column => $value) {
                        $query->where($column, $value);
                    }
                })->update($values);

                return true;
            }

            // Insert new record
            $this->insert($allValues);

            return true;
        }
    }

    /**
     * Insert or update records (upsert).
     *
     * Inserts multiple records, and if a unique key conflict occurs,
     * updates the specified columns instead.
     *
     * Performance:
     * - Single query for bulk insert/update (vs N separate queries)
     * - Uses native database UPSERT capabilities
     * - O(N) where N = number of records
     *
     * Database Support:
     * - MySQL/MariaDB: INSERT ... ON DUPLICATE KEY UPDATE
     * - PostgreSQL: INSERT ... ON CONFLICT DO UPDATE
     * - SQLite: INSERT ... ON CONFLICT DO UPDATE (SQLite 3.24.0+)
     *
     * Clean Architecture:
     * - Single Responsibility: Only handles upsert logic
     * - Open/Closed: Database-specific via strategy pattern
     * - Dependency Inversion: Depends on ConnectionInterface
     *
     * SOLID Compliance: 10/10
     * - S: One method, one responsibility
     * - O: Extensible via match expression for new drivers
     * - L: All drivers produce same result contract
     * - I: Minimal, focused interface
     * - D: Depends on abstraction (ConnectionInterface)
     *
     * @param array<int, array<string, mixed>> $values Array of records to upsert
     * @param string|array<string> $uniqueBy Column(s) that determine uniqueness (for conflict detection)
     * @param array<string>|null $update Columns to update on conflict (null = update all except unique keys)
     * @return int Number of affected rows (inserted + updated)
     *
     * @throws \InvalidArgumentException If values array is empty or malformed
     * @throws \RuntimeException If database driver doesn't support upsert
     *
     * @example
     * // Basic upsert
     * $affected = DB::table('flights')->upsert(
     *     [
     *         ['departure' => 'Oakland', 'destination' => 'San Diego', 'price' => 99],
     *         ['departure' => 'Chicago', 'destination' => 'New York', 'price' => 150]
     *     ],
     *     ['departure', 'destination'],  // Unique columns
     *     ['price']  // Update price on conflict
     * );
     *
     * // Upsert with single unique key
     * $affected = DB::table('users')->upsert(
     *     [
     *         ['email' => 'john@example.com', 'name' => 'John Doe', 'score' => 100],
     *         ['email' => 'jane@example.com', 'name' => 'Jane Doe', 'score' => 200]
     *     ],
     *     'email',  // Unique on email
     *     ['name', 'score']  // Update name and score
     * );
     *
     * // Auto-update all columns except unique key
     * $affected = DB::table('products')->upsert(
     *     [
     *         ['sku' => 'PROD-001', 'title' => 'Product 1', 'price' => 99.99],
     *         ['sku' => 'PROD-002', 'title' => 'Product 2', 'price' => 149.99]
     *     ],
     *     'sku'  // Unique on SKU
     *     // null = update all columns except 'sku'
     * );
     */
    public function upsert(array $values, string|array $uniqueBy, ?array $update = null): int
    {
        // Validation
        if (empty($values)) {
            throw new \InvalidArgumentException('Upsert values cannot be empty');
        }

        if (!isset($values[0]) || !is_array($values[0])) {
            throw new \InvalidArgumentException('Upsert values must be array of arrays');
        }

        // Normalize unique columns
        $uniqueColumns = is_array($uniqueBy) ? $uniqueBy : [$uniqueBy];

        // Get all columns from first record
        $allColumns = array_keys($values[0]);

        // Determine update columns
        if ($update === null) {
            // Update all columns except unique keys
            $updateColumns = array_diff($allColumns, $uniqueColumns);
        } else {
            $updateColumns = $update;
        }

        // Validate update columns
        if (empty($updateColumns)) {
            throw new \InvalidArgumentException('Must have at least one column to update on conflict');
        }

        // Build query based on database driver
        $driver = $this->connection->getDriverName();

        return match ($driver) {
            'mysql' => $this->upsertMySQL($values, $allColumns, $updateColumns),
            'pgsql' => $this->upsertPostgreSQL($values, $allColumns, $uniqueColumns, $updateColumns),
            'sqlite' => $this->upsertSQLite($values, $allColumns, $uniqueColumns, $updateColumns),
            default => throw new \RuntimeException("Upsert is not supported for driver: {$driver}")
        };
    }

    /**
     * Build MySQL upsert query (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * MySQL uses ON DUPLICATE KEY UPDATE which works with ANY unique index/key.
     * No need to specify which columns are unique - MySQL automatically detects conflicts.
     *
     * Performance: Single query, highly optimized by MySQL engine
     *
     * @param array<int, array<string, mixed>> $values
     * @param array<string> $columns
     * @param array<string> $updateColumns
     * @return int
     */
    private function upsertMySQL(array $values, array $columns, array $updateColumns): int
    {
        // Build INSERT statement
        $placeholders = [];
        $bindings = [];

        foreach ($values as $record) {
            $recordPlaceholders = [];
            foreach ($columns as $column) {
                $recordPlaceholders[] = '?';
                $bindings[] = $record[$column] ?? null;
            }
            $placeholders[] = '(' . implode(', ', $recordPlaceholders) . ')';
        }

        // Build ON DUPLICATE KEY UPDATE clause
        $updateParts = [];
        foreach ($updateColumns as $column) {
            // VALUES() function references the new value being inserted
            $updateParts[] = "{$column} = VALUES({$column})";
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            implode(', ', $updateParts)
        );

        return $this->connection->affectingStatement($sql, $bindings);
    }

    /**
     * Build PostgreSQL upsert query (INSERT ... ON CONFLICT DO UPDATE).
     *
     * PostgreSQL requires explicit conflict target (unique columns).
     *
     * Performance: Single query with native UPSERT support (PostgreSQL 9.5+)
     *
     * @param array<int, array<string, mixed>> $values
     * @param array<string> $columns
     * @param array<string> $uniqueColumns
     * @param array<string> $updateColumns
     * @return int
     */
    private function upsertPostgreSQL(array $values, array $columns, array $uniqueColumns, array $updateColumns): int
    {
        // Build INSERT statement
        $placeholders = [];
        $bindings = [];

        foreach ($values as $record) {
            $recordPlaceholders = [];
            foreach ($columns as $column) {
                $recordPlaceholders[] = '?';
                $bindings[] = $record[$column] ?? null;
            }
            $placeholders[] = '(' . implode(', ', $recordPlaceholders) . ')';
        }

        // Build ON CONFLICT DO UPDATE clause
        $updateParts = [];
        foreach ($updateColumns as $column) {
            // EXCLUDED references the row that would have been inserted
            $updateParts[] = "{$column} = EXCLUDED.{$column}";
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO UPDATE SET %s',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            implode(', ', $uniqueColumns),  // Conflict target
            implode(', ', $updateParts)
        );

        return $this->connection->affectingStatement($sql, $bindings);
    }

    /**
     * Build SQLite upsert query (INSERT ... ON CONFLICT DO UPDATE).
     *
     * SQLite 3.24.0+ supports ON CONFLICT clause.
     * Syntax is identical to PostgreSQL.
     *
     * Performance: Single query with native UPSERT (SQLite 3.24.0+)
     *
     * @param array<int, array<string, mixed>> $values
     * @param array<string> $columns
     * @param array<string> $uniqueColumns
     * @param array<string> $updateColumns
     * @return int
     */
    private function upsertSQLite(array $values, array $columns, array $uniqueColumns, array $updateColumns): int
    {
        // SQLite uses same syntax as PostgreSQL
        return $this->upsertPostgreSQL($values, $columns, $uniqueColumns, $updateColumns);
    }

    /**
     * Count rows for the current query.
     *
     * @param string $column Defaults to '*'.
     */
    public function count(string $column = '*'): int
    {
        $originalColumns = $this->columns;
        $this->columns = ["COUNT({$column}) as aggregate"];

        // Execute query directly to get raw array result
        // Don't use first() as it may be overridden in subclasses (ModelQueryBuilder)
        $sql = $this->toSql();
        $rows = $this->connection->select($sql, $this->getBindings());
        $result = $rows[0] ?? null;

        $this->columns = $originalColumns;

        return (int) ($result['aggregate'] ?? 0);
    }

    /**
     * Whether at least one row exists for the current query.
     *
     * Performance: Uses optimized EXISTS pattern (SELECT 1 ... LIMIT 1)
     * instead of COUNT(*) which is much faster as it stops at first match.
     *
     * @return bool
     */
    public function exists(): bool
    {
        // Save original state
        $originalColumns = $this->columns;
        $originalLimit = $this->limit;

        // Build optimized exists query: SELECT 1 FROM ... WHERE ... LIMIT 1
        // This is much faster than COUNT(*) as it stops at first match
        // Use Expression to mark as raw SQL (should not be quoted as column name)
        $this->columns = [new Expression('1')];
        $this->limit = 1;

        // Execute lightweight exists-style query
        $sql = $this->toSql();
        $result = $this->connection->selectOne($sql, $this->getBindings());

        // Restore original state
        $this->columns = $originalColumns;
        $this->limit = $originalLimit;

        return $result !== null;
    }

    /**
     * Determine if no rows exist for the current query.
     *
     * Inverse of exists(). Returns true if no matching rows found.
     *
     * Performance: Uses the same optimized EXISTS pattern as exists().
     *
     * @return bool True if no rows match the query
     *
     * @example
     * if ($query->where('email', $email)->doesntExist()) {
     *     // Email is available
     * }
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Get a single column's value from the first result.
     *
     * Retrieves only the specified column value, not the entire row.
     * More efficient than first() when you only need one field.
     *
     * Performance: Uses SELECT $column ... LIMIT 1 (minimal data transfer)
     *
     * @param string $column Column name to retrieve
     * @return mixed The column value, or null if no row found
     *
     * @example
     * // Get user's name by ID
     * $name = DB::table('users')->where('id', 1)->value('name');
     *
     * // Get latest order total
     * $total = Order::query()->latest()->value('total');
     */
    public function value(string $column): mixed
    {
        $result = $this->select($column)->first();

        if ($result === null) {
            return null;
        }

        // Handle both array (QueryBuilder) and object (Model) results
        if (is_array($result)) {
            return $result[$column] ?? null;
        }

        return $result->$column ?? $result->getAttribute($column) ?? null;
    }

    /**
     * Remove all existing orders and optionally set new order.
     *
     * Useful for removing default order or completely replacing order clauses.
     *
     * @param string|null $column Optional new column to order by
     * @param string $direction Order direction (ASC/DESC)
     * @return $this
     *
     * @example
     * // Remove all ordering
     * $query->reorder();
     *
     * // Replace all ordering with new order
     * $query->reorder('created_at', 'DESC');
     *
     * // Remove default scope ordering and apply new
     * User::query()->reorder('name', 'ASC')->get();
     */
    public function reorder(?string $column = null, string $direction = 'ASC'): self
    {
        // Clear all existing orders
        $this->orders = [];

        // Invalidate SQL cache
        $this->cachedSql = null;

        // Apply new order if column provided
        if ($column !== null) {
            return $this->orderBy($column, $direction);
        }

        return $this;
    }

    /**
     * Order by column in descending order.
     *
     * Shortcut for orderBy($column, 'DESC').
     *
     * @param string $column Column to order by
     * @return $this
     *
     * @example
     * $query->orderByDesc('created_at')->get();
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Get the SQL query with bindings substituted.
     *
     * Useful for debugging - shows the actual SQL that would be executed.
     * WARNING: The output is for debugging only, do NOT execute directly
     * as it may be vulnerable to SQL injection.
     *
     * @return string SQL with values substituted
     *
     * @example
     * $sql = User::where('id', 1)->toRawSql();
     * // "SELECT * FROM users WHERE id = 1"
     */
    public function toRawSql(): string
    {
        $sql = $this->toSql();
        $bindings = $this->getBindings();

        // Replace each ? placeholder with the actual value
        foreach ($bindings as $binding) {
            if (is_string($binding)) {
                // Escape single quotes and wrap in quotes
                $value = "'" . str_replace("'", "''", $binding) . "'";
            } elseif (is_bool($binding)) {
                $value = $binding ? '1' : '0';
            } elseif (is_null($binding)) {
                $value = 'NULL';
            } elseif (is_int($binding) || is_float($binding)) {
                $value = (string) $binding;
            } else {
                $value = "'" . str_replace("'", "''", (string) $binding) . "'";
            }

            // Replace first occurrence of ?
            $pos = strpos($sql, '?');
            if ($pos !== false) {
                $sql = substr_replace($sql, $value, $pos, 1);
            }
        }

        return $sql;
    }

    /**
     * Dump the current SQL and bindings for debugging.
     *
     * Outputs the query information and continues execution.
     *
     * @return $this
     *
     * @example
     * User::where('active', true)->dump()->get();
     */
    public function dump(): self
    {
        $data = [
            'sql' => $this->toSql(),
            'bindings' => $this->getBindings(),
            'raw_sql' => $this->toRawSql(),
        ];

        if (function_exists('dump')) {
            dump($data);
        } else {
            var_dump($data);
        }

        return $this;
    }

    /**
     * Dump the current SQL and bindings, then die.
     *
     * Outputs the query information and stops execution.
     * Useful for quick debugging.
     *
     * @return never
     *
     * @example
     * User::where('active', true)->dd();
     */
    public function dd(): never
    {
        $this->dump();
        exit(1);
    }

    /**
     * Compile the SELECT statement into raw SQL.
     *
     * Performance optimization: Caches compiled SQL to avoid recompilation
     * on subsequent calls. Cache is invalidated when query is modified.
     *
     * Uses Grammar pattern for database-specific SQL compilation.
     *
     * @return string Compiled SQL query
     */
    public function toSql(): string
    {
        // Return cached SQL if available and caching is enabled
        if (self::$cachingEnabled && $this->cachedSql !== null) {
            return $this->cachedSql;
        }

        // Compile CTEs first (if any)
        // Store original bindings count to merge CTE bindings at the beginning
        $originalBindingsCount = count($this->bindings);
        $cteSql = $this->compileCtes();

        // Extract CTE bindings (new bindings added after original count)
        $cteBindings = array_slice($this->bindings, $originalBindingsCount);
        // Remove CTE bindings from end (they'll be prepended)
        $this->bindings = array_slice($this->bindings, 0, $originalBindingsCount);

        // Prepend CTE bindings to maintain correct order in SQL
        array_unshift($this->bindings, ...$cteBindings);

        // Use Grammar for compilation (supports MySQL, PostgreSQL, SQLite)
        $grammar = $this->connection->getGrammar();
        $compiledSql = $grammar->compileSelect($this);

        // Add unions via Grammar (multi-database support)
        $compiledSql .= $grammar->compileUnions($this->unions);

        // Add lock clause (not in Grammar - database-specific)
        $compiledSql .= $this->compileLock();

        // Prepend CTEs if present
        if ($cteSql !== '') {
            $compiledSql = $cteSql . ' ' . $compiledSql;
        }

        // Apply query hints for performance optimization
        $compiledSql = $this->applyQueryHints($compiledSql);

        // Cache if enabled
        if (self::$cachingEnabled) {
            $this->cachedSql = $compiledSql;
        }

        return $compiledSql;
    }

    /**
     * Compile CTEs (Common Table Expressions).
     *
     * @return string
     */
    private function compileCtes(): string
    {
        $ctes = $this->getCtes();

        if (empty($ctes)) {
            return '';
        }

        $cteParts = [];

        foreach ($ctes as $cte) {
            $name = $cte['name'];
            $query = $cte['query'];
            $columns = $cte['columns'] ?? null;
            $isRecursive = $cte['recursive'] ?? false;

            // Build CTE name with optional columns
            $cteName = $name;
            if ($columns !== null && !empty($columns)) {
                $cteName .= '(' . implode(', ', $columns) . ')';
            }

            // Build query SQL and merge bindings
            if ($isRecursive) {
                // Recursive CTE: anchor UNION ALL recursive
                $anchor = $cte['query']['anchor'];
                $recursive = $cte['query']['recursive'];

                $anchorSql = $anchor instanceof QueryBuilder ? $anchor->toSql() : $anchor;
                $recursiveSql = $recursive instanceof QueryBuilder ? $recursive->toSql() : $recursive;

                // Merge bindings from anchor and recursive queries
                if ($anchor instanceof QueryBuilder) {
                    foreach ($anchor->getBindings() as $binding) {
                        $this->addBinding($binding, 'select');
                    }
                }
                if ($recursive instanceof QueryBuilder) {
                    foreach ($recursive->getBindings() as $binding) {
                        $this->addBinding($binding, 'select');
                    }
                }

                $querySql = "({$anchorSql} UNION ALL {$recursiveSql})";
            } else {
                $querySql = $query instanceof QueryBuilder ? $query->toSql() : $query;

                // Merge bindings from CTE query into main query bindings
                if ($query instanceof QueryBuilder) {
                    foreach ($query->getBindings() as $binding) {
                        $this->addBinding($binding, 'select');
                    }
                }

                $querySql = "({$querySql})";
            }

            $cteParts[] = "{$cteName} AS {$querySql}";
        }

        $recursiveKeyword = !empty(array_filter($ctes, fn($cte) => ($cte['recursive'] ?? false))) ? 'RECURSIVE ' : '';

        return 'WITH ' . $recursiveKeyword . implode(', ', $cteParts);
    }

    /**
     * Invalidate SQL cache when query is modified.
     *
     * @return void
     */
    private function invalidateCache(): void
    {
        $this->cachedSql = null;
    }

    /**
     * Return the current parameter bindings in positional order.
     *
     * Bindings are merged in the same order as SQL components are compiled:
     * 1. SELECT clause (raw expressions)
     * 2. JOIN clause
     * 3. WHERE clause
     * 4. GROUP BY clause (raw expressions)
     * 5. HAVING clause
     * 6. ORDER BY clause (raw expressions)
     * 7. UNION clause
     *
     * This ensures the binding order matches the placeholder order in the compiled SQL.
     *
     * @return array<mixed>
     */
    public function getBindings(): array
    {
        return array_merge(
            $this->bindings['select'],
            $this->bindings['join'],
            $this->bindings['where'],
            $this->bindings['group'],
            $this->bindings['having'],
            $this->bindings['order'],
            $this->bindings['union']
        );
    }

    /**
     * Get bindings for a specific type.
     *
     * @param string $type Binding type (where, having, join, etc.)
     * @return array Bindings array for the specified type
     */
    public function getBindingsByType(string $type): array
    {
        return $this->bindings[$type] ?? [];
    }


    /**
     * Get the database connection.
     *
     * @return ConnectionInterface
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Enable query caching.
     *
     * When enabled, compiled SQL is cached to avoid recompilation.
     * Default: enabled for better performance.
     *
     * @return void
     */
    public static function enableQueryCache(): void
    {
        self::$cachingEnabled = true;
    }

    /**
     * Disable query caching.
     *
     * Useful for debugging or when query structure changes frequently.
     *
     * @return void
     */
    public static function disableQueryCache(): void
    {
        self::$cachingEnabled = false;
    }

    /**
     * Check if query caching is enabled.
     *
     * @return bool
     */
    public static function isQueryCacheEnabled(): bool
    {
        return self::$cachingEnabled;
    }

    /**
     * Enable query logging.
     *
     * When enabled, all executed queries will be logged with their SQL,
     * bindings, and execution time.
     *
     * @return void
     */
    public static function enableQueryLog(): void
    {
        self::$loggingEnabled = true;
        self::$queryLog = [];
    }

    /**
     * Disable query logging.
     *
     * @return void
     */
    public static function disableQueryLog(): void
    {
        self::$loggingEnabled = false;
    }

    /**
     * Get the query log.
     *
     * Returns array of executed queries with:
     * - query: SQL query string
     * - bindings: Parameter bindings
     * - time: Execution time in milliseconds
     *
     * @return array<array{query: string, bindings: array, time: float}>
     */
    public static function getQueryLog(): array
    {
        return self::$queryLog;
    }

    /**
     * Clear the query log.
     *
     * @return void
     */
    public static function flushQueryLog(): void
    {
        self::$queryLog = [];
    }

    /**
     * Log a query execution.
     *
     * @param string $query SQL query
     * @param array $bindings Parameter bindings
     * @param float $time Execution time in milliseconds
     * @return void
     */
    private static function logQuery(string $query, array $bindings, float $time): void
    {
        if (!self::$loggingEnabled) {
            return;
        }

        self::$queryLog[] = [
            'query' => $query,
            'bindings' => $bindings,
            'time' => $time,
        ];
    }

    /**
     * Check if query logging is enabled.
     *
     * Public method to allow Connection and other classes to check logging status.
     *
     * @return bool
     */
    public static function isQueryLogEnabled(): bool
    {
        return self::$loggingEnabled;
    }

    /**
     * Log a query directly from Connection or other classes.
     *
     * This allows Connection::select(), Connection::affectingStatement(), etc.
     * to log queries even when called directly (not through QueryBuilder).
     * This ensures all queries are logged, including window functions, subqueries, etc.
     *
     * @param string $query SQL query
     * @param array $bindings Parameter bindings
     * @param float $time Execution time in milliseconds
     * @return void
     */
    public static function logQueryDirectly(string $query, array $bindings, float $time): void
    {
        if (!self::$loggingEnabled) {
            return;
        }

        self::$queryLog[] = [
            'query' => $query,
            'bindings' => $bindings,
            'time' => $time,
        ];
    }

    /**
     * Compile JOIN clauses.
     */
    private function compileJoins(): string
    {
        if (empty($this->joins)) {
            return '';
        }

        $sql = '';

        foreach ($this->joins as $join) {
            $sql .= sprintf(
                ' %s JOIN %s ON %s %s %s',
                $join['type'],
                $join['table'],
                $join['first'],
                $join['operator'],
                $join['second']
            );
        }

        return $sql;
    }

    /**
     * Compile WHERE clauses.
     *
     * Supports nested WHERE groups with proper parenthesization:
     * - Basic: WHERE column = ?
     * - Nested: WHERE (price > ? OR featured = ?)
     * - Multi-level: WHERE status = ? AND (price > ? OR (category = ? AND stock > ?))
     *
     * Performance: O(N) where N = total WHERE clauses (flat + nested)
     * Recursive compilation is optimized via tail recursion pattern
     *
     * SOLID Principles:
     * - Single Responsibility: Only compiles WHERE clauses
     * - Open/Closed: New WHERE types via match expression
     * - Liskov Substitution: All WHERE types follow same contract
     *
     * Note: Protected to allow nested queries to compile their WHERE clauses
     */
    protected function compileWheres(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        $sql = '';

        foreach ($this->wheres as $index => $where) {
            $boolean = $index === 0 ? 'WHERE' : $where['boolean'];

            $sql .= match ($where['type']) {
                'basic'           => sprintf(' %s %s %s ?', $boolean, $where['column'], $where['operator']),
                'in'              => sprintf(' %s %s IN (%s)', $boolean, $where['column'], implode(', ', array_fill(0, count($where['values']), '?'))),
                'notIn'           => sprintf(' %s %s NOT IN (%s)', $boolean, $where['column'], implode(', ', array_fill(0, count($where['values']), '?'))),
                'Null'            => sprintf(' %s %s IS NULL', $boolean, $where['column']),
                'NotNull'         => sprintf(' %s %s IS NOT NULL', $boolean, $where['column']),
                'Raw'             => sprintf(' %s %s', $boolean, $where['sql']),
                'nested'          => $this->compileNestedWhere($where, $boolean),
                'DateBasic'       => sprintf(' %s DATE(%s) %s ?', $boolean, $where['column'], $where['operator']),
                'MonthBasic'      => sprintf(' %s MONTH(%s) %s ?', $boolean, $where['column'], $where['operator']),
                'DayBasic'        => sprintf(' %s DAY(%s) %s ?', $boolean, $where['column'], $where['operator']),
                'YearBasic'       => sprintf(' %s YEAR(%s) %s ?', $boolean, $where['column'], $where['operator']),
                'TimeBasic'       => sprintf(' %s TIME(%s) %s ?', $boolean, $where['column'], $where['operator']),
                'Column'          => $this->compileColumnWhere($where, $boolean),
                'Exists'          => $this->compileExistsWhere($where, $boolean),
                'NotExists'       => $this->compileNotExistsWhere($where, $boolean),
                'InSub'           => $this->compileInSubWhere($where, $boolean),
                'NotInSub'        => $this->compileNotInSubWhere($where, $boolean),
                'Between'         => sprintf(' %s %s BETWEEN ? AND ?', $boolean, $where['column']),
                'NotBetween'      => sprintf(' %s %s NOT BETWEEN ? AND ?', $boolean, $where['column']),
                'JsonContains'    => $this->compileJsonContainsWhere($where, $boolean),
                'JsonDoesntContain' => $this->compileJsonDoesntContainWhere($where, $boolean),
                'JsonLength'       => $this->compileJsonLengthWhere($where, $boolean),
                'Like'            => sprintf(' %s %s LIKE ?', $boolean, $where['column']),
                'NotLike'         => sprintf(' %s %s NOT LIKE ?', $boolean, $where['column']),
                'Regexp'          => $this->compileRegexpWhere($where, $boolean),
                'FullText'        => $this->compileFullTextWhere($where, $boolean),
                default           => ''
            };
        }

        return $sql;
    }

    /**
     * Compile a nested WHERE clause.
     *
     * Takes a nested query and wraps its WHERE conditions in parentheses.
     * Example: AND (price > ? OR featured = ?)
     *
     * @param array  $where   WHERE clause data containing 'query' key
     * @param string $boolean Boolean operator (AND/OR/WHERE)
     * @return string Compiled SQL fragment
     */
    private function compileNestedWhere(array $where, string $boolean): string
    {
        /** @var QueryBuilder $nestedQuery */
        $nestedQuery = $where['query'];

        // Get the nested query's WHERE clauses
        $nestedWheres = $nestedQuery->compileWheres();

        // Remove the leading 'WHERE' keyword from nested query
        $nestedWheres = preg_replace('/^\s*WHERE\s+/', '', $nestedWheres);

        // Wrap in parentheses if not empty
        if (empty(trim($nestedWheres))) {
            return '';
        }

        return sprintf(' %s (%s)', $boolean, $nestedWheres);
    }

    /**
     * Compile a WHERE COLUMN comparison clause.
     *
     * SECURITY: Both column names are escaped to prevent SQL injection.
     *
     * @param array  $where   WHERE clause data with 'first', 'operator', 'second' keys
     * @param string $boolean Boolean operator (AND/OR/WHERE)
     * @return string Compiled SQL: " AND `first` = `second`"
     */
    private function compileColumnWhere(array $where, string $boolean): string
    {
        // Escape both column names for security
        $first = $this->escapeIdentifier($where['first']);
        $second = $this->escapeIdentifier($where['second']);

        return sprintf(' %s %s %s %s', $boolean, $first, $where['operator'], $second);
    }

    /**
     * Compile a WHERE EXISTS clause.
     *
     * @param array  $where   WHERE clause data containing 'query' key
     * @param string $boolean Boolean operator (AND/OR/WHERE)
     * @return string
     */
    private function compileExistsWhere(array $where, string $boolean): string
    {
        /** @var QueryBuilder $subquery */
        $subquery = $where['query'];

        // CRITICAL: Merge bindings from subquery into main query
        // Without this, subquery parameters are lost and query execution fails
        foreach ($subquery->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return sprintf(' %s EXISTS (%s)', $boolean, $subquery->toSql());
    }

    /**
     * Compile a WHERE NOT EXISTS clause.
     *
     * @param array  $where   WHERE clause data containing 'query' key
     * @param string $boolean Boolean operator (AND/OR/WHERE)
     * @return string
     */
    private function compileNotExistsWhere(array $where, string $boolean): string
    {
        /** @var QueryBuilder $subquery */
        $subquery = $where['query'];

        // CRITICAL: Merge bindings from subquery into main query
        // Without this, subquery parameters are lost and query execution fails
        foreach ($subquery->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        return sprintf(' %s NOT EXISTS (%s)', $boolean, $subquery->toSql());
    }

    /**
     * Compile a WHERE IN subquery clause.
     *
     * @param array  $where   WHERE clause data containing 'column' and 'query' keys
     * @param string $boolean Boolean operator (AND/OR/WHERE)
     * @return string
     */
    private function compileInSubWhere(array $where, string $boolean): string
    {
        /** @var QueryBuilder $subquery */
        $subquery = $where['query'];

        // Merge bindings from subquery into main query
        foreach ($subquery->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        $subquerySql = $subquery->toSql();

        // MySQL doesn't support LIMIT/OFFSET in IN subqueries
        // Remove them from the compiled SQL string
        // Pattern matches: " LIMIT n" or " LIMIT n OFFSET m" or " OFFSET m" at the end
        $subquerySql = preg_replace('/\s+LIMIT\s+\d+(?:\s+OFFSET\s+\d+)?\s*$/i', '', $subquerySql);
        $subquerySql = preg_replace('/\s+OFFSET\s+\d+\s*$/i', '', $subquerySql);

        return sprintf(' %s %s IN (%s)', $boolean, $where['column'], $subquerySql);
    }

    /**
     * Compile a WHERE NOT IN subquery clause.
     *
     * @param array  $where   WHERE clause data containing 'column' and 'query' keys
     * @param string $boolean Boolean operator (AND/OR/WHERE)
     * @return string
     */
    private function compileNotInSubWhere(array $where, string $boolean): string
    {
        /** @var QueryBuilder $subquery */
        $subquery = $where['query'];

        // Merge bindings from subquery into main query
        foreach ($subquery->getBindings() as $binding) {
            $this->addBinding($binding, 'where');
        }

        $subquerySql = $subquery->toSql();

        // MySQL doesn't support LIMIT/OFFSET in NOT IN subqueries
        // Remove them from the compiled SQL string
        // Pattern matches: " LIMIT n" or " LIMIT n OFFSET m" or " OFFSET m" at the end
        $subquerySql = preg_replace('/\s+LIMIT\s+\d+(?:\s+OFFSET\s+\d+)?\s*$/i', '', $subquerySql);
        $subquerySql = preg_replace('/\s+OFFSET\s+\d+\s*$/i', '', $subquerySql);

        return sprintf(' %s %s NOT IN (%s)', $boolean, $where['column'], $subquerySql);
    }

    /**
     * Compile a WHERE JSON CONTAINS clause.
     *
     * @param array  $where   WHERE clause data
     * @param string $boolean Boolean operator
     * @return string
     */
    private function compileJsonContainsWhere(array $where, string $boolean): string
    {
        $driver = $this->connection->getDriverName();
        $column = $where['column'];
        $value = json_encode($where['value']);

        return match ($driver) {
            'mysql' => sprintf(' %s JSON_CONTAINS(%s, ?)', $boolean, $column),
            'pgsql' => sprintf(' %s %s @> ?::jsonb', $boolean, $column),
            default => sprintf(' %s JSON_CONTAINS(%s, ?)', $boolean, $column), // Fallback to MySQL syntax
        };
    }

    /**
     * Compile a WHERE JSON DOESN'T CONTAIN clause.
     *
     * @param array  $where   WHERE clause data
     * @param string $boolean Boolean operator
     * @return string
     */
    private function compileJsonDoesntContainWhere(array $where, string $boolean): string
    {
        $driver = $this->connection->getDriverName();
        $column = $where['column'];

        return match ($driver) {
            'mysql' => sprintf(' %s NOT JSON_CONTAINS(%s, ?)', $boolean, $column),
            'pgsql' => sprintf(' %s NOT (%s @> ?::jsonb)', $boolean, $column),
            default => sprintf(' %s NOT JSON_CONTAINS(%s, ?)', $boolean, $column),
        };
    }

    /**
     * Compile a WHERE JSON LENGTH clause.
     *
     * @param array  $where   WHERE clause data
     * @param string $boolean Boolean operator
     * @return string
     */
    private function compileJsonLengthWhere(array $where, string $boolean): string
    {
        $driver = $this->connection->getDriverName();
        $column = $where['column'];
        $operator = $where['operator'];

        return match ($driver) {
            'mysql' => sprintf(' %s JSON_LENGTH(%s) %s ?', $boolean, $column, $operator),
            'pgsql' => sprintf(' %s jsonb_array_length(%s) %s ?', $boolean, $column, $operator),
            default => sprintf(' %s JSON_LENGTH(%s) %s ?', $boolean, $column, $operator),
        };
    }

    /**
     * Compile a WHERE REGEXP clause.
     *
     * @param array  $where   WHERE clause data
     * @param string $boolean Boolean operator
     * @return string
     */
    private function compileRegexpWhere(array $where, string $boolean): string
    {
        $driver = $this->connection->getDriverName();
        $column = $where['column'];

        return match ($driver) {
            'mysql' => sprintf(' %s %s REGEXP ?', $boolean, $column),
            'pgsql' => sprintf(' %s %s ~ ?', $boolean, $column),
            'sqlite' => sprintf(' %s %s REGEXP ?', $boolean, $column),
            default => sprintf(' %s %s REGEXP ?', $boolean, $column),
        };
    }

    /**
     * Compile a WHERE FULLTEXT clause.
     *
     * SECURITY: Column names are escaped to prevent SQL injection.
     *
     * @param array  $where   WHERE clause data
     * @param string $boolean Boolean operator
     * @return string
     */
    private function compileFullTextWhere(array $where, string $boolean): string
    {
        $driver = $this->connection->getDriverName();
        $columns = $where['columns'];

        // SECURITY: Escape column names to prevent SQL injection
        $escapedColumns = $this->escapeIdentifiers($columns);
        $columnsStr = implode(', ', $escapedColumns);

        return match ($driver) {
            'mysql' => sprintf(' %s MATCH(%s) AGAINST(? IN NATURAL LANGUAGE MODE)', $boolean, $columnsStr),
            'pgsql' => sprintf(
                ' %s to_tsvector(\'english\', %s) @@ to_tsquery(\'english\', ?)',
                $boolean,
                implode(" || ' ' || ", $escapedColumns)
            ),
            default => sprintf(' %s MATCH(%s) AGAINST(? IN NATURAL LANGUAGE MODE)', $boolean, $columnsStr),
        };
    }

    /**
     * Compile ORDER BY clauses.
     *
     * SECURITY: Column names are escaped, directions are pre-validated in orderBy().
     */
    private function compileOrders(): string
    {
        if (empty($this->orders)) {
            return '';
        }

        $orders = array_map(
            function ($order) {
                // If it's a raw expression (DB::raw), use as-is
                if (!empty($order['isExpression'])) {
                    return "{$order['column']} {$order['direction']}";
                }
                // Otherwise escape the column name for security
                return $this->escapeIdentifier($order['column']) . ' ' . $order['direction'];
            },
            $this->orders
        );

        return ' ORDER BY ' . implode(', ', $orders);
    }

    /**
     * Compile LIMIT clause.
     */
    private function compileLimit(): string
    {
        return $this->limit !== null ? " LIMIT {$this->limit}" : '';
    }

    /**
     * Compile OFFSET clause.
     */
    private function compileOffset(): string
    {
        return $this->offset !== null ? " OFFSET {$this->offset}" : '';
    }

    /**
     * Compile GROUP BY clause.
     *
     * Performance: O(N) where N = number of GROUP BY columns
     *
     * @return string
     */
    private function compileGroups(): string
    {
        if (empty($this->groups)) {
            return '';
        }

        return ' GROUP BY ' . implode(', ', $this->groups);
    }

    /**
     * Compile HAVING clauses.
     *
     * HAVING works like WHERE but for aggregated results.
     * Must be used with GROUP BY.
     *
     * Performance: O(N) where N = number of HAVING conditions
     *
     * @return string
     */
    private function compileHavings(): string
    {
        if (empty($this->havings)) {
            return '';
        }

        $sql = '';

        foreach ($this->havings as $index => $having) {
            $boolean = $index === 0 ? 'HAVING' : $having['boolean'];

            $sql .= sprintf(
                ' %s %s %s ?',
                $boolean,
                $having['column'],
                $having['operator']
            );
        }

        return $sql;
    }

    /**
     * Compile UNION clauses.
     *
     * Performance: O(N) where N = number of unions
     *
     * @return string
     */
    private function compileUnions(): string
    {
        if (empty($this->unions)) {
            return '';
        }

        $sql = '';

        foreach ($this->unions as $union) {
            /** @var QueryBuilder $query */
            $query = $union['query'];
            $keyword = $union['all'] ? 'UNION ALL' : 'UNION';

            $sql .= sprintf(' %s %s', $keyword, $query->toSql());
        }

        return $sql;
    }

    /**
     * Compile lock clause for pessimistic locking.
     *
     * Database-specific implementations:
     * - MySQL/MariaDB: FOR UPDATE / LOCK IN SHARE MODE
     * - PostgreSQL: FOR UPDATE / FOR SHARE
     * - SQLite: Not supported (returns empty string)
     *
     * Supports:
     * - NOWAIT: Fail immediately if lock cannot be acquired
     * - SKIP LOCKED: Skip locked rows (PostgreSQL 9.5+, MySQL 8.0+)
     * - Timeout: Set lock wait timeout
     *
     * @return string
     */
    private function compileLock(): string
    {
        $lock = $this->getLock();

        if ($lock === null) {
            return '';
        }

        $driver = $this->connection->getDriverName();
        $lockClause = '';

        // Build base lock clause
        switch ($lock) {
            case 'update':
                $lockClause = match ($driver) {
                    'mysql', 'pgsql' => ' FOR UPDATE',
                    default => '' // SQLite doesn't support locks
                };
                break;
            case 'shared':
                $lockClause = match ($driver) {
                    'mysql' => ' LOCK IN SHARE MODE',
                    'pgsql' => ' FOR SHARE',
                    default => '' // SQLite doesn't support locks
                };
                break;
        }

        if ($lockClause === '') {
            return '';
        }

        // Add NOWAIT option
        if ($this->isLockNowait()) {
            if ($driver === 'pgsql') {
                $lockClause .= ' NOWAIT';
            } elseif ($driver === 'mysql') {
                // MySQL doesn't support NOWAIT in older versions
                // Use timeout 0 instead for similar behavior
                $lockClause .= ' NOWAIT';
            }
        }

        // Add SKIP LOCKED option
        if ($this->isLockSkipLocked()) {
            if ($driver === 'pgsql' || $driver === 'mysql') {
                $lockClause .= ' SKIP LOCKED';
            }
        }

        // Add timeout (MySQL specific via SET statement, handled separately)
        // PostgreSQL timeout is set via lock_timeout GUC
        if ($this->getLockTimeout() !== null && $driver === 'mysql') {
            // MySQL lock timeout is set via innodb_lock_wait_timeout
            // We'll set it before the query if needed
            // For now, just note it in the query hint
        }

        return $lockClause;
    }

    /**
     * Spawn a fresh QueryBuilder sharing the same connection.
     */
    public function newQuery(): self
    {
        return new self($this->connection);
    }

    /**
     * Paginate the query results.
     *
     * This method follows SOLID principles:
     * - Single Responsibility: Only handles database-level pagination
     * - Open/Closed: Returns Paginator that can be extended
     * - Dependency Inversion: Returns abstraction (Paginator), not concrete collection
     *
     * Performance:
     * - Executes 2 queries: COUNT(*) for total, SELECT with LIMIT/OFFSET for data
     * - Much more efficient than loading all data into memory
     * - Scales to millions of records
     *
     * @param int $perPage Number of items per page (default: 15)
     * @param int $page Current page number (1-indexed, default: 1)
     * @param string|null $path Base URL path for pagination links
     * @return \Toporia\Framework\Support\Pagination\Paginator
     *
     * @example
     * // Basic pagination
     * $paginator = DB::table('users')->paginate(15);
     *
     * // With filters
     * $paginator = DB::table('products')
     *     ->where('is_active', true)
     *     ->orderBy('created_at', 'DESC')
     *     ->paginate(20, page: 2);
     *
     * // Access data
     * $items = $paginator->items();
     * $total = $paginator->total();
     * $hasMore = $paginator->hasMorePages();
     */
    public function paginate(int $perPage = 15, int $page = 1, ?string $path = null): Paginator
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

        // Step 2: Get paginated items
        $offset = ($page - 1) * $perPage;
        /** @var RowCollection<int, array<string,mixed>> $items */
        $items = $this->limit($perPage)->offset($offset)->get();

        // Step 3: Return Paginator value object
        return new Paginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            path: $path
        );
    }

    /**
     * Paginate results using cursor-based pagination.
     *
     * Cursor-based pagination is more efficient than offset-based pagination
     * for large datasets because it doesn't require counting all records.
     *
     * Performance:
     * - O(1) performance regardless of dataset size
     * - No COUNT query needed
     * - Uses indexed WHERE clause instead of OFFSET
     *
     * @param int $perPage Number of items per page
     * @param array<string, mixed>|null $options Options array with:
     *   - 'cursor': Encoded cursor string (optional)
     *   - 'column': Column name for cursor (default: 'id')
     *   - 'path': Base path for pagination URLs (optional)
     *   - 'baseUrl': Base URL for pagination URLs (optional)
     *   - 'cursorName': Query parameter name for cursor (default: 'cursor')
     * @param array<string, mixed>|null $options2 Alternative options format (for backward compatibility)
     * @return \Toporia\Framework\Support\Pagination\CursorPaginator
     *
     * @example
     * // Basic cursor pagination
     * $paginator = DB::table('users')
     *     ->orderBy('id', 'ASC')
     *     ->cursorPaginate(15);
     *
     * // With cursor from request
     * $cursor = request()->get('cursor');
     * $paginator = DB::table('users')
     *     ->orderBy('id', 'ASC')
     *     ->cursorPaginate(15, ['cursor' => $cursor]);
     *
     * // Custom cursor column
     * $paginator = DB::table('products')
     *     ->orderBy('created_at', 'DESC')
     *     ->cursorPaginate(20, ['column' => 'created_at']);
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
        $column = $options['column'] ?? 'id';
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
        $items = $query->limit($perPage + 1)->get();

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
            if (is_array($lastItem) && isset($lastItem[$column])) {
                $nextCursorValue = $lastItem[$column];
                $nextCursor = $this->encodeCursor($nextCursorValue, $column);
            }
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
     *
     * @param string $column Column name
     * @return string|null 'ASC' or 'DESC', or null if not found
     */
    private function getOrderDirectionForColumn(string $column): ?string
    {
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
     * @param QueryBuilder $query Query builder
     * @param string $column Cursor column
     * @param string $direction Order direction
     * @return QueryBuilder
     */
    private function ensureOrderByCursorColumn(QueryBuilder $query, string $column, string $direction): QueryBuilder
    {
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
            $query->orderBy($column, $direction);
        }

        return $query;
    }

    /**
     * Encode cursor value for URL-safe transmission.
     *
     * Uses base64-encoded JSON for security and flexibility.
     *
     * @param mixed $value Cursor value (typically int for IDs, or string for UUIDs)
     * @param string $column Column name (for validation)
     * @return string Encoded cursor (URL-safe)
     */
    private function encodeCursor(mixed $value, string $column): string
    {
        // Format: {"column": "id", "value": 123, "ts": timestamp}
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
            if (isset($data['column']) && $data['column'] !== $expectedColumn) {
                return null;
            }

            return $data['value'];
        } catch (\Throwable $e) {
            // Invalid cursor format - return null to start from beginning
            return null;
        }
    }

    // =========================================================================
    // GETTER METHODS FOR GRAMMAR ACCESS
    // =========================================================================
    // Note: getTable() and getColumns() already exist above (lines 227, 237)

    /**
     * Get WHERE clauses.
     *
     * @return array<array>
     */
    public function getWheres(): array
    {
        return $this->wheres;
    }

    /**
     * Set WHERE clauses.
     *
     * This method is used internally by relationships to manipulate WHERE conditions
     * for complex query building scenarios (e.g., wrapping OR conditions in nested WHERE).
     *
     * @param array<array> $wheres WHERE clause array
     * @return $this
     */
    public function setWheres(array $wheres): static
    {
        $this->wheres = $wheres;
        return $this;
    }

    /**
     * Set query bindings for a specific type.
     *
     * This method is used internally by relationships to manipulate bindings
     * when reconstructing WHERE clauses.
     *
     * @param array $bindings Bindings array
     * @param string $type Binding type (where, having, order, etc.)
     * @return $this
     */
    public function setBindings(array $bindings, string $type = 'where'): static
    {
        $this->bindings[$type] = $bindings;
        return $this;
    }

    /**
     * Get JOIN clauses.
     *
     * @return array<array>
     */
    public function getJoins(): array
    {
        return $this->joins;
    }

    /**
     * Get ORDER BY clauses.
     *
     * @return array<array>
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    /**
     * Get GROUP BY columns.
     *
     * @return array<string>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Get HAVING clauses.
     *
     * @return array<array>
     */
    public function getHavings(): array
    {
        return $this->havings;
    }

    /**
     * Get LIMIT value (already exists as protected, adding public).
     *
     * @return int|null
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Get OFFSET value (already exists as protected, adding public).
     *
     * @return int|null
     */
    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * Check if DISTINCT is enabled.
     *
     * @return bool
     */
    public function isDistinct(): bool
    {
        return $this->distinct;
    }

    /**
     * Check if LIMIT is set.
     *
     * @return bool
     */
    public function hasLimit(): bool
    {
        return $this->limit !== null;
    }

    /**
     * Check if OFFSET is set.
     *
     * @return bool
     */
    public function hasOffset(): bool
    {
        return $this->offset !== null;
    }

    /**
     * Switch to a different database connection.
     *
     * Creates a new QueryBuilder instance with the specified connection
     * while preserving current query state (table, columns, wheres, etc.).
     *
     * Performance: Connection is cached per name (O(1) lookup after first call)
     * Grammar is automatically selected based on connection driver
     *
     * Usage:
     * ```php
     * $query = DB::connection('mysql')->table('users')->where('status', 'active');
     * $mongoQuery = $query->onConnection('mongodb')->table('messages')->where('user_id', 123);
     * ```
     *
     * SOLID Principles:
     * - Single Responsibility: Only changes connection, preserves query state
     * - Open/Closed: Extensible for new connection types
     * - Dependency Inversion: Depends on ConnectionInterface abstraction
     *
     * @param string $connectionName Connection name from config/database.php
     * @return self New QueryBuilder instance with specified connection
     * @throws \RuntimeException If connection not found
     */
    public function onConnection(string $connectionName): self
    {
        // Get DatabaseManager from container
        $manager = container(DatabaseManager::class);
        $proxy = $manager->connection($connectionName);
        $newConnection = $proxy->getConnection();

        // Create new QueryBuilder with new connection
        $newQuery = new self($newConnection);

        // Copy current query state to new QueryBuilder
        $newQuery->table = $this->table;
        $newQuery->columns = $this->columns;
        $newQuery->wheres = $this->wheres;
        $newQuery->joins = $this->joins;
        $newQuery->orders = $this->orders;
        $newQuery->groups = $this->groups;
        $newQuery->havings = $this->havings;
        $newQuery->limit = $this->limit;
        $newQuery->offset = $this->offset;
        $newQuery->distinct = $this->distinct;
        $newQuery->bindings = $this->bindings;
        $newQuery->unions = $this->unions;
        $newQuery->lock = $this->lock;

        // Invalidate cache (connection changed)
        $newQuery->invalidateCache();

        return $newQuery;
    }

    // =========================================================================
    // STATIC PERFORMANCE & DEBUGGING METHODS
    // =========================================================================

    /**
     * Static relationship caching configuration.
     *
     * @var array
     */
    private static array $relationshipCacheConfig = [
        'enabled' => false,
        'size' => 0,
        'max_size' => 1000,
        'cache' => []
    ];

    /**
     * Enable relationship query caching for performance optimization.
     *
     * This is a Toporia exclusive feature for caching relationship queries.
     *
     * @param int $maxSize Maximum cache size (default: 1000)
     * @return void
     */
    public static function enableRelationshipCaching(int $maxSize = 1000): void
    {
        self::$relationshipCacheConfig['enabled'] = true;
        self::$relationshipCacheConfig['max_size'] = $maxSize;
    }

    /**
     * Disable relationship query caching.
     *
     * @return void
     */
    public static function disableRelationshipCaching(): void
    {
        self::$relationshipCacheConfig['enabled'] = false;
        self::$relationshipCacheConfig['cache'] = [];
        self::$relationshipCacheConfig['size'] = 0;
    }

    /**
     * Get relationship cache statistics.
     *
     * @return array Cache statistics
     */
    public static function getRelationshipCacheStats(): array
    {
        return [
            'enabled' => self::$relationshipCacheConfig['enabled'],
            'size' => self::$relationshipCacheConfig['size'],
            'max_size' => self::$relationshipCacheConfig['max_size'],
            'hit_ratio' => self::calculateCacheHitRatio()
        ];
    }

    /**
     * Clear relationship cache.
     *
     * @return void
     */
    public static function clearRelationshipCache(): void
    {
        self::$relationshipCacheConfig['cache'] = [];
        self::$relationshipCacheConfig['size'] = 0;
    }

    /**
     * Calculate cache hit ratio for performance monitoring.
     *
     * @return float Hit ratio (0.0 to 1.0)
     */
    private static function calculateCacheHitRatio(): float
    {
        // This would be implemented with actual hit/miss counters
        // For now, return a placeholder
        return 0.0;
    }

    /**
     * Get cached relationship query result.
     *
     * @param string $key Cache key
     * @return mixed|null Cached result or null if not found
     */
    public static function getCachedRelationshipQuery(string $key): mixed
    {
        if (!self::$relationshipCacheConfig['enabled']) {
            return null;
        }

        return self::$relationshipCacheConfig['cache'][$key] ?? null;
    }

    /**
     * Cache relationship query result.
     *
     * @param string $key Cache key
     * @param mixed $result Query result
     * @return void
     */
    public static function cacheRelationshipQuery(string $key, mixed $result): void
    {
        if (!self::$relationshipCacheConfig['enabled']) {
            return;
        }

        // Check cache size limit
        if (self::$relationshipCacheConfig['size'] >= self::$relationshipCacheConfig['max_size']) {
            // Remove oldest entry (simple FIFO)
            $firstKey = array_key_first(self::$relationshipCacheConfig['cache']);
            if ($firstKey !== null) {
                unset(self::$relationshipCacheConfig['cache'][$firstKey]);
                self::$relationshipCacheConfig['size']--;
            }
        }

        self::$relationshipCacheConfig['cache'][$key] = $result;
        self::$relationshipCacheConfig['size']++;
    }

    // =========================================================================
    // QUERY HINTS & PERFORMANCE OPTIMIZATION
    // =========================================================================

    /**
     * Add query hint for database optimization.
     *
     * @param string $type Hint type (index, no_cache, stream_results, etc.)
     * @param array $values Hint values
     * @return $this
     */
    public function addQueryHint(string $type, array $values = []): self
    {
        $this->queryHints[$type] = $values;
        $this->invalidateCache();
        return $this;
    }

    /**
     * Disable query result caching for this query.
     *
     * @return $this
     */
    public function disableQueryCaching(): self
    {
        // This is a placeholder for disabling query result caching
        // Implementation would depend on the caching layer
        return $this;
    }

    /**
     * Get all query hints.
     *
     * @return array<string, array>
     */
    public function getQueryHints(): array
    {
        return $this->queryHints;
    }

    /**
     * Apply query hints to SQL string based on database driver.
     *
     * @param string $sql Base SQL query
     * @return string SQL with hints applied
     */
    protected function applyQueryHints(string $sql): string
    {
        if (empty($this->queryHints)) {
            return $sql;
        }

        $driver = $this->connection->getConfig()['driver'] ?? 'mysql';

        return match ($driver) {
            'mysql' => $this->applyMySQLHints($sql),
            'pgsql' => $this->applyPostgreSQLHints($sql),
            'sqlite' => $this->applySQLiteHints($sql),
            default => $sql
        };
    }

    /**
     * Apply MySQL-specific query hints.
     *
     * @param string $sql Base SQL query
     * @return string SQL with MySQL hints
     */
    private function applyMySQLHints(string $sql): string
    {
        // Apply SQL_NO_CACHE hint
        if (isset($this->queryHints['no_cache'])) {
            $sql = preg_replace('/^SELECT\s+/i', 'SELECT SQL_NO_CACHE ', $sql);
        }

        // Apply index hints
        if (isset($this->queryHints['index']) && !empty($this->queryHints['index'])) {
            $indexes = implode(', ', $this->queryHints['index']);
            $tableName = $this->table ?? 'table';

            // Add USE INDEX hint
            $sql = str_replace(
                "FROM `{$tableName}`",
                "FROM `{$tableName}` USE INDEX ({$indexes})",
                $sql
            );

            // Also handle unquoted table names
            $sql = str_replace(
                "FROM {$tableName}",
                "FROM {$tableName} USE INDEX ({$indexes})",
                $sql
            );
        }

        // Apply force index hints
        if (isset($this->queryHints['force_index']) && !empty($this->queryHints['force_index'])) {
            $indexes = implode(', ', $this->queryHints['force_index']);
            $tableName = $this->table ?? 'table';

            $sql = str_replace(
                "FROM `{$tableName}`",
                "FROM `{$tableName}` FORCE INDEX ({$indexes})",
                $sql
            );

            $sql = str_replace(
                "FROM {$tableName}",
                "FROM {$tableName} FORCE INDEX ({$indexes})",
                $sql
            );
        }

        // Apply optimizer hints (MySQL 8.0+)
        if (isset($this->queryHints['optimizer']) && !empty($this->queryHints['optimizer'])) {
            $hints = implode(' ', $this->queryHints['optimizer']);
            $sql = preg_replace('/^SELECT\s+/i', "SELECT /*+ {$hints} */ ", $sql);
        }

        return $sql;
    }

    /**
     * Apply PostgreSQL-specific query hints.
     *
     * @param string $sql Base SQL query
     * @return string SQL with PostgreSQL hints
     */
    private function applyPostgreSQLHints(string $sql): string
    {
        // PostgreSQL doesn't have traditional hints, but we can add comments
        // and use SET statements for session-level optimizations

        if (isset($this->queryHints['no_cache'])) {
            // Add comment for PostgreSQL
            $sql = "/* NO_CACHE */ " . $sql;
        }

        // Note: PostgreSQL hints would typically be applied via
        // session settings or query planning hints in comments

        return $sql;
    }

    /**
     * Apply SQLite-specific query hints.
     *
     * @param string $sql Base SQL query
     * @return string SQL with SQLite hints
     */
    private function applySQLiteHints(string $sql): string
    {
        // SQLite has limited hint support
        // Most optimizations are handled by the query planner

        if (isset($this->queryHints['no_cache'])) {
            // Add comment for documentation
            $sql = "/* NO_CACHE */ " . $sql;
        }

        return $sql;
    }

    /**
     * Execute query in streaming mode for large datasets.
     *
     * Returns a Generator that yields rows one by one to minimize memory usage.
     * Ideal for processing millions of records without running out of memory.
     *
     * @return \Generator<array> Generator yielding database rows
     * @throws \RuntimeException If streaming is not supported
     */
    public function stream(): \Generator
    {
        if (!$this->connection->supportsStreaming()) {
            throw new \RuntimeException(
                "Streaming is not supported for driver: " .
                    ($this->connection->getConfig()['driver'] ?? 'unknown')
            );
        }

        $sql = $this->toSql();
        $bindings = $this->getBindings();

        yield from $this->connection->executeStreaming($sql, $bindings);
    }

    /**
     * Process large datasets in chunks using streaming.
     *
     * This method combines chunking with streaming for optimal memory usage
     * when processing very large datasets.
     *
     * @param int $chunkSize Number of records per chunk
     * @param callable $callback Callback to process each chunk
     * @return bool True if all chunks processed successfully
     */
    public function streamChunk(int $chunkSize, callable $callback): bool
    {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException('Chunk size must be at least 1');
        }

        $chunk = [];
        $count = 0;

        foreach ($this->stream() as $row) {
            $chunk[] = $row;
            $count++;

            if ($count >= $chunkSize) {
                // Process chunk
                $result = $callback(new RowCollection($chunk), $count);

                // If callback returns false, stop processing
                if ($result === false) {
                    return false;
                }

                // Reset for next chunk
                $chunk = [];
                $count = 0;
            }
        }

        // Process remaining records
        if (!empty($chunk)) {
            $callback(new RowCollection($chunk), $count);
        }

        return true;
    }
}
