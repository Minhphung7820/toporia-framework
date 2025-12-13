<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Contracts;

use Closure;
use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Database\ORM\ModelCollection;
use Toporia\Framework\Support\Pagination\Paginator;
use Toporia\Framework\Support\Pagination\CursorPaginator;

/**
 * Interface RepositoryInterface
 *
 * Core contract for Repository Pattern implementation.
 * Provides a clean abstraction layer between domain logic and data persistence.
 *
 * Features:
 * - Full CRUD operations
 * - Flexible querying with criteria pattern
 * - Pagination support (offset & cursor)
 * - Eager loading for relationships
 * - Caching integration
 * - Event dispatching
 *
 * @template TModel of Model
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository\Contracts
 */
interface RepositoryInterface
{
    /**
     * Find entity by primary key.
     *
     * @param int|string $id Primary key value
     * @param array<string> $columns Columns to select
     * @return TModel|null
     */
    public function find(int|string $id, array $columns = ['*']): ?Model;

    /**
     * Find entity by primary key or throw exception.
     *
     * @param int|string $id Primary key value
     * @param array<string> $columns Columns to select
     * @return TModel
     * @throws \Toporia\Framework\Repository\Exceptions\EntityNotFoundException
     */
    public function findOrFail(int|string $id, array $columns = ['*']): Model;

    /**
     * Find multiple entities by primary keys.
     *
     * @param array<int|string> $ids Array of primary key values
     * @param array<string> $columns Columns to select
     * @return ModelCollection<TModel>
     */
    public function findMany(array $ids, array $columns = ['*']): ModelCollection;

    /**
     * Find entity by specific field value.
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param array<string> $columns Columns to select
     * @return TModel|null
     */
    public function findBy(string $field, mixed $value, array $columns = ['*']): ?Model;

    /**
     * Find all entities matching field value.
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param array<string> $columns Columns to select
     * @return ModelCollection<TModel>
     */
    public function findAllBy(string $field, mixed $value, array $columns = ['*']): ModelCollection;

    /**
     * Find entities matching criteria.
     *
     * @param array<string, mixed> $criteria Key-value pairs for WHERE conditions
     * @param array<string> $columns Columns to select
     * @return ModelCollection<TModel>
     */
    public function findWhere(array $criteria, array $columns = ['*']): ModelCollection;

    /**
     * Find entities where field is in array of values.
     *
     * @param string $field Field name
     * @param array<mixed> $values Array of values
     * @param array<string> $columns Columns to select
     * @return ModelCollection<TModel>
     */
    public function findWhereIn(string $field, array $values, array $columns = ['*']): ModelCollection;

    /**
     * Find entities where field is not in array of values.
     *
     * @param string $field Field name
     * @param array<mixed> $values Array of values
     * @param array<string> $columns Columns to select
     * @return ModelCollection<TModel>
     */
    public function findWhereNotIn(string $field, array $values, array $columns = ['*']): ModelCollection;

    /**
     * Find entities where field is between values.
     *
     * @param string $field Field name
     * @param mixed $min Minimum value
     * @param mixed $max Maximum value
     * @param array<string> $columns Columns to select
     * @return ModelCollection<TModel>
     */
    public function findWhereBetween(string $field, mixed $min, mixed $max, array $columns = ['*']): ModelCollection;

    /**
     * Get first entity matching criteria.
     *
     * @param array<string, mixed> $criteria Key-value pairs for WHERE conditions
     * @param array<string> $columns Columns to select
     * @return TModel|null
     */
    public function first(array $criteria = [], array $columns = ['*']): ?Model;

    /**
     * Get first entity or throw exception.
     *
     * @param array<string, mixed> $criteria Key-value pairs for WHERE conditions
     * @param array<string> $columns Columns to select
     * @return TModel
     * @throws \Toporia\Framework\Repository\Exceptions\EntityNotFoundException
     */
    public function firstOrFail(array $criteria = [], array $columns = ['*']): Model;

    /**
     * Get first entity or create new one.
     *
     * @param array<string, mixed> $criteria Search criteria
     * @param array<string, mixed> $attributes Additional attributes for creation
     * @return TModel
     */
    public function firstOrCreate(array $criteria, array $attributes = []): Model;

    /**
     * Get first entity or instantiate new one (not persisted).
     *
     * @param array<string, mixed> $criteria Search criteria
     * @param array<string, mixed> $attributes Additional attributes
     * @return TModel
     */
    public function firstOrNew(array $criteria, array $attributes = []): Model;

    /**
     * Get all entities.
     *
     * @param array<string> $columns Columns to select
     * @return ModelCollection<TModel>
     */
    public function all(array $columns = ['*']): ModelCollection;

    /**
     * Get paginated results.
     *
     * @param int $perPage Items per page
     * @param int $page Current page number
     * @param array<string> $columns Columns to select
     * @return Paginator
     */
    public function paginate(int $perPage = 15, int $page = 1, array $columns = ['*']): Paginator;

