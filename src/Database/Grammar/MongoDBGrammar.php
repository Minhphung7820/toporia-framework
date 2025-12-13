<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Grammar;

use Toporia\Framework\Database\Contracts\GrammarInterface;
use Toporia\Framework\Database\Query\QueryBuilder;

/**
 * MongoDB Grammar Implementation
 *
 * Compiles QueryBuilder structures into MongoDB query syntax.
 * MongoDB is NoSQL, so this grammar converts SQL-like queries into MongoDB query arrays.
 *
 * MongoDB Query Structure:
 * - find() with filter, projection, sort, limit, skip
 * - insertOne/insertMany for INSERT
 * - updateOne/updateMany with $set, $unset for UPDATE
 * - deleteOne/deleteMany for DELETE
 *
 * Design Pattern: Adapter Pattern
 * - Adapts SQL-like QueryBuilder API to MongoDB query syntax
 * - Maintains same interface as SQL grammars for consistency
 *
 * SOLID Principles:
 * - Single Responsibility: Only compile queries to MongoDB syntax
 * - Open/Closed: Extensible for new MongoDB features
 * - Liskov Substitution: Can replace any GrammarInterface implementation
 * - Interface Segregation: Implements GrammarInterface fully
 * - Dependency Inversion: Depends on QueryBuilder abstraction
 *
 * Performance Optimizations:
 * - Query compilation caching
 * - Efficient array building
 * - Minimal memory allocation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Grammar
 * @since       2025-01-23
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class MongoDBGrammar implements GrammarInterface
{
    /**
     * Compilation cache to avoid recompiling identical queries.
     * Key: query hash, Value: compiled query JSON
     *
     * @var array<string, string>
     */
    private array $compilationCache = [];

    /**
     * MongoDB-specific features support map.
     *
     * @var array<string, bool>
     */
    protected array $features = [
        'window_functions' => false,  // Not applicable (aggregation pipeline)
        'returning_clause' => true,  // Return inserted/updated document
        'upsert' => true,             // upsert: true option
        'json_operators' => true,     // Native JSON support
        'cte' => false,               // Not applicable
        'aggregation_pipeline' => true, // MongoDB aggregation framework
        'text_search' => true,        // $text operator
        'geospatial' => true,         // $geoWithin, $near, etc.
    ];

    /**
     * {@inheritdoc}
     *
     * Compiles SELECT query to MongoDB find() query.
     * Returns JSON string of MongoDB query array for consistency with interface.
     *
     * MongoDB equivalent:
     * db.collection.find(filter, projection).sort(sort).limit(limit).skip(skip)
     */
    public function compileSelect(QueryBuilder $query): string
    {
        // Check cache first for performance
        $hash = $this->getQueryHash($query);
        if (isset($this->compilationCache[$hash])) {
            return $this->compilationCache[$hash];
        }

        $mongoQuery = [
            'operation' => 'find',
            'collection' => $query->getTable(),
            'filter' => $this->compileWheres($query),
            'projection' => $this->compileProjection($query),
            'sort' => $this->compileSort($query),
            'limit' => $query->hasLimit() ? $query->getLimit() : null,
            'skip' => $query->hasOffset() ? $query->getOffset() : null,
        ];

        // Remove null values for cleaner query
        $mongoQuery = array_filter($mongoQuery, fn($value) => $value !== null);

        $json = json_encode($mongoQuery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->compilationCache[$hash] = $json;
    }

    /**
     * {@inheritdoc}
     *
     * Compiles INSERT query to MongoDB insertOne/insertMany operation.
     */
    public function compileInsert(QueryBuilder $query, array $values): string
    {
        $mongoQuery = [
            'operation' => count($values) === 1 ? 'insertOne' : 'insertMany',
            'collection' => $query->getTable(),
            'documents' => $values,
        ];

        return json_encode($mongoQuery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * {@inheritdoc}
     *
     * Compiles UPDATE query to MongoDB updateOne/updateMany operation.
     */
    public function compileUpdate(QueryBuilder $query, array $values): string
    {
        $mongoQuery = [
            'operation' => 'updateMany', // Can be updateOne if single match expected
            'collection' => $query->getTable(),
            'filter' => $this->compileWheres($query),
            'update' => [
                '$set' => $values,
            ],
            'options' => [
                'upsert' => false, // Can be enabled via query options
            ],
        ];

        return json_encode($mongoQuery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * {@inheritdoc}
     *
     * Compiles DELETE query to MongoDB deleteOne/deleteMany operation.
     */
    public function compileDelete(QueryBuilder $query): string
    {
        $mongoQuery = [
            'operation' => 'deleteMany', // Can be deleteOne if single match expected
            'collection' => $query->getTable(),
            'filter' => $this->compileWheres($query),
        ];

        return json_encode($mongoQuery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Compile WHERE clauses to MongoDB filter.
     *
     * Converts SQL WHERE conditions to MongoDB $and, $or, $nor operators.
     *
     * @param QueryBuilder $query
     * @return array<string, mixed> MongoDB filter array
     */
    private function compileWheres(QueryBuilder $query): array
    {
        $wheres = $query->getWheres();
        if (empty($wheres)) {
            return [];
        }

        $filter = [];
        $andConditions = [];
        $orGroups = [];

        foreach ($wheres as $where) {
            $condition = $this->compileWhere($where);
            $boolean = $where['boolean'] ?? 'AND';

            if ($boolean === 'OR') {
                $orGroups[] = $condition;
            } else {
                $andConditions[] = $condition;
            }
        }

        // Combine conditions
        if (!empty($andConditions)) {
            if (count($andConditions) === 1) {
                $filter = array_merge($filter, $andConditions[0]);
            } else {
                $filter['$and'] = $andConditions;
            }
        }

        if (!empty($orGroups)) {
            if (count($orGroups) === 1 && empty($filter)) {
                $filter = $orGroups[0];
            } else {
                $filter['$or'] = $orGroups;
            }
        }

        return $filter;
    }

    /**
     * Compile a single WHERE clause to MongoDB condition.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed> MongoDB condition
     */
    private function compileWhere(array $where): array
    {
        $type = $where['type'];

        return match ($type) {
            'basic', 'Basic' => $this->compileBasicWhere($where),
            'in' => $this->compileWhereIn($where),
            'Null' => $this->compileWhereNull($where),
            'NotNull' => $this->compileWhereNotNull($where),
            'nested', 'Nested' => $this->compileNestedWhere($where),
            'DateBasic' => $this->compileDateBasicWhere($where),
            'MonthBasic' => $this->compileMonthBasicWhere($where),
            'DayBasic' => $this->compileDayBasicWhere($where),
            'YearBasic' => $this->compileYearBasicWhere($where),
            'TimeBasic' => $this->compileTimeBasicWhere($where),
            'Column' => $this->compileColumnWhere($where),
            'Exists' => $this->compileExistsWhere($where),
            'NotExists' => $this->compileNotExistsWhere($where),
            'InSub' => $this->compileInSubWhere($where),
            'NotInSub' => $this->compileNotInSubWhere($where),
            'Raw' => $this->compileRawWhere($where),
            default => throw new \InvalidArgumentException("Unknown WHERE type: {$type}"),
        };
    }

    /**
     * Compile basic WHERE (column operator value).
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileBasicWhere(array $where): array
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = $where['value'] ?? null;

        // Map SQL operators to MongoDB operators
        $mongoOperator = match ($operator) {
            '=' => '$eq',
            '!=' => '$ne',
            '>' => '$gt',
            '>=' => '$gte',
            '<' => '$lt',
            '<=' => '$lte',
            'LIKE' => '$regex',
            'NOT LIKE' => ['$not' => ['$regex' => $this->convertLikeToRegex($value)]],
            default => throw new \InvalidArgumentException("Unsupported operator: {$operator}"),
        };

        // Handle LIKE with regex conversion
        if ($operator === 'LIKE') {
            return [$column => ['$regex' => $this->convertLikeToRegex($value), '$options' => 'i']];
        }

        // For simple operators, use MongoDB comparison operators
        if ($mongoOperator === '$eq') {
            return [$column => $value];
        }

        return [$column => [$mongoOperator => $value]];
    }

    /**
     * Convert SQL LIKE pattern to MongoDB regex.
     *
     * @param string $pattern SQL LIKE pattern (% wildcard)
     * @return string MongoDB regex pattern
     */
    private function convertLikeToRegex(string $pattern): string
    {
        // Escape special regex characters except % and _
        $pattern = preg_quote($pattern, '/');
        // Convert SQL wildcards to regex
        $pattern = str_replace(['%', '_'], ['.*', '.'], $pattern);
        return '^' . $pattern . '$';
    }

    /**
     * Compile WHERE IN clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileWhereIn(array $where): array
    {
        $column = $where['column'];
        $values = $where['values'] ?? [];

        return [$column => ['$in' => $values]];
    }

    /**
     * Compile WHERE NULL clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileWhereNull(array $where): array
    {
        $column = $where['column'];
        return [$column => null];
    }

    /**
     * Compile WHERE NOT NULL clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileWhereNotNull(array $where): array
    {
        $column = $where['column'];
        return [$column => ['$ne' => null]];
    }

    /**
     * Compile nested WHERE (closure-based).
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileNestedWhere(array $where): array
    {
        /** @var QueryBuilder $query */
        $query = $where['query'];
        return $this->compileWheres($query);
    }

    /**
     * Compile DATE() WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileDateBasicWhere(array $where): array
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = $where['value'];

        // MongoDB date comparison - convert value to date range
        $dateStart = new \DateTime($value);
        $dateStart->setTime(0, 0, 0);
        $dateEnd = clone $dateStart;
        $dateEnd->setTime(23, 59, 59);

        $mongoOperator = match ($operator) {
            '=' => ['$gte' => $dateStart, '$lte' => $dateEnd],
            '>=' => ['$gte' => $dateStart],
            '<=' => ['$lte' => $dateEnd],
            '>' => ['$gt' => $dateEnd],
            '<' => ['$lt' => $dateStart],
            default => throw new \InvalidArgumentException("Unsupported date operator: {$operator}"),
        };

        return [$column => $mongoOperator];
    }

    /**
     * Compile MONTH() WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileMonthBasicWhere(array $where): array
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = (int)$where['value'];

        // MongoDB: Use $expr with $month operator
        $mongoOperator = match ($operator) {
            '=' => '$eq',
            '!=' => '$ne',
            '>' => '$gt',
            '>=' => '$gte',
            '<' => '$lt',
            '<=' => '$lte',
            default => throw new \InvalidArgumentException("Unsupported month operator: {$operator}"),
        };

        return [
            '$expr' => [
                $mongoOperator => [
                    ['$month' => '$' . $column],
                    $value
                ]
            ]
        ];
    }

    /**
     * Compile DAY() WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileDayBasicWhere(array $where): array
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = (int)$where['value'];

        $mongoOperator = match ($operator) {
            '=' => '$eq',
            '!=' => '$ne',
            '>' => '$gt',
            '>=' => '$gte',
            '<' => '$lt',
            '<=' => '$lte',
            default => throw new \InvalidArgumentException("Unsupported day operator: {$operator}"),
        };

        return [
            '$expr' => [
                $mongoOperator => [
                    ['$dayOfMonth' => '$' . $column],
                    $value
                ]
            ]
        ];
    }

    /**
     * Compile YEAR() WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileYearBasicWhere(array $where): array
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = (int)$where['value'];

        $mongoOperator = match ($operator) {
            '=' => '$eq',
            '!=' => '$ne',
            '>' => '$gt',
            '>=' => '$gte',
            '<' => '$lt',
            '<=' => '$lte',
            default => throw new \InvalidArgumentException("Unsupported year operator: {$operator}"),
        };

        return [
            '$expr' => [
                $mongoOperator => [
                    ['$year' => '$' . $column],
                    $value
                ]
            ]
        ];
    }

    /**
     * Compile TIME() WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileTimeBasicWhere(array $where): array
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = $where['value'];

        $mongoOperator = match ($operator) {
            '=' => '$eq',
            '!=' => '$ne',
            '>=' => '$gte',
            '<=' => '$lte',
            '>' => '$gt',
            '<' => '$lt',
            default => throw new \InvalidArgumentException("Unsupported time operator: {$operator}"),
        };

        // MongoDB: Extract time portion and compare
        return [
            '$expr' => [
                $mongoOperator => [
                    [
                        '$dateToString' => [
                            'format' => '%H:%M:%S',
                            'date' => '$' . $column
                        ]
                    ],
                    $value
                ]
            ]
        ];
    }

    /**
     * Compile column-to-column WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileColumnWhere(array $where): array
    {
        $first = $where['first'];
        $operator = $where['operator'];
        $second = $where['second'];

        return [
            '$expr' => [
                match ($operator) {
                    '=' => '$eq',
                    '!=' => '$ne',
                    '>' => '$gt',
                    '>=' => '$gte',
                    '<' => '$lt',
                    '<=' => '$lte',
                    default => throw new \InvalidArgumentException("Unsupported column operator: {$operator}"),
                } => ['$' . $first, '$' . $second]
            ]
        ];
    }

    /**
     * Compile EXISTS WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileExistsWhere(array $where): array
    {
        // MongoDB: Use $exists operator or aggregation pipeline
        // For simplicity, return empty array (would need aggregation pipeline)
        return [];
    }

    /**
     * Compile NOT EXISTS WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileNotExistsWhere(array $where): array
    {
        // MongoDB: Use $not with $exists or aggregation pipeline
        return [];
    }

    /**
     * Compile IN subquery WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileInSubWhere(array $where): array
    {
        // MongoDB: Would need aggregation pipeline with $lookup
        // For now, return empty (would need to execute subquery first)
        return [];
    }

    /**
     * Compile NOT IN subquery WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileNotInSubWhere(array $where): array
    {
        // MongoDB: Would need aggregation pipeline
        return [];
    }

    /**
     * Compile raw WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function compileRawWhere(array $where): array
    {
        // MongoDB: Raw filter - decode JSON if provided
        $sql = $where['sql'] ?? '';
        $decoded = json_decode($sql, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Compile SELECT columns to MongoDB projection.
     *
     * @param QueryBuilder $query
     * @return array<string, int> MongoDB projection (1 = include, 0 = exclude)
     */
    private function compileProjection(QueryBuilder $query): array
    {
        $columns = $query->getColumns();

        // If selecting all columns
        if (empty($columns) || $columns === ['*']) {
            return [];
        }

        $projection = [];
        foreach ($columns as $column) {
            // Handle aliases: column AS alias
            if (str_contains($column, ' AS ')) {
                [$col, $alias] = explode(' AS ', $column, 2);
                $projection[trim($col)] = 1;
            } else {
                $projection[trim($column)] = 1;
            }
        }

        return $projection;
    }

    /**
     * Compile ORDER BY to MongoDB sort.
     *
     * @param QueryBuilder $query
     * @return array<string, int> MongoDB sort (1 = ASC, -1 = DESC)
     */
    private function compileSort(QueryBuilder $query): array
    {
        $orders = $query->getOrders();
        if (empty($orders)) {
            return [];
        }

        $sort = [];
        foreach ($orders as $order) {
            $column = $order['column'];
            $direction = strtoupper($order['direction']);
            $sort[$column] = $direction === 'DESC' ? -1 : 1;
        }

        return $sort;
    }

    /**
     * {@inheritdoc}
     *
     * MongoDB doesn't use table/column quotes like SQL.
     * Return as-is for collection/field names.
     */
    public function wrapTable(string $table): string
    {
        return $table;
    }

    /**
     * {@inheritdoc}
     *
     * MongoDB doesn't use column quotes.
     * Return as-is for field names.
     */
    public function wrapColumn(string $column): string
    {
        return $column;
    }

    /**
     * {@inheritdoc}
     *
     * MongoDB uses positional placeholders in aggregation pipelines.
     * For find queries, values are embedded directly.
     * Return '?' for consistency.
     */
    public function getParameterPlaceholder(int $index): string
    {
        return '?';
    }

    /**
     * {@inheritdoc}
     *
     * MongoDB uses limit() method, not SQL LIMIT clause.
     * Return empty string (handled in compileSelect).
     */
    public function compileLimit(int $limit): string
    {
        return ''; // Handled in compileSelect as 'limit' option
    }

    /**
     * {@inheritdoc}
     *
     * MongoDB uses skip() method, not SQL OFFSET clause.
     * Return empty string (handled in compileSelect).
     */
    public function compileOffset(int $offset): string
    {
        return ''; // Handled in compileSelect as 'skip' option
    }

    /**
     * {@inheritdoc}
     */
    public function supportsFeature(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    /**
     * Generate a hash for the query structure (for caching).
     *
     * @param QueryBuilder $query
     * @return string
     */
    private function getQueryHash(QueryBuilder $query): string
    {
        return md5(serialize([
            $query->getTable(),
            $query->getColumns(),
            $query->getWheres(),
            $query->getJoins(),
            $query->getGroups(),
            $query->getOrders(),
            $query->getLimit(),
            $query->getOffset(),
        ]));
    }
}
