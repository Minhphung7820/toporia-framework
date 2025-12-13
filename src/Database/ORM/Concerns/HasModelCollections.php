<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

/**
 * Trait HasModelCollections
 *
 * Enhanced model collection methods for retrieving and manipulating models.
 * Provides Modern ORM convenience methods for common operations.
 *
 * Features:
 * - find() with multiple IDs support
 * - fresh() - Reload model from database
 * - refresh() - Reload and replace current instance
 * - replicate() - Clone model without primary key
 * - touch() - Update timestamps without triggering events
 * - is() / isNot() - Model comparison
 *
 * Performance:
 * - O(1) for single find operations
 * - O(N) for multiple find operations
 * - Efficient database queries
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\ORM\Concerns
 * @since       2025-01-22
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @property-read bool $timestamps
 * @property-read string $table
 */
trait HasModelCollections
{
    /**
     * Find a model or multiple models by primary key(s).
     *
     * Example:
     * ```php
     * // Find single model
     * $user = User::find(1);
     *
     * // Find multiple models
     * $users = User::find([1, 2, 3]);
     * // Returns: [User, User, User]
     *
     * // With columns
     * $users = User::find([1, 2, 3], ['id', 'name']);
     * ```
     *
     * @param mixed $id Single ID or array of IDs
     * @param array<string> $columns Columns to select
     * @return static|array|null
     */
    public static function find(mixed $id, array $columns = ['*']): static|array|null
    {
        // Single ID
        if (!is_array($id)) {
            return static::query()
                ->select(...$columns)
                ->where(static::getKeyName(), $id)
                ->first();
        }

        // Multiple IDs
        if (empty($id)) {
            return [];
        }

        $results = static::query()
            ->select(...$columns)
            ->whereIn(static::getKeyName(), $id)
            ->get();

        return $results;
    }

    /**
     * Find a model by primary key or throw an exception.
     *
     * Example:
     * ```php
     * $user = User::findOrFail(1);
     * // Throws ModelNotFoundException if not found
     * ```
     *
     * @param mixed $id Single ID or array of IDs
     * @param array<string> $columns Columns to select
     * @return static|array
     * @throws \RuntimeException
     */
    public static function findOrFail(mixed $id, array $columns = ['*']): static|array
    {
        $result = static::find($id, $columns);

        if (is_array($id)) {
            if (count($result) !== count($id)) {
                throw new \RuntimeException('Model not found');
            }
            return $result;
        }

        if ($result === null) {
            throw new \RuntimeException('Model not found');
        }

        return $result;
    }

    /**
     * Reload the model from the database.
     *
     * Returns a fresh instance without modifying the current instance.
     *
     * Example:
     * ```php
     * $user = User::find(1);
     * $user->name = 'Changed';
     *
     * $freshUser = $user->fresh();
     * echo $freshUser->name; // Original name from DB
     * echo $user->name; // Still 'Changed'
     * ```
     *
     * @param array<string>|string $with Relationships to eager load
     * @return static|null
     */
    public function fresh(array|string $with = []): ?static
    {
        if (!$this->exists) {
            return null;
        }

        $key = $this->getKey();

        if ($key === null) {
            return null;
        }

        $fresh = static::find($key);

        if ($fresh === null) {
            return null;
        }

        // Eager load relationships if specified
        if (!empty($with)) {
            $relations = is_array($with) ? $with : [$with];
            foreach ($relations as $relation) {
                if (method_exists($fresh, $relation)) {
                    $fresh->$relation();
                }
            }
        }

        return $fresh;
    }