    /**
     * Get cursor-paginated results (for large datasets).
     *
     * @param int $perPage Items per page
     * @param string|null $cursor Cursor position
     * @param array<string> $columns Columns to select
     * @return CursorPaginator
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, array $columns = ['*']): CursorPaginator;

    /**
     * Create new entity.
     *
     * @param array<string, mixed> $attributes Entity attributes
     * @return TModel
     */
    public function create(array $attributes): Model;

    /**
     * Create multiple entities.
     *
     * @param array<array<string, mixed>> $records Array of attribute arrays
     * @return ModelCollection<TModel>
     */
    public function createMany(array $records): ModelCollection;

    /**
     * Update entity by primary key.
     *
     * @param int|string $id Primary key value
     * @param array<string, mixed> $attributes Attributes to update
     * @return TModel
     * @throws \Toporia\Framework\Repository\Exceptions\EntityNotFoundException
     */
    public function update(int|string $id, array $attributes): Model;

    /**
     * Update entities matching criteria.
     *
     * @param array<string, mixed> $criteria WHERE conditions
     * @param array<string, mixed> $attributes Attributes to update
     * @return int Number of affected rows
     */
    public function updateWhere(array $criteria, array $attributes): int;

    /**
     * Update or create entity.
     *
     * @param array<string, mixed> $criteria Search criteria
     * @param array<string, mixed> $attributes Attributes to set
     * @return TModel
     */
    public function updateOrCreate(array $criteria, array $attributes): Model;

    /**
     * Upsert multiple records (insert or update).
     *
     * @param array<array<string, mixed>> $records Records to upsert
     * @param array<string> $uniqueBy Columns for uniqueness check
     * @param array<string>|null $updateColumns Columns to update (null = all)
     * @return int Number of affected rows
     */
    public function upsert(array $records, array $uniqueBy, ?array $updateColumns = null): int;

    /**
     * Delete entity by primary key.
     *
     * @param int|string $id Primary key value
     * @return bool
     */
    public function delete(int|string $id): bool;

    /**
     * Delete entities matching criteria.
     *
     * @param array<string, mixed> $criteria WHERE conditions
     * @return int Number of deleted rows
     */
    public function deleteWhere(array $criteria): int;

    /**
     * Force delete entity (bypass soft delete).
     *
     * @param int|string $id Primary key value
     * @return bool
     */
    public function forceDelete(int|string $id): bool;

    /**
     * Restore soft-deleted entity.
     *
     * @param int|string $id Primary key value
     * @return bool
     */
    public function restore(int|string $id): bool;

    /**
     * Check if entity exists by primary key.
     *
     * @param int|string $id Primary key value
     * @return bool
     */
    public function exists(int|string $id): bool;

    /**
     * Check if entities exist matching criteria.
     *
     * @param array<string, mixed> $criteria WHERE conditions
     * @return bool
     */
    public function existsWhere(array $criteria): bool;

    /**
     * Count all entities.
     *
     * @return int
     */
    public function count(): int;

    /**
     * Count entities matching criteria.
     *
     * @param array<string, mixed> $criteria WHERE conditions
     * @return int
     */
    public function countWhere(array $criteria): int;

    /**
     * Get sum of column values.
     *
     * @param string $column Column name
     * @param array<string, mixed> $criteria WHERE conditions
     * @return float|int
     */
    public function sum(string $column, array $criteria = []): float|int;

    /**
     * Get average of column values.
     *
     * @param string $column Column name
     * @param array<string, mixed> $criteria WHERE conditions
     * @return float|null
     */
    public function avg(string $column, array $criteria = []): ?float;

    /**
     * Get minimum column value.
     *
     * @param string $column Column name
     * @param array<string, mixed> $criteria WHERE conditions
     * @return mixed
     */
    public function min(string $column, array $criteria = []): mixed;

    /**
     * Get maximum column value.
     *
     * @param string $column Column name
     * @param array<string, mixed> $criteria WHERE conditions
     * @return mixed
     */
    public function max(string $column, array $criteria = []): mixed;

    /**
     * Eager load relationships.
     *
     * @param array<string>|string $relations Relations to load
     * @return static
     */
    public function with(array|string $relations): static;

    /**
     * Add ordering.
     *
     * @param string $column Column to order by
     * @param string $direction Sort direction (asc/desc)
     * @return static
     */
    public function orderBy(string $column, string $direction = 'asc'): static;

    /**
     * Limit results.
     *
     * @param int $limit Maximum records
     * @return static
     */
    public function limit(int $limit): static;

    /**
     * Offset results.
     *
     * @param int $offset Number of records to skip
     * @return static
     */
    public function offset(int $offset): static;

    /**
     * Include soft-deleted entities in queries.
     *
     * @return static
     */
    public function withTrashed(): static;

    /**
     * Only get soft-deleted entities.
     *
     * @return static
     */
    public function onlyTrashed(): static;

    /**
     * Apply scope callback to query.
     *
     * @param Closure $callback Query callback
     * @return static
     */
    public function scope(Closure $callback): static;

    /**
     * Reset query modifications.
     *
     * @return static
     */
    public function resetQuery(): static;

    /**
     * Get the model class name.
     *
     * @return class-string<TModel>
     */
    public function getModelClass(): string;

    /**
     * Make new model instance.
     *
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function makeModel(array $attributes = []): Model;
}
