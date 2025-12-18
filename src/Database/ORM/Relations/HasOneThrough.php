<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Relations;

use Toporia\Framework\Database\ORM\{Model, ModelCollection};
use Toporia\Framework\Database\Query\QueryBuilder;

/**
 * HasOneThrough Relationship
 *
 * Handles has-one-through relationships for distant relations.
 * Example: Country hasOne Phone through User
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
 * @package     toporia/framework
 * @subpackage  Relations
 * @since       2025-01-10
 */
class HasOneThrough extends Relation
{
    /** @var string|null Cached through table name */
    private ?string $throughTableCache = null;

    /** @var string|null Cached related table name */
    private ?string $relatedTableCache = null;

    /**
     * @param QueryBuilder $query Query builder for related model
     * @param Model $parent Parent model instance
     * @param class-string<Model> $relatedClass Related model class (Phone)
     * @param class-string<Model> $throughClass Through model class (User)
     * @param string $firstKey Foreign key on through table (users.country_id)
     * @param string $secondKey Foreign key on related table (phones.user_id)
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

        // Override foreignKey to use secondKey (phones.user_id)
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
    public function getResults(): ?Model
    {
        $relatedTable = $this->getRelatedTable();

        // Ensure table is set before executing query
        $table = $this->query->getTable();
        if ($table === null) {
            // Use related table if not set
            if ($relatedTable !== null) {
                $this->query->table($relatedTable);
            }
        }

        $this->query->select("{$relatedTable}.*");

        return $this->query->first();
    }

    /**
     * {@inheritdoc}
     */
    public function addEagerConstraints(array $models): void
    {
        $throughTable = $this->getThroughTable();
        $relatedTable = $this->getRelatedTable();

        $keys = array_map(fn($m) => $m->getAttribute($this->localKey), $models);

        $this->query = $this->query->newQuery()->table($relatedTable);

        $this->query->join(
            $throughTable,
            "{$throughTable}.{$this->secondLocalKey}",
            '=',
            "{$relatedTable}.{$this->foreignKey}"
        );

        // CRITICAL FIX: Wrap existing WHERE conditions to handle OR operator precedence
        // If user callback has OR conditions, we need to wrap them in parentheses
        // Example: (is_active = 1 OR status = 'approved') AND cities.id IN (...)
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
        $this->query->select("{$relatedTable}.*", "{$throughTable}.{$this->firstKey}");

        // Apply soft delete scope if related model uses soft deletes
        $this->applySoftDeleteScope($this->query, $this->relatedClass, $relatedTable);
    }

    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     *
     * PERFORMANCE: Optimized dictionary building and null handling for single model relations.
     * Single relations (HasOneThrough) return null when empty, not empty array.
     */
    public function match(array $models, mixed $results, string $relationName): array
    {
        // Early return: set null for all models if no results (single relation behavior)
        if (!$results instanceof ModelCollection || $results->isEmpty()) {
            foreach ($models as $model) {
                $model->setRelation($relationName, null);
            }
            return $models;
        }

        // OPTIMIZATION: Build dictionary once and reuse
        $dictionary = $this->buildDictionary($results);

        // OPTIMIZATION: Batch set relations with efficient lookup
        foreach ($models as $model) {
            $localValue = $model->getAttribute($this->localKey);
            // Use isset() for O(1) lookup instead of ?? operator with array access
            $relatedModel = isset($dictionary[$localValue]) ? $dictionary[$localValue] : null;
            $model->setRelation($relationName, $relatedModel);
        }

        return $models;
    }

    /**
     * Build dictionary for eager loading matching.
     *
     * @return array<int|string, Model>
     */
    protected function buildDictionary(ModelCollection $results): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->firstKey);
            if ($key !== null) {
                $dictionary[$key] = $result;
            }
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

        // Use freshQuery directly instead of creating another new query
        // freshQuery already has the table set from loadRelationBatch
        $instance->setQuery($freshQuery);

        // Copy where constraints from original query (excluding parent-specific through constraints)
        $this->copyWhereConstraints($freshQuery, [
            $this->firstKey,
            $this->foreignKey,
            fn($col) => $col === $this->firstKey || $col === $this->foreignKey
        ]);

        return $instance;
    }

    // =========================================================================
    // QUERY METHODS
    // =========================================================================

    /**
     * Check if the related model exists.
     */
    public function exists(): bool
    {
        return $this->parent->exists() && $this->query->exists();
    }

    /**
     * Get the count of related models (always 0 or 1 for HasOneThrough).
     */
    public function count(): int
    {
        return $this->parent->exists() ? $this->query->count() : 0;
    }

    // =========================================================================
    // CRUD OPERATIONS
    // =========================================================================

    /**
     * Update the related model through the intermediate relationship.
     */
    public function update(array $attributes): int
    {
        return $this->parent->exists() ? $this->query->update($attributes) : 0;
    }

    /**
     * Delete the related model through the intermediate relationship.
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
        $result = $this->getResults();

        if ($result === null) {
            throw new \RuntimeException('No related model found through intermediate relationship');
        }

        return $result;
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

    // =========================================================================
    // MAGIC METHODS
    // =========================================================================

    /**
     * Magic method to delegate calls to the underlying query builder.
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
}
