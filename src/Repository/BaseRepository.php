<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository;

use Closure;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Database\ORM\ModelCollection;
use Toporia\Framework\Support\Pagination\Paginator;
use Toporia\Framework\Support\Pagination\CursorPaginator;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;
use Toporia\Framework\Repository\Contracts\CriteriaRepositoryInterface;
use Toporia\Framework\Repository\Contracts\CacheableRepositoryInterface;
use Toporia\Framework\Repository\Concerns\HasCriteria;
use Toporia\Framework\Repository\Concerns\HasCache;
use Toporia\Framework\Repository\Concerns\HasEvents;
use Toporia\Framework\Repository\Exceptions\EntityNotFoundException;

/**
 * Base Repository
 *
 * Abstract base class providing complete repository functionality.
 * Extend this class and set the $model property to create entity-specific repositories.
 *
 * Features:
 * - Full CRUD operations with automatic model hydration
 * - Criteria pattern for composable queries
 * - Query caching with tags support
 * - Event dispatching for lifecycle hooks
 * - Eager loading for relationships
 * - Soft delete support
 * - Aggregate functions
 * - Pagination (offset & cursor)
 *
 * Performance:
 * - O(1) for single entity operations with caching
 * - O(N) for collection operations
 * - Lazy query building (no DB hit until execution)
 * - Connection caching per model
 *
 * @template TModel of Model
 * @implements RepositoryInterface<TModel>
 * @implements CriteriaRepositoryInterface<TModel>
 * @implements CacheableRepositoryInterface<TModel>
 *
 * @example
 * ```php
 * class UserRepository extends BaseRepository
 * {
 *     protected string $model = User::class;
 *
 *     public function findActiveByEmail(string $email): ?User
 *     {
 *         return $this->findWhere([
 *             'email' => $email,
 *             'status' => 'active'
 *         ])->first();
 *     }
 * }
 * ```
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 */
abstract class BaseRepository implements
    RepositoryInterface,
    CriteriaRepositoryInterface,
    CacheableRepositoryInterface
{
    use HasCriteria;
    use HasCache;
    use HasEvents;

    /**
     * Model class name.
     *
     * @var class-string<TModel>
     */
    protected string $model;

    /**
     * Model instance for query building.
     *
     * @var TModel|null
     */
    protected ?Model $modelInstance = null;

    /**
     * Current query builder.
     *
     * @var ModelQueryBuilder|null
     */
    protected ?ModelQueryBuilder $query = null;

    /**
     * Relationships to eager load.
     *
     * @var array<string>
     */
    protected array $eagerLoad = [];

    /**
     * Order by clauses.
     *
     * @var array<array{column: string, direction: string}>
     */
    protected array $orderBys = [];

    /**
     * Query limit.
     *
     * @var int|null
     */
    protected ?int $queryLimit = null;

    /**
     * Query offset.
     *
     * @var int|null
     */
    protected ?int $queryOffset = null;

    /**
     * Include soft-deleted records.
     *
     * @var bool
     */
    protected bool $includeTrashed = false;

    /**
     * Only soft-deleted records.
     *
     * @var bool
     */
    protected bool $onlyTrashedFlag = false;

    /**
     * Scope callbacks.
     *
     * @var array<Closure>
     */
    protected array $scopes = [];

    /**
     * Create repository instance.
     *
     * @param ContainerInterface|null $container DI container
     */
    public function __construct(
        protected ?ContainerInterface $container = null
    ) {
        $this->createModelInstance();
        $this->criteria = $this->getDefaultCriteria();
    }

    /**
     * Create new model instance for internal use.
     *
     * @return TModel
     */
    protected function createModelInstance(): Model
    {
        // Try to resolve from container if available, otherwise instantiate directly
        if ($this->container !== null) {
            $model = $this->container->make($this->model);
        } else {
            $model = new $this->model();
        }

        $this->modelInstance = $model;
        return $model;
    }

    /**
     * Get the primary key name for the model.
     *
     * @return string
     */
    protected function getPrimaryKeyName(): string
    {
        return ($this->model)::getKeyName();
    }

    /**
     * Get fresh query builder.
     *
     * @return ModelQueryBuilder
     */
    protected function newQuery(): ModelQueryBuilder
    {
        // Apply soft delete handling using Model static methods
        if ($this->modelUsesSoftDeletes()) {
            if ($this->includeTrashed) {
                /** @var class-string<Model&\Toporia\Framework\Database\ORM\Concerns\SoftDeletes> $modelClass */
                $modelClass = $this->model;
                $query = $modelClass::withTrashed();
            } elseif ($this->onlyTrashedFlag) {
                /** @var class-string<Model&\Toporia\Framework\Database\ORM\Concerns\SoftDeletes> $modelClass */
                $modelClass = $this->model;
                $query = $modelClass::onlyTrashed();
            } else {
                // Regular query with soft delete scope
                $query = ($this->model)::query();
            }
        } else {
            // Regular query without soft delete support
            $query = ($this->model)::query();
        }

        // Apply eager loading
        if (!empty($this->eagerLoad) && method_exists($query, 'with')) {
            $query->with($this->eagerLoad);
        }

        // Apply ordering
        foreach ($this->orderBys as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }

        // Apply limit/offset
        if ($this->queryLimit !== null) {
            $query->limit($this->queryLimit);
        }
        if ($this->queryOffset !== null) {
            $query->offset($this->queryOffset);
        }

        // Apply scopes
        foreach ($this->scopes as $scope) {
            $scope($query);
        }

        // Apply criteria
        return $this->applyCriteriaToQuery($query);
    }

    /**
     * {@inheritDoc}
     */
    public function resetQuery(): static
    {
        $this->query = null;
        $this->eagerLoad = [];
        $this->orderBys = [];
        $this->queryLimit = null;
        $this->queryOffset = null;
        $this->includeTrashed = false;
        $this->onlyTrashedFlag = false;
        $this->scopes = [];
        $this->skipCriteria = false;
        $this->resetCacheState();
        return $this;
    }

    // =========================================================================
    // READ OPERATIONS
    // =========================================================================

    /**
     * {@inheritDoc}
     */
    public function find(int|string $id, array $columns = ['*']): ?Model
    {
        $cacheKey = $this->getCacheKey('find', [$id, $columns]);

        /** @var Model|null $result */
        $result = $this->remember($cacheKey, function () use ($id, $columns) {
            $entity = $this->newQuery()->select($columns)->find($id);
            $this->resetQuery();
            return $entity;
        });

        if ($result instanceof Model) {
            $this->fireRetrieved($result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function findOrFail(int|string $id, array $columns = ['*']): Model
    {
        $entity = $this->find($id, $columns);

        if ($entity === null) {
            throw new EntityNotFoundException($this->model, $id);
        }

        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function findMany(array $ids, array $columns = ['*']): ModelCollection
    {
        if (empty($ids)) {
            return new ModelCollection([]);
        }

        $cacheKey = $this->getCacheKey('findMany', [$ids, $columns]);

        $result = $this->remember($cacheKey, function () use ($ids, $columns) {
            $primaryKey = $this->getPrimaryKeyName();
            $entities = $this->newQuery()
                ->select($columns)
                ->whereIn($primaryKey, $ids)
                ->get();
            $this->resetQuery();
            return $entities;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function findBy(string $field, mixed $value, array $columns = ['*']): ?Model
    {
        $cacheKey = $this->getCacheKey('findBy', [$field, $value, $columns]);

        /** @var Model|null $result */
        $result = $this->remember($cacheKey, function () use ($field, $value, $columns) {
            $entity = $this->newQuery()
                ->select($columns)
                ->where($field, $value)
                ->first();
            $this->resetQuery();
            return $entity;
        });

        if ($result instanceof Model) {
            $this->fireRetrieved($result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function findAllBy(string $field, mixed $value, array $columns = ['*']): ModelCollection
    {
        $cacheKey = $this->getCacheKey('findAllBy', [$field, $value, $columns]);

        $result = $this->remember($cacheKey, function () use ($field, $value, $columns) {
            $entities = $this->newQuery()
                ->select($columns)
                ->where($field, $value)
                ->get();
            $this->resetQuery();
            return $entities;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function findWhere(array $criteria, array $columns = ['*']): ModelCollection
    {
        $cacheKey = $this->getCacheKey('findWhere', [$criteria, $columns]);

        $result = $this->remember($cacheKey, function () use ($criteria, $columns) {
            $query = $this->newQuery()->select($columns);

            foreach ($criteria as $field => $value) {
                if (is_array($value)) {
                    $query->whereIn($field, $value);
                } else {
                    $query->where($field, $value);
                }
            }

            $entities = $query->get();
            $this->resetQuery();
            return $entities;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function findWhereIn(string $field, array $values, array $columns = ['*']): ModelCollection
    {
        if (empty($values)) {
            return new ModelCollection([]);
        }

        $cacheKey = $this->getCacheKey('findWhereIn', [$field, $values, $columns]);

        $result = $this->remember($cacheKey, function () use ($field, $values, $columns) {
            $entities = $this->newQuery()
                ->select($columns)
                ->whereIn($field, $values)
                ->get();
            $this->resetQuery();
            return $entities;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function findWhereNotIn(string $field, array $values, array $columns = ['*']): ModelCollection
    {
        $cacheKey = $this->getCacheKey('findWhereNotIn', [$field, $values, $columns]);

        $result = $this->remember($cacheKey, function () use ($field, $values, $columns) {
            $entities = $this->newQuery()
                ->select($columns)
                ->whereNotIn($field, $values)
                ->get();
            $this->resetQuery();
            return $entities;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function findWhereBetween(string $field, mixed $min, mixed $max, array $columns = ['*']): ModelCollection
    {
        $cacheKey = $this->getCacheKey('findWhereBetween', [$field, $min, $max, $columns]);

        $result = $this->remember($cacheKey, function () use ($field, $min, $max, $columns) {
            $entities = $this->newQuery()
                ->select($columns)
                ->whereBetween($field, [$min, $max])
                ->get();
            $this->resetQuery();
            return $entities;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function first(array $criteria = [], array $columns = ['*']): ?Model
    {
        $cacheKey = $this->getCacheKey('first', [$criteria, $columns]);

        /** @var Model|null $result */
        $result = $this->remember($cacheKey, function () use ($criteria, $columns) {
            $query = $this->newQuery()->select($columns);

            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }

            $entity = $query->first();
            $this->resetQuery();
            return $entity;
        });

        if ($result instanceof Model) {
            $this->fireRetrieved($result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function firstOrFail(array $criteria = [], array $columns = ['*']): Model
    {
        $entity = $this->first($criteria, $columns);

        if ($entity === null) {
            throw new EntityNotFoundException($this->model, $criteria);
        }

        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function firstOrCreate(array $criteria, array $attributes = []): Model
    {
        $entity = $this->first($criteria);

        if ($entity !== null) {
            return $entity;
        }

        return $this->create(array_merge($criteria, $attributes));
    }

    /**
     * {@inheritDoc}
     */
    public function firstOrNew(array $criteria, array $attributes = []): Model
    {
        $entity = $this->first($criteria);

        if ($entity !== null) {
            return $entity;
        }

        return $this->newModelInstance(array_merge($criteria, $attributes));
    }

    /**
     * {@inheritDoc}
     */
    public function all(array $columns = ['*']): ModelCollection
    {
        $cacheKey = $this->getCacheKey('all', [$columns]);

        $result = $this->remember($cacheKey, function () use ($columns) {
            $entities = $this->newQuery()->select($columns)->get();
            $this->resetQuery();
            return $entities;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function paginate(int $perPage = 15, int $page = 1, array $columns = ['*']): Paginator
    {
        // Pagination typically shouldn't be cached
        $this->skipCache();

        $result = $this->newQuery()
            ->select($columns)
            ->paginate($perPage, $page);

        $this->resetQuery();
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, array $columns = ['*']): CursorPaginator
    {
        // Cursor pagination shouldn't be cached
        $this->skipCache();

        $result = $this->newQuery()
            ->select($columns)
            ->cursorPaginate($perPage, ['cursor' => $cursor]);

        $this->resetQuery();
        return $result;
    }

    // =========================================================================
    // WRITE OPERATIONS
    // =========================================================================

    /**
     * {@inheritDoc}
     */
    public function create(array $attributes): Model
    {
        $this->fireCreating($attributes);

        $entity = $this->newModelInstance();
        $entity->fill($attributes);
        $entity->save();

        $this->fireCreated($entity);
        $this->invalidateCacheOnWrite();

        return $entity;
    }

    /**
     * Create a new model instance (not persisted).
     *
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    protected function newModelInstance(array $attributes = []): Model
    {
        $model = new $this->model();

        if (!empty($attributes)) {
            $model->fill($attributes);
        }

        return $model;
    }

    /**
     * {@inheritDoc}
     */
    public function createMany(array $records): ModelCollection
    {
        $entities = [];

        foreach ($records as $attributes) {
            $entities[] = $this->create($attributes);
        }

        return new ModelCollection($entities);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int|string $id, array $attributes): Model
    {
        $entity = $this->findOrFail($id);

        $this->fireUpdating($entity, $attributes);

        $entity->fill($attributes);
        $entity->save();

        $this->fireUpdated($entity);
        $this->invalidateCacheOnWrite();

        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function updateWhere(array $criteria, array $attributes): int
    {
        $query = $this->newQuery();

        foreach ($criteria as $field => $value) {
            if (is_array($value)) {
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }

        $affected = $query->update($attributes);

        $this->resetQuery();
        $this->invalidateCacheOnWrite();

        return $affected;
    }

    /**
     * {@inheritDoc}
     */
    public function updateOrCreate(array $criteria, array $attributes): Model
    {
        $entity = $this->first($criteria);

        if ($entity !== null) {
            $this->fireUpdating($entity, $attributes);
            $entity->fill($attributes);
            $entity->save();
            $this->fireUpdated($entity);
        } else {
            $entity = $this->create(array_merge($criteria, $attributes));
        }

        $this->invalidateCacheOnWrite();

        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function upsert(array $records, array $uniqueBy, ?array $updateColumns = null): int
    {
        $affected = ($this->model)::query()->upsert($records, $uniqueBy, $updateColumns);

        $this->invalidateCacheOnWrite();

        return $affected;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int|string $id): bool
    {
        $entity = $this->findOrFail($id);

        $this->fireDeleting($entity);

        $result = $entity->delete();

        $this->fireDeleted($entity);
        $this->invalidateCacheOnWrite();

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteWhere(array $criteria): int
    {
        $query = $this->newQuery();

        foreach ($criteria as $field => $value) {
            if (is_array($value)) {
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }

        $affected = $query->delete();

        $this->resetQuery();
        $this->invalidateCacheOnWrite();

        return $affected;
    }

    /**
     * {@inheritDoc}
     */
    public function forceDelete(int|string $id): bool
    {
        $entity = $this->withTrashed()->findOrFail($id);

        $this->fireDeleting($entity);

        // Check if model supports soft deletes
        if (!$this->modelUsesSoftDeletes()) {
            // Regular delete for non-soft-delete models
            $result = $entity->delete();
        } else {
            /** @var Model&\Toporia\Framework\Database\ORM\Concerns\SoftDeletes $entity */
            $result = $entity->forceDelete();
        }

        $this->fireDeleted($entity);
        $this->invalidateCacheOnWrite();

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function restore(int|string $id): bool
    {
        if (!$this->modelUsesSoftDeletes()) {
            return false;
        }

        $entity = $this->onlyTrashed()->findOrFail($id);

        $this->fireRestoring($entity);

        /** @var Model&\Toporia\Framework\Database\ORM\Concerns\SoftDeletes $entity */
        $result = $entity->restore();

        $this->fireRestored($entity);
        $this->invalidateCacheOnWrite();

        return $result;
    }

    /**
     * Check if the model uses SoftDeletes trait.
     *
     * @return bool
     */
    protected function modelUsesSoftDeletes(): bool
    {
        return method_exists($this->model, 'usesSoftDeletes')
            && ($this->model)::usesSoftDeletes();
    }

    // =========================================================================
    // AGGREGATE OPERATIONS
    // =========================================================================

    /**
     * {@inheritDoc}
     */
    public function exists(int|string $id): bool
    {
        $primaryKey = $this->getPrimaryKeyName();
        return $this->existsWhere([$primaryKey => $id]);
    }

    /**
     * {@inheritDoc}
     */
    public function existsWhere(array $criteria): bool
    {
        $cacheKey = $this->getCacheKey('existsWhere', [$criteria]);

        $result = $this->remember($cacheKey, function () use ($criteria) {
            $query = $this->newQuery();

            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }

            $exists = $query->exists();
            $this->resetQuery();
            return $exists;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        $cacheKey = $this->getCacheKey('count', []);

        $result = $this->remember($cacheKey, function () {
            $count = $this->newQuery()->count();
            $this->resetQuery();
            return $count;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function countWhere(array $criteria): int
    {
        $cacheKey = $this->getCacheKey('countWhere', [$criteria]);

        $result = $this->remember($cacheKey, function () use ($criteria) {
            $query = $this->newQuery();

            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }

            $count = $query->count();
            $this->resetQuery();
            return $count;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function sum(string $column, array $criteria = []): float|int
    {
        $cacheKey = $this->getCacheKey('sum', [$column, $criteria]);

        $result = $this->remember($cacheKey, function () use ($column, $criteria) {
            $query = $this->newQuery();

            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }

            $sum = $query->sum($column);
            $this->resetQuery();
            return $sum ?? 0;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function avg(string $column, array $criteria = []): ?float
    {
        $cacheKey = $this->getCacheKey('avg', [$column, $criteria]);

        $result = $this->remember($cacheKey, function () use ($column, $criteria) {
            $query = $this->newQuery();

            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }

            $avg = $query->avg($column);
            $this->resetQuery();
            return $avg;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function min(string $column, array $criteria = []): mixed
    {
        $cacheKey = $this->getCacheKey('min', [$column, $criteria]);

        $result = $this->remember($cacheKey, function () use ($column, $criteria) {
            $query = $this->newQuery();

            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }

            $min = $query->min($column);
            $this->resetQuery();
            return $min;
        });

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function max(string $column, array $criteria = []): mixed
    {
        $cacheKey = $this->getCacheKey('max', [$column, $criteria]);

        $result = $this->remember($cacheKey, function () use ($column, $criteria) {
            $query = $this->newQuery();

            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }

            $max = $query->max($column);
            $this->resetQuery();
            return $max;
        });

        return $result;
    }

    // =========================================================================
    // QUERY MODIFIERS
    // =========================================================================

    /**
     * {@inheritDoc}
     */
    public function with(array|string $relations): static
    {
        $this->eagerLoad = array_merge(
            $this->eagerLoad,
            is_array($relations) ? $relations : [$relations]
        );
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orderBys[] = [
            'column' => $column,
            'direction' => strtolower($direction) === 'desc' ? 'desc' : 'asc'
        ];
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function limit(int $limit): static
    {
        $this->queryLimit = $limit;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function offset(int $offset): static
    {
        $this->queryOffset = $offset;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function withTrashed(): static
    {
        $this->includeTrashed = true;
        $this->onlyTrashedFlag = false;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function onlyTrashed(): static
    {
        $this->onlyTrashedFlag = true;
        $this->includeTrashed = false;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function scope(Closure $callback): static
    {
        $this->scopes[] = $callback;
        return $this;
    }

    /**
     * Order by latest (descending created_at).
     *
     * @param string $column Column name (default: created_at)
     * @return static
     */
    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Order by oldest (ascending created_at).
     *
     * @param string $column Column name (default: created_at)
     * @return static
     */
    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * {@inheritDoc}
     */
    public function getModelClass(): string
    {
        return $this->model;
    }

    /**
     * {@inheritDoc}
     */
    public function makeModel(array $attributes = []): Model
    {
        return $this->newModelInstance($attributes);
    }

    /**
     * Get underlying query builder.
     *
     * @return ModelQueryBuilder
     */
    public function getQuery(): ModelQueryBuilder
    {
        return $this->newQuery();
    }

    /**
     * Execute raw query and return results.
     *
     * @param Closure $callback Callback receiving query builder
     * @return mixed
     */
    public function raw(Closure $callback): mixed
    {
        $query = $this->newQuery();
        $result = $callback($query);
        $this->resetQuery();
        return $result;
    }

    /**
     * Chunk results for memory-efficient processing.
     *
     * @param int $size Chunk size
     * @param callable $callback Callback for each chunk
     * @return bool
     */
    public function chunk(int $size, callable $callback): bool
    {
        $query = $this->newQuery();
        /** @var bool $result */
        $result = $query->chunk($size, function ($results) use ($callback): bool {
            return $callback($results, $this) !== false;
        });
        $this->resetQuery();
        return $result;
    }

    /**
     * Process each entity in chunks.
     *
     * @param int $size Chunk size
     * @param callable $callback Callback for each entity
     * @return bool
     */
    public function each(int $size, callable $callback): bool
    {
        return $this->chunk($size, function ($results) use ($callback) {
            foreach ($results as $entity) {
                if ($callback($entity, $this) === false) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Get plucked values.
     *
     * @param string $column Column to pluck
     * @param string|null $key Key column (optional)
     * @return array<mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $cacheKey = $this->getCacheKey('pluck', [$column, $key]);

        $result = $this->remember($cacheKey, function () use ($column, $key) {
            $plucked = $this->newQuery()->pluck($column, $key);
            $this->resetQuery();
            // Convert to array if it's a collection
            return $plucked instanceof \Traversable ? iterator_to_array($plucked) : (array) $plucked;
        });

        return $result;
    }

    /**
     * Get random entities.
     *
     * @param int $count Number of random entities
     * @return ModelCollection
     */
    public function random(int $count = 1): ModelCollection
    {
        // Random queries shouldn't be cached
        $this->skipCache();

        $entities = $this->newQuery()
            ->inRandomOrder()
            ->limit($count)
            ->get();

        $this->resetQuery();
        return $entities;
    }
}
