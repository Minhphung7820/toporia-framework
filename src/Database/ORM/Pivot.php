<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM;

/**
 * Pivot Model
 *
 * Base class for custom pivot models in many-to-many relationships.
 * Extends Model to provide full ORM capabilities including:
 * - Accessors/Mutators
 * - Custom methods
 * - Relationships from pivot
 * - Event hooks
 * - Save/update/delete operations
 *
 * Usage:
 * ```php
 * class RoleUser extends Pivot
 * {
 *     protected static string $table = 'role_user';
 *
 *     public function isPrimary(): bool
 *     {
 *         return (bool) $this->is_primary;
 *     }
 *
 *     // Accessor example
 *     public function getExpiresAtAttribute($value): ?Carbon
 *     {
 *         return $value ? Carbon::parse($value) : null;
 *     }
 * }
 *
 * // In User model:
 * public function roles(): BelongsToMany
 * {
 *     return $this->belongsToMany(Role::class)
 *                 ->using(RoleUser::class)
 *                 ->withPivot(['is_primary'])
 *                 ->withTimestamps();
 * }
 * ```
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  ORM
 */
class Pivot extends Model
{
    /**
     * The parent model of the relationship.
     */
    protected ?Model $pivotParent = null;

    /**
     * The name of the foreign key column for the parent model.
     */
    protected string $foreignKey = '';

    /**
     * The name of the "other key" column for the related model.
     */
    protected string $relatedKey = '';

    /**
     * Indicates if the model should be timestamped.
     * Default to false for pivot tables.
     */
    protected static bool $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     * Pivot models are generally more permissive.
     */
    protected static array $guarded = [];

    /**
     * Create a new pivot model instance.
     *
     * @param array<string, mixed> $attributes
     * @param string $table Optional table name
     * @param bool $exists Whether the record exists in database
     */
    public function __construct(array $attributes = [], string $table = '', bool $exists = false)
    {
        // Set table before parent constructor if provided
        if ($table !== '') {
            static::$table = $table;
        }

        parent::__construct($attributes);

        // Set exists status using parent's protected method
        parent::setExists($exists);
    }

    /**
     * Create a new pivot model from raw attributes.
     *
     * @param Model $parent The parent model
     * @param array<string, mixed> $attributes
     * @param string $table
     * @param bool $exists
     * @return static
     */
    public static function fromAttributes(Model $parent, array $attributes, string $table, bool $exists = false): static
    {
        $instance = new static($attributes, $table, $exists);
        $instance->setPivotParent($parent);

        return $instance;
    }

    /**
     * Create a new pivot model from raw attributes without triggering mutators.
     *
     * @param Model $parent
     * @param array<string, mixed> $attributes
     * @param string $table
     * @param bool $exists
     * @return static
     */
    public static function fromRawAttributes(Model $parent, array $attributes, string $table, bool $exists = false): static
    {
        $instance = new static([], $table, $exists);
        $instance->setPivotParent($parent);

        // Set attributes directly using parent's protected method
        foreach ($attributes as $key => $value) {
            $instance->setAttribute($key, $value);
        }

        $instance->syncOriginal();

        return $instance;
    }

    /**
     * Delete the pivot model record from the database.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->exists()) {
            return false;
        }

        $query = static::query();

        // Build where clause using foreign and related keys
        if ($this->foreignKey !== '' && $this->relatedKey !== '') {
            $query->where($this->foreignKey, $this->getAttribute($this->foreignKey));
            $query->where($this->relatedKey, $this->getAttribute($this->relatedKey));
        } elseif ($this->getKey() !== null) {
            $query->where(static::$primaryKey, $this->getKey());
        }

        $query->delete();

        parent::setExists(false);

        return true;
    }

    /**
     * Set the pivot parent model.
     *
     * @param Model $parent
     * @return static
     */
    public function setPivotParent(Model $parent): static
    {
        $this->pivotParent = $parent;
        return $this;
    }

    /**
     * Get the pivot parent model.
     *
     * @return Model|null
     */
    public function getPivotParent(): ?Model
    {
        return $this->pivotParent;
    }

    /**
     * Set the foreign key column name.
     *
     * @param string $foreignKey
     * @return static
     */
    public function setForeignKey(string $foreignKey): static
    {
        $this->foreignKey = $foreignKey;
        return $this;
    }

    /**
     * Get the foreign key column name.
     *
     * @return string
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Set the "other key" column name.
     *
     * @param string $relatedKey
     * @return static
     */
    public function setRelatedKey(string $relatedKey): static
    {
        $this->relatedKey = $relatedKey;
        return $this;
    }

    /**
     * Get the "other key" column name.
     *
     * @return string
     */
    public function getRelatedKey(): string
    {
        return $this->relatedKey;
    }

    /**
     * Determine if the pivot model exists.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return parent::exists();
    }

    /**
     * Get the queueable identity for the entity.
     *
     * @return mixed
     */
    public function getQueueableId(): mixed
    {
        if ($this->foreignKey !== '' && $this->relatedKey !== '') {
            return [
                $this->foreignKey => $this->getAttribute($this->foreignKey),
                $this->relatedKey => $this->getAttribute($this->relatedKey),
            ];
        }

        return $this->getKey();
    }

    /**
     * Unset timestamps on the model.
     *
     * @return static
     */
    public function withoutTimestamps(): static
    {
        static::$timestamps = false;
        return $this;
    }

    /**
     * Enable timestamps on the model.
     *
     * @return static
     */
    public function withTimestamps(): static
    {
        static::$timestamps = true;
        return $this;
    }

}
