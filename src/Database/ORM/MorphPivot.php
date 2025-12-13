<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM;

/**
 * MorphPivot Model
 *
 * Base class for custom pivot models in polymorphic many-to-many relationships.
 * Extends Pivot with additional support for morph type columns.
 *
 * Usage:
 * ```php
 * class Taggable extends MorphPivot
 * {
 *     protected static string $table = 'taggables';
 *
 *     public function isHighlighted(): bool
 *     {
 *         return (bool) $this->is_highlighted;
 *     }
 * }
 *
 * // In Post model:
 * public function tags(): MorphToMany
 * {
 *     return $this->morphToMany(Tag::class, 'taggable')
 *                 ->using(Taggable::class)
 *                 ->withPivot(['is_highlighted']);
 * }
 * ```
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  ORM
 */
class MorphPivot extends Pivot
{
    /**
     * The type of the polymorphic relation.
     *
     * This property stores the class name of the related model
     * for polymorphic relationships (e.g., 'App\Models\Post').
     */
    protected string $morphType = '';

    /**
     * The value of the polymorphic relation type column.
     *
     * This is the actual value stored in the database column
     * (e.g., 'posts', 'App\Models\Post').
     */
    protected string $morphClass = '';

    /**
     * Set the morph type for the pivot.
     *
     * @param string $morphType The morph type column name (e.g., 'taggable_type')
     * @return static
     */
    public function setMorphType(string $morphType): static
    {
        $this->morphType = $morphType;
        return $this;
    }

    /**
     * Get the morph type column name.
     *
     * @return string
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Set the morph class for the pivot.
     *
     * @param string $morphClass The morph class value (e.g., 'App\Models\Post')
     * @return static
     */
    public function setMorphClass(string $morphClass): static
    {
        $this->morphClass = $morphClass;
        return $this;
    }

    /**
     * Get the morph class value.
     *
     * @return string
     */
    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    /**
     * Set the keys for a save/update query.
     *
     * Override to include morph type in the query constraints.
     *
     * @param \Toporia\Framework\Database\Query\QueryBuilder $query
     * @return \Toporia\Framework\Database\Query\QueryBuilder
     */
    protected function setKeysForSaveQuery($query)
    {
        // First apply parent keys (foreign key and related key)
        parent::setKeysForSaveQuery($query);

        // Then add morph type constraint if set
        if ($this->morphType !== '' && $this->morphClass !== '') {
            $query->where($this->morphType, $this->morphClass);
        }

        return $query;
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

        // Fire deleting event (can cancel)
        if ($this->fireEvent('deleting') === false) {
            return false;
        }

        $query = static::query();
        $this->setKeysForSaveQuery($query);
        $query->delete();

        parent::setExists(false);

        $this->fireEvent('deleted');

        return true;
    }

    /**
     * Get the queueable identity for the entity.
     *
     * @return mixed
     */
    public function getQueueableId(): mixed
    {
        $id = parent::getQueueableId();

        if (is_array($id) && $this->morphType !== '') {
            $id[$this->morphType] = $this->morphClass;
        }

        return $id;
    }

    /**
     * Create a new morph pivot model from raw attributes.
     *
     * @param Model $parent The parent model
     * @param array<string, mixed> $attributes
     * @param string $table
     * @param bool $exists
     * @param string|null $morphType The morph type column name
     * @param string|null $morphClass The morph class value
     * @return static
     */
    public static function fromMorphAttributes(
        Model $parent,
        array $attributes,
        string $table,
        bool $exists = false,
        ?string $morphType = null,
        ?string $morphClass = null
    ): static {
        $instance = static::fromRawAttributes($parent, $attributes, $table, $exists);

        if ($morphType !== null) {
            $instance->setMorphType($morphType);
        }

        if ($morphClass !== null) {
            $instance->setMorphClass($morphClass);
        }

        return $instance;
    }
}
