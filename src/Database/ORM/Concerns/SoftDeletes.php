<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Database\Query\QueryBuilder;


/**
 * Trait SoftDeletes
 *
 * Trait providing reusable functionality for SoftDeletes in the Concerns
 * layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait SoftDeletes
{
    /**
     * The name of the "deleted at" column.
     *
     * @var string
     */
    protected static string $deletedAtColumn = 'deleted_at';

    /**
     * Indicates if the model is currently force deleting.
     *
     * @var bool
     */
    protected bool $forceDeleting = false;

    /**
     * Global scopes for soft deletes (independent of HasQueryScopes).
     *
     * @var array<string, callable>
     */
    protected static array $softDeleteGlobalScopes = [];

    /**
     * Boot the soft deletes trait for a model.
     *
     * Automatically adds global scope to exclude soft-deleted records.
     * Works independently or with HasQueryScopes trait.
     *
     * Performance: O(1) - Single scope registration
     *
     * @return void
     */
    protected static function bootSoftDeletes(): void
    {
        // Register soft delete scope
        $scope = function (QueryBuilder $query): void {
            $query->whereNull(static::$deletedAtColumn);
        };

        // If HasQueryScopes is available, use it
        if (method_exists(static::class, 'addGlobalScope')) {
            static::addGlobalScope('softDeletes', $scope);
        } else {
            // Otherwise, store in our own array
            static::$softDeleteGlobalScopes['softDeletes'] = $scope;
        }
    }

    /**
     * Get soft delete global scopes.
     *
     * Used by ModelQueryBuilder to apply scopes even without HasQueryScopes.
     *
     * @return array<string, callable>
     */
    public static function getSoftDeleteGlobalScopes(): array
    {
        return static::$softDeleteGlobalScopes;
    }

    /**
     * Determine if the model instance has been soft-deleted.
     *
     * Performance: O(1) - Direct attribute access
     *
     * @return bool
     */
    public function trashed(): bool
    {
        return $this->getAttribute(static::$deletedAtColumn) !== null;
    }

    /**
     * Soft delete the model.
     *
     * Sets deleted_at timestamp instead of physically deleting.
     *
     * Performance: O(1) - Single UPDATE query
     *
     * @return bool
     */
    public function delete(): bool
    {
        if ($this->forceDeleting) {
            return parent::delete();
        }

        // Soft delete: set deleted_at timestamp
        $deletedAt = now()->toDateTimeString();
        $this->setAttribute(static::$deletedAtColumn, $deletedAt);

        $result = $this->save();

        // Refresh the model to ensure deleted_at is loaded from database
        if ($result && $this->exists) {
            $this->refresh();
        }

        return $result;
    }

    /**
     * Force a hard delete on a soft-deleted model.
     *
     * Physically removes the record from database.
     *
     * Performance: O(1) - Single DELETE query
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        $this->forceDeleting = true;

        $deleted = parent::delete();

        $this->forceDeleting = false;

        return $deleted;
    }

    /**
     * Restore a soft-deleted model instance.
     *
     * Removes deleted_at timestamp to restore the record.
     * Uses atomic UPDATE with WHERE condition to prevent race conditions.
     *
     * SECURITY: Atomic update prevents TOCTOU (Time-of-Check-Time-of-Use) race condition.
     * The check and update happen in a single SQL statement.
     *
     * Performance: O(1) - Single UPDATE query
     *
     * @return bool True if record was restored, false if already restored or not found
     */
    public function restore(): bool
    {
        // Atomic update: only update if record is actually soft-deleted
        // This prevents race condition where another process might have already restored it
        $deletedAt = null;

        $updated = static::withTrashed()
            ->where(static::getPrimaryKey(), $this->getKey())
            ->whereNotNull(static::$deletedAtColumn) // Atomic check: only if still deleted
            ->update([static::$deletedAtColumn => $deletedAt]);

        if ($updated > 0) {
            // Sync local model state after successful restore
            $this->setAttribute(static::$deletedAtColumn, $deletedAt);
            $this->syncOriginal();
            return true;
        }

        // Either record doesn't exist or was already restored by another process
        return false;
    }

    /**
     * Determine if the model is currently force deleting.
     *
     * @return bool
     */
    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    /**
     * Get the name of the "deleted at" column.
     *
     * @return string
     */
    public static function getDeletedAtColumn(): string
    {
        return static::$deletedAtColumn;
    }

    /**
     * Get a new query builder that includes soft-deleted models.
     *
     * Removes the global soft delete scope.
     *
     * Performance: O(1) - Scope removal
     *
     * @return \Toporia\Framework\Database\ORM\ModelQueryBuilder
     */
    public static function withTrashed(): ModelQueryBuilder
    {
        // Always use queryWithoutSoftDeleteScope to avoid static method call issues
        return static::queryWithoutSoftDeleteScope();
    }

    /**
     * Create a query builder without soft delete scope.
     *
     * Internal method used by withTrashed().
     *
     * @return \Toporia\Framework\Database\ORM\ModelQueryBuilder
     */
    protected static function queryWithoutSoftDeleteScope(): ModelQueryBuilder
    {
        // Create ModelQueryBuilder with skipGlobalScopes flag
        $connection = static::getConnection();
        $queryBuilder = new ModelQueryBuilder(
            $connection,
            static::class,
            true // Skip global scopes
        );
        $queryBuilder->table(static::getTableName());

        return $queryBuilder;
    }

    /**
     * Get a new query builder that only includes soft-deleted models.
     *
     * Performance: O(1) - Single WHERE clause
     *
     * @return \Toporia\Framework\Database\ORM\ModelQueryBuilder
     */
    public static function onlyTrashed(): ModelQueryBuilder
    {
        return static::withTrashed()->whereNotNull(static::$deletedAtColumn);
    }

    /**
     * Determine if the model uses soft deletes.
     *
     * @return bool
     */
    public static function usesSoftDeletes(): bool
    {
        return true;
    }

    /**
     * Perform a batch soft delete.
     *
     * Soft deletes multiple records in a single query.
     *
     * Performance: O(1) - Single UPDATE query for all records
     *
     * @param array<int|string> $ids Array of primary key values
     * @return int Number of affected rows
     */
    public static function softDeleteBatch(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $deletedAt = now()->toDateTimeString();
        $primaryKey = static::getPrimaryKey();

        return static::query()
            ->whereIn($primaryKey, $ids)
            ->whereNull(static::$deletedAtColumn)
            ->update([static::$deletedAtColumn => $deletedAt]);
    }

    /**
     * Perform a batch restore.
     *
     * Restores multiple soft-deleted records in a single query.
     *
     * Performance: O(1) - Single UPDATE query for all records
     *
     * @param array<int|string> $ids Array of primary key values
     * @return int Number of affected rows
     */
    public static function restoreBatch(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $primaryKey = static::getPrimaryKey();

        // Use withTrashed() to include soft deleted records
        return static::withTrashed()
            ->whereIn($primaryKey, $ids)
            ->whereNotNull(static::$deletedAtColumn)
            ->update([static::$deletedAtColumn => null]);
    }

    /**
     * Get the primary key column name.
     *
     * @return string
     */
    abstract protected static function getPrimaryKey(): string;

    /**
     * Get a query builder instance.
     *
     * @return QueryBuilder
     */
    abstract public static function query(): QueryBuilder;


    /**
     * Set an attribute value.
     *
     * @param string $key Attribute name
     * @param mixed $value Attribute value
     * @return void
     */
    abstract public function setAttribute(string $key, mixed $value): void;

    /**
     * Get an attribute value.
     *
     * @param string $key Attribute name
     * @return mixed
     */
    abstract public function getAttribute(string $key): mixed;

    /**
     * Save the model to the database.
     *
     * @return bool
     */
    abstract public function save(): bool;
}