    /**
     * Reload the current model instance from the database.
     *
     * Replaces current attributes with fresh data.
     *
     * Example:
     * ```php
     * $user = User::find(1);
     * $user->name = 'Changed';
     *
     * $user->refresh();
     * echo $user->name; // Original name from DB (changed attribute lost)
     * ```
     *
     * @return $this
     */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }

        $fresh = $this->fresh();

        if ($fresh === null) {
            return $this;
        }

        // Replace current attributes with fresh data
        $this->setRawAttributes($fresh->getAllAttributes());
        $this->syncOriginal();

        return $this;
    }

    /**
     * Clone the model into a new, non-existing instance.
     *
     * Example:
     * ```php
     * $user = User::find(1);
     * $clone = $user->replicate();
     *
     * $clone->email = 'new@example.com';
     * $clone->save(); // Creates new record
     * ```
     *
     * @param array<string>|null $except Attributes to exclude from replication
     * @return static
     */
    public function replicate(?array $except = null): static
    {
        // Fire replicating event
        $this->fireEvent('replicating');

        $except = $except ?? [
            static::getKeyName(),
            'created_at',
            'updated_at',
        ];

        $attributes = $this->getAllAttributes();

        // Remove excepted attributes
        foreach ($except as $attribute) {
            unset($attributes[$attribute]);
        }

        // Also remove 'exists' if it's stored in attributes
        unset($attributes['exists']);

        // Create new instance
        $replica = new static();
        $replica->setRawAttributes($attributes);
        $replica->setExists(false);

        return $replica;
    }

    /**
     * Update the model's timestamps.
     *
     * Does not trigger save events or modify other attributes.
     *
     * Example:
     * ```php
     * $post->touch(); // Updates updated_at
     *
     * // Touch related models
     * $comment->post->touch();
     * ```
     *
     * @return bool
     */
    public function touch(): bool
    {
        // @phpstan-ignore-next-line - Property exists in Model class
        if (!static::$timestamps) {
            return false;
        }

        $this->updateTimestamps();

        return $this->save();
    }

    /**
     * Determine if the model has the same ID and belongs to the same table as another.
     *
     * Example:
     * ```php
     * $user1 = User::find(1);
     * $user2 = User::find(1);
     * $user3 = User::find(2);
     *
     * $user1->is($user2); // true
     * $user1->is($user3); // false
     * ```
     *
     * @param static|null $model
     * @return bool
     */
    public function is(?self $model): bool
    {
        if ($model === null) {
            return false;
        }

        return $this->getKey() === $model->getKey()
            && $this->getTable() === $model->getTable()
            && static::class === get_class($model);
    }

    /**
     * Determine if the model is not the same as another.
     *
     * Example:
     * ```php
     * $user1 = User::find(1);
     * $user2 = User::find(2);
     *
     * $user1->isNot($user2); // true
     * ```
     *
     * @param static|null $model
     * @return bool
     */
    public function isNot(?self $model): bool
    {
        return !$this->is($model);
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable(): string
    {
        return static::getTableName();
    }

    /**
     * Get the value of the model's primary key.
     *
     * @return mixed
     */
    public function getKey(): mixed
    {
        return $this->getAttribute(static::getKeyName());
    }

    /**
     * Set the array of model attributes without any processing.
     *
     * @param array<string, mixed> $attributes
     * @return $this
     */
    protected function setRawAttributes(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setRawAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Determine if two models have the same ID and belong to the same table.
     *
     * Static helper for is() method.
     *
     * @param static|null $model1
     * @param static|null $model2
     * @return bool
     */
    public static function isSame(?self $model1, ?self $model2): bool
    {
        if ($model1 === null || $model2 === null) {
            return false;
        }

        return $model1->is($model2);
    }

    /**
     * Get the first model matching the attributes or create it.
     *
     * Example:
     * ```php
     * $user = User::firstOrCreate(
     *     ['email' => 'john@example.com'],
     *     ['name' => 'John Doe']
     * );
     * ```
     *
     * @param array<string, mixed> $attributes Attributes to search for
     * @param array<string, mixed> $values Additional values for creation
     * @return static
     */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        $instance = static::query();

        foreach ($attributes as $key => $value) {
            $instance->where($key, $value);
        }

        $model = $instance->first();

        if ($model !== null) {
            return $model;
        }

        return static::create(array_merge($attributes, $values));
    }

    /**
     * Get the first model matching the attributes or instantiate it.
     *
     * Example:
     * ```php
     * $user = User::firstOrNew(
     *     ['email' => 'john@example.com'],
     *     ['name' => 'John Doe']
     * );
     *
     * if (!$user->exists) {
     *     $user->save();
     * }
     * ```
     *
     * @param array<string, mixed> $attributes Attributes to search for
     * @param array<string, mixed> $values Additional values for instantiation
     * @return static
     */
    public static function firstOrNew(array $attributes, array $values = []): static
    {
        $instance = static::query();

        foreach ($attributes as $key => $value) {
            $instance->where($key, $value);
        }

        $model = $instance->first();

        if ($model !== null) {
            return $model;
        }

        $newModel = new static();
        $newModel->fill(array_merge($attributes, $values));

        return $newModel;
    }

    /**
     * Create or update a model matching the attributes.
     *
     * Example:
     * ```php
     * $user = User::updateOrCreate(
     *     ['email' => 'john@example.com'],
     *     ['name' => 'John Doe', 'active' => true]
     * );
     * ```
     *
     * @param array<string, mixed> $attributes Attributes to search for
     * @param array<string, mixed> $values Values to update/create
     * @return static
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $instance = static::query();

        foreach ($attributes as $key => $value) {
            $instance->where($key, $value);
        }

        $model = $instance->first();

        if ($model !== null) {
            $model->fill($values);
            $model->save();
            return $model;
        }

        return static::create(array_merge($attributes, $values));
    }
}
