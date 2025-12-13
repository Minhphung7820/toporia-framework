<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

use Toporia\Framework\Database\ORM\{Model, ModelQueryBuilder};
use Toporia\Framework\Database\ORM\Relations\{
    HasOne,
    HasMany,
    BelongsTo,
    BelongsToMany,
    HasOneThrough,
    HasManyThrough,
    MorphOne,
    MorphMany,
    MorphToMany,
    MorphedByMany,
    MorphTo
};

/**
 * Trait HasRelationships
 *
 * Provides relationship definition methods for ORM models.
 * Follows Toporia's pattern for defining model relationships.
 *
 * Supported Relationships:
 * - HasOne: One-to-one (User has one Profile)
 * - HasMany: One-to-many (Post has many Comments)
 * - BelongsTo: Inverse one-to-one/many (Comment belongs to Post)
 * - BelongsToMany: Many-to-many (Post has many Tags through pivot)
 * - HasOneThrough: Has-one through intermediate (Country has one Phone through User)
 * - HasManyThrough: Has-many through intermediate (Country has many Posts through Users)
 * - MorphOne: Polymorphic one-to-one (Post has one Image as imageable)
 * - MorphMany: Polymorphic one-to-many (Post has many Comments as commentable)
 * - MorphToMany: Polymorphic many-to-many (Post has many Tags as taggable)
 * - MorphedByMany: Inverse polymorphic many-to-many (Tag is tagged by many Posts)
 * - MorphTo: Polymorphic inverse (Image belongs to Post/Video as imageable)
 *
 * SOLID Principles:
 * - Single Responsibility: Only handles relationship definitions
 * - Open/Closed: New relationship types can be added without modifying existing code
 * - Interface Segregation: Each relationship type has its own class
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
trait HasRelationships
{
    // =========================================================================
    // ONE-TO-ONE RELATIONSHIPS
    // =========================================================================

    /**
     * Define a one-to-one relationship.
     *
     * @param class-string<Model> $related Related model class
     * @param string|null $foreignKey Foreign key on related table (default: {parent}_id)
     * @param string|null $localKey Local key on parent table (default: id)
     * @return HasOne
     *
     * @example
     * ```php
     * // User has one Profile
     * public function profile(): HasOne
     * {
     *     return $this->hasOne(Profile::class);
     *     // SQL: SELECT * FROM profiles WHERE user_id = ?
     * }
     * ```
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey ??= $this->getForeignKey();
        $localKey ??= static::getPrimaryKey();

        $query = $related::query();

        return new HasOne($query, $this, $related, $foreignKey, $localKey);
    }

    // =========================================================================
    // ONE-TO-MANY RELATIONSHIPS
    // =========================================================================

    /**
     * Define a one-to-many relationship.
     *
     * @param class-string<Model> $related Related model class
     * @param string|null $foreignKey Foreign key on related table (default: {parent}_id)
     * @param string|null $localKey Local key on parent table (default: id)
     * @return HasMany
     *
     * @example
     * ```php
     * // Post has many Comments
     * public function comments(): HasMany
     * {
     *     return $this->hasMany(Comment::class);
     *     // SQL: SELECT * FROM comments WHERE post_id = ?
     * }
     * ```
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey ??= $this->getForeignKey();
        $localKey ??= static::getPrimaryKey();

        $query = $related::query();

        return new HasMany($query, $this, $related, $foreignKey, $localKey);
    }

    // =========================================================================
    // INVERSE RELATIONSHIPS
    // =========================================================================

    /**
     * Define an inverse one-to-one or one-to-many relationship.
     *
     * @param class-string<Model> $related Related model class
     * @param string|null $foreignKey Foreign key on current table (default: {related}_id)
     * @param string|null $ownerKey Primary key on related table (default: id)
     * @return BelongsTo
     *
     * @example
     * ```php
     * // Comment belongs to Post
     * public function post(): BelongsTo
     * {
     *     return $this->belongsTo(Post::class);
     *     // SQL: SELECT * FROM posts WHERE id = ?
     * }
     * ```
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $foreignKey ??= $this->guessBelongsToForeignKey($related);
        $ownerKey ??= $related::getPrimaryKey();

        $query = $related::query();

        return new BelongsTo($query, $this, $related, $foreignKey, $ownerKey);
    }

    // =========================================================================
    // MANY-TO-MANY RELATIONSHIPS
    // =========================================================================

    /**
     * Define a many-to-many relationship.
     *
     * @param class-string<Model> $related Related model class
     * @param string|null $pivotTable Pivot table name
     * @param string|null $foreignPivotKey Foreign key in pivot for parent
     * @param string|null $relatedPivotKey Foreign key in pivot for related
     * @param string|null $parentKey Parent primary key
     * @param string|null $relatedKey Related primary key
     * @return BelongsToMany
     *
     * @example
     * ```php
     * // Post has many Tags (through post_tag pivot table)
     * public function tags(): BelongsToMany
     * {
     *     return $this->belongsToMany(Tag::class);
     *     // SQL: SELECT tags.*, post_tag.* FROM tags
     *     //      INNER JOIN post_tag ON tags.id = post_tag.tag_id
     *     //      WHERE post_tag.post_id = ?
     * }
     *
     * // With pivot data
     * public function tags(): BelongsToMany
     * {
     *     return $this->belongsToMany(Tag::class)
     *                 ->withPivot('created_at', 'order')
     *                 ->withTimestamps();
     * }
     * ```
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): BelongsToMany {
        $foreignPivotKey ??= $this->getForeignKey();
        $relatedPivotKey ??= $this->getRelatedForeignKey($related);
        $parentKey ??= static::getPrimaryKey();
        $relatedKey ??= $related::getPrimaryKey();

        $query = $related::query();

        return new BelongsToMany(
            $query,
            $this,
            $related,
            $pivotTable ?? $this->guessPivotTable($related),
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey
        );
    }

    // =========================================================================
    // HAS-THROUGH RELATIONSHIPS
    // =========================================================================

    /**
     * Define a has-one-through relationship.
     *
     * Example: Country → User → Phone
     * Country::hasOneThrough(Phone::class, User::class)
     *
     * @param class-string<Model> $related Related model class (Phone)
     * @param class-string<Model> $through Through model class (User)
     * @param string|null $firstKey Foreign key on through table (users.country_id)
     * @param string|null $secondKey Foreign key on related table (phones.user_id)
     * @param string|null $localKey Local key on parent table (countries.id)
     * @param string|null $secondLocalKey Local key on through table (users.id)
     * @return HasOneThrough
     *
     * @example
     * ```php
     * // Country has one Phone through User
     * public function phone(): HasOneThrough
     * {
     *     return $this->hasOneThrough(Phone::class, User::class);
     *     // SQL: SELECT phones.* FROM phones
     *     //      INNER JOIN users ON users.id = phones.user_id
     *     //      WHERE users.country_id = ?
     * }
     * ```
     */
    protected function hasOneThrough(
        string $related,
        string $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null
    ): HasOneThrough {
        $firstKey ??= $this->getForeignKey();
        $secondKey ??= $this->getRelatedForeignKey($through);
        $localKey ??= static::getPrimaryKey();
        $secondLocalKey ??= $through::getPrimaryKey();

        $query = $related::query();

        return new HasOneThrough(
            $query,
            $this,
            $related,
            $through,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocalKey
        );
    }

    /**
     * Define a has-many-through relationship.
     *
     * Example: Country → Users → Posts
     * Country::hasManyThrough(Post::class, User::class)
     *
     * @param class-string<Model> $related Related model class (Post)
     * @param class-string<Model> $through Through model class (User)
     * @param string|null $firstKey Foreign key on through table (users.country_id)
     * @param string|null $secondKey Foreign key on related table (posts.user_id)
     * @param string|null $localKey Local key on parent table (countries.id)
     * @param string|null $secondLocalKey Local key on through table (users.id)
     * @return HasManyThrough
     *
     * @example
     * ```php
     * // Country has many Posts through Users
     * public function posts(): HasManyThrough
     * {
     *     return $this->hasManyThrough(Post::class, User::class);
     *     // SQL: SELECT posts.* FROM posts
     *     //      INNER JOIN users ON users.id = posts.user_id
     *     //      WHERE users.country_id = ?
     * }
     * ```
     */
    protected function hasManyThrough(
        string $related,
        string $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null
    ): HasManyThrough {
        $firstKey ??= $this->getForeignKey();
        $secondKey ??= $this->getRelatedForeignKey($through);
        $localKey ??= static::getPrimaryKey();
        $secondLocalKey ??= $through::getPrimaryKey();

        $query = $related::query();

        return new HasManyThrough(
            $query,
            $this,
            $related,
            $through,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocalKey
        );
    }

    // =========================================================================
    // POLYMORPHIC RELATIONSHIPS
    // =========================================================================

    /**
     * Define a polymorphic one-to-one relationship.
     *
     * Example: Post/Video → Image
     * Post::morphOne(Image::class, 'imageable')
     *
     * @param class-string<Model> $related Related model class (Image)
     * @param string $morphName Morph name ('imageable')
     * @param string|null $morphType Type column (imageable_type)
     * @param string|null $morphId ID column (imageable_id)
     * @param string|null $localKey Local key (id)
     * @return MorphOne
     *
     * @example
     * ```php
     * // Post has one Image (polymorphic)
     * public function image(): MorphOne
     * {
     *     return $this->morphOne(Image::class, 'imageable');
     *     // SQL: SELECT * FROM images
     *     //      WHERE imageable_type = 'App\Models\Post'
     *     //      AND imageable_id = ?
     * }
     * ```
     */
    protected function morphOne(
        string $related,
        string $morphName,
        ?string $morphType = null,
        ?string $morphId = null,
        ?string $localKey = null
    ): MorphOne {
        $query = $related::query();

        return new MorphOne(
            $query,
            $this,
            $related,
            $morphName,
            $morphType,
            $morphId,
            $localKey
        );
    }

    /**
     * Define a polymorphic one-to-many relationship.
     *
     * Example: Post/Video → Comments
     * Post::morphMany(Comment::class, 'commentable')
     *
     * @param class-string<Model> $related Related model class (Comment)
     * @param string $morphName Morph name ('commentable')
     * @param string|null $morphType Type column (commentable_type)
     * @param string|null $morphId ID column (commentable_id)
     * @param string|null $localKey Local key (id)
     * @return MorphMany
     *
     * @example
     * ```php
     * // Post has many Comments (polymorphic)
     * public function comments(): MorphMany
     * {
     *     return $this->morphMany(Comment::class, 'commentable');
     *     // SQL: SELECT * FROM comments
     *     //      WHERE commentable_type = 'App\Models\Post'
     *     //      AND commentable_id = ?
     * }
     * ```
     */
    protected function morphMany(
        string $related,
        string $morphName,
        ?string $morphType = null,
        ?string $morphId = null,
        ?string $localKey = null
    ): MorphMany {
        $query = $related::query();

        return new MorphMany(
            $query,
            $this,
            $related,
            $morphName,
            $morphType,
            $morphId,
            $localKey
        );
    }

    /**
     * Define a polymorphic many-to-many relationship.
     *
     * Example: Post/Video ↔ Tags
     * Post::morphToMany(Tag::class, 'taggable')
     *
     * @param class-string<Model> $related Related model class (Tag)
     * @param string $morphName Morph name ('taggable')
     * @param string|null $pivotTable Pivot table (taggables)
     * @param string|null $morphType Type column (taggable_type)
     * @param string|null $morphId ID column (taggable_id)
     * @param string|null $relatedKey Related key (tag_id)
     * @param string|null $parentKey Parent key (id)
     * @param string|null $relatedPrimaryKey Related primary key (id)
     * @return MorphToMany
     *
     * @example
     * ```php
     * // Post has many Tags (polymorphic many-to-many)
     * public function tags(): MorphToMany
     * {
     *     return $this->morphToMany(Tag::class, 'taggable');
     *     // SQL: SELECT tags.*, taggables.* FROM tags
     *     //      INNER JOIN taggables ON tags.id = taggables.tag_id
     *     //      WHERE taggables.taggable_type = 'App\Models\Post'
     *     //      AND taggables.taggable_id = ?
     * }
     * ```
     */
    protected function morphToMany(
        string $related,
        string $morphName,
        ?string $pivotTable = null,
        ?string $morphType = null,
        ?string $morphId = null,
        ?string $relatedKey = null,
        ?string $parentKey = null,
        ?string $relatedPrimaryKey = null
    ): MorphToMany {
        $query = $related::query();

        return new MorphToMany(
            $query,
            $this,
            $related,
            $morphName,
            $pivotTable,
            $morphType,
            $morphId,
            $relatedKey,
            $parentKey,
            $relatedPrimaryKey
        );
    }

    /**
     * Define an inverse polymorphic many-to-many relationship.
     *
     * This is the inverse of morphToMany. While morphToMany defines the relationship
     * from the morphable model (Post/Video) to the related model (Tag), morphedByMany
     * defines the relationship from the related model (Tag) back to the morphable models.
     *
     * Example: Tag → Post/Video (Tag is tagged by many Posts or Videos)
     * Tag::morphedByMany(Post::class, 'taggable')
     *
     * Pivot table structure (taggables):
     * - taggable_type: The class name of the morph model (Post, Video)
     * - taggable_id: The ID of the morph model
     * - tag_id: The ID of this model (Tag)
     *
     * @param class-string<Model> $related Related model class (Post)
     * @param string $morphName Morph name ('taggable')
     * @param string|null $pivotTable Pivot table (taggables)
     * @param string|null $morphType Type column (taggable_type)
     * @param string|null $morphId ID column (taggable_id)
     * @param string|null $parentKey Parent key in pivot (tag_id)
     * @param string|null $localKey Local key (id)
     * @param string|null $relatedPrimaryKey Related primary key (id)
     * @return MorphedByMany
     *
     * @example
     * ```php
     * // Tag is tagged by many Posts (inverse polymorphic many-to-many)
     * public function posts(): MorphedByMany
     * {
     *     return $this->morphedByMany(Post::class, 'taggable');
     *     // SQL: SELECT posts.*, taggables.* FROM posts
     *     //      INNER JOIN taggables ON posts.id = taggables.taggable_id
     *     //      WHERE taggables.taggable_type = 'App\Models\Post'
     *     //      AND taggables.tag_id = ?
     * }
     *
     * // Tag is tagged by many Videos
     * public function videos(): MorphedByMany
     * {
     *     return $this->morphedByMany(Video::class, 'taggable');
     * }
     * ```
     */
    protected function morphedByMany(
        string $related,
        string $morphName,
        ?string $pivotTable = null,
        ?string $morphType = null,
        ?string $morphId = null,
        ?string $parentKey = null,
        ?string $localKey = null,
        ?string $relatedPrimaryKey = null
    ): MorphedByMany {
        $query = $related::query();

        return new MorphedByMany(
            $query,
            $this,
            $related,
            $morphName,
            $pivotTable,
            $morphType,
            $morphId,
            $parentKey,
            $localKey,
            $relatedPrimaryKey
        );
    }

    /**
     * Define a polymorphic inverse relationship.
     *
     * Example: Comment → Post/Video
     * Comment::morphTo('commentable')
     *
     * @param string $morphName Morph name ('commentable')
     * @param string|null $morphType Type column (commentable_type)
     * @param string|null $morphId ID column (commentable_id)
     * @param string|null $ownerKey Owner key (id)
     * @return MorphTo
     *
     * @example
     * ```php
     * // Comment belongs to Post or Video (polymorphic inverse)
     * public function commentable(): MorphTo
     * {
     *     return $this->morphTo('commentable');
     *     // Dynamically resolves to Post or Video based on commentable_type
     * }
     * ```
     */
    protected function morphTo(
        string $morphName,
        ?string $morphType = null,
        ?string $morphId = null,
        ?string $ownerKey = null
    ): MorphTo {
        // MorphTo doesn't need a specific query - it will be created dynamically
        $query = static::query();

        return new MorphTo(
            $query,
            $this,
            $morphName,
            $morphType,
            $morphId,
            $ownerKey
        );
    }

    // =========================================================================
    // FOREIGN KEY HELPERS
    // =========================================================================

    /**
     * Get the default foreign key name for this model.
     *
     * @return string
     *
     * @example
     * ```php
     * // For User model -> 'user_id'
     * // For ProductCategory model -> 'productcategory_id'
     * ```
     */
    protected function getForeignKey(): string
    {
        $parts = explode('\\', static::class);
        $className = end($parts);

        return strtolower($className) . '_id';
    }

    /**
     * Get the foreign key name for a related model.
     *
     * @param class-string<Model> $related
     * @return string
     */
    protected function getRelatedForeignKey(string $related): string
    {
        $parts = explode('\\', $related);
        $className = end($parts);

        return strtolower($className) . '_id';
    }

    /**
     * Guess the belongs to foreign key.
     *
     * @param class-string<Model> $related
     * @return string
     */
    protected function guessBelongsToForeignKey(string $related): string
    {
        return $this->getRelatedForeignKey($related);
    }

    /**
     * Guess the pivot table name for a many-to-many relationship.
     *
     * The pivot table name is generated by joining the two model names
     * in alphabetical order with an underscore.
     *
     * @param class-string<Model> $related
     * @return string
     *
     * @example
     * ```php
     * // Post <-> Tag = 'post_tag'
     * // Role <-> User = 'role_user'
     * ```
     */
    protected function guessPivotTable(string $related): string
    {
        $models = [
            strtolower(basename(str_replace('\\', '/', static::class))),
            strtolower(basename(str_replace('\\', '/', $related)))
        ];

        sort($models);

        return implode('_', $models);
    }

    // =========================================================================
    // STATIC EAGER LOADING QUERY METHODS
    // =========================================================================

    /**
     * Static eager loading for query results.
     *
     * Supports multiple formats:
     * 1. String: with('childrens')
     * 2. Array: with(['childrens', 'category'])
     * 3. Array with column selection: with(['childrens:id,title', 'category:id,name'])
     * 4. Mixed varargs: with('childrens', 'category')
     *
     * @param string|array<string>|callable ...$relations Relationship name(s)
     * @return ModelQueryBuilder
     *
     * @example
     * ```php
     * // Single relationship
     * Product::with('childrens')->get()
     *
     * // Multiple relationships
     * Product::with(['childrens', 'category'])->get()
     *
     * // With column selection (optimize queries)
     * Product::with(['childrens:id,title,parent_id'])->get()
     *
     * // Varargs style
     * Product::with('childrens', 'category')->get()
     * ```
     */
    public static function with(string|array|callable ...$relations): ModelQueryBuilder
    {
        $normalized = static::normalizeWithRelations($relations);

        $query = static::query();
        $query->setEagerLoad($normalized);

        return $query;
    }

    /**
     * Add a subselect count of a relationship to the query.
     *
     * @param string|array $relations Relationship name(s) or associative array with callbacks
     * @return ModelQueryBuilder
     */
    public static function withCount(string|array $relations): ModelQueryBuilder
    {
        return static::query()->withCount($relations);
    }

    /**
     * Add a subselect sum of a relationship column to the query.
     *
     * @param string $relation Relationship name
     * @param string $column Column to sum
     * @param callable|null $callback Optional callback to constrain the sum
     * @return ModelQueryBuilder
     */
    public static function withSum(string $relation, string $column, ?callable $callback = null): ModelQueryBuilder
    {
        return static::query()->withSum($relation, $column, $callback);
    }

    /**
     * Add a subselect average of a relationship column to the query.
     *
     * @param string $relation Relationship name
     * @param string $column Column to average
     * @param callable|null $callback Optional callback to constrain the average
     * @return ModelQueryBuilder
     */
    public static function withAvg(string $relation, string $column, ?callable $callback = null): ModelQueryBuilder
    {
        return static::query()->withAvg($relation, $column, $callback);
    }

    /**
     * Add a subselect minimum of a relationship column to the query.
     *
     * @param string $relation Relationship name
     * @param string $column Column to find minimum
     * @param callable|null $callback Optional callback to constrain
     * @return ModelQueryBuilder
     */
    public static function withMin(string $relation, string $column, ?callable $callback = null): ModelQueryBuilder
    {
        return static::query()->withMin($relation, $column, $callback);
    }

    /**
     * Add a subselect maximum of a relationship column to the query.
     *
     * @param string $relation Relationship name
     * @param string $column Column to find maximum
     * @param callable|null $callback Optional callback to constrain
     * @return ModelQueryBuilder
     */
    public static function withMax(string $relation, string $column, ?callable $callback = null): ModelQueryBuilder
    {
        return static::query()->withMax($relation, $column, $callback);
    }

    // =========================================================================
    // RELATION NORMALIZATION HELPERS
    // =========================================================================

    /**
     * Normalize with() relations into consistent format.
     *
     * Converts all input formats into: ['relation' => callback|null, ...]
     *
     * @param array $relations Raw relations input
     * @return array<string, callable|null> Normalized relations
     */
    public static function normalizeWithRelations(array $relations): array
    {
        $normalized = [];

        // Special case: with('childrens', function($q) { ... })
        // Only check this if we have exactly 2 elements and both are accessible by numeric keys
        if (
            count($relations) === 2 &&
            isset($relations[0]) && isset($relations[1]) &&
            is_string($relations[0]) && is_callable($relations[1])
        ) {
            return [$relations[0] => $relations[1]];
        }

        foreach ($relations as $key => $value) {
            // Case 1: Array with callback: ['childrens' => function($q) { ... }]
            if (is_string($key) && is_callable($value)) {
                $normalized[$key] = $value;
            }
            // Case 2: Array with string: ['childrens', 'category']
            // Case 2a: With column selection: ['category:id,name']
            elseif (is_int($key) && is_string($value)) {
                // Check if string contains column selection (e.g., 'category:id,name')
                if (str_contains($value, ':')) {
                    [$relationName, $columns] = explode(':', $value, 2);
                    $columns = array_map('trim', explode(',', $columns));

                    // Create closure to select only specified columns
                    // Note: For BelongsTo relations, we need to include the foreign key column
                    // But since we're selecting from the related table, we just need id and specified columns
                    $normalized[$relationName] = function ($query) use ($columns) {
                        // Ensure id is included for relation matching (if not already in columns)
                        $selectColumns = in_array('id', $columns) ? $columns : array_merge(['id'], $columns);
                        $query->select($selectColumns);
                    };
                } else {
                    $normalized[$value] = null;
                }
            }
            // Case 3: Nested array (from varargs): [['childrens', 'category']]
            elseif (is_int($key) && is_array($value)) {
                $nested = static::normalizeWithRelations($value);
                $normalized = array_merge($normalized, $nested);
            }
        }

        return $normalized;
    }

    // =========================================================================
    // STATIC WHEREDOESNTHAVE CONVENIENCE METHODS
    // =========================================================================

    /**
     * Static convenience method for whereDoesntHave.
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @param string $operator Comparison operator (<, =, etc.)
     * @param int $count Maximum count (default: 1)
     * @return ModelQueryBuilder
     */
    public static function whereDoesntHave(string $relation, ?callable $callback = null, string $operator = '<', int $count = 1): ModelQueryBuilder
    {
        return static::query()->whereDoesntHave($relation, $callback, $operator, $count);
    }

    /**
     * Static convenience method for whereDoesntHaveNested.
     *
     * @param string $relation Nested relationship using dot notation
     * @param callable|null $callback Optional callback to constrain the final relationship
     * @return ModelQueryBuilder
     */
    public static function whereDoesntHaveNested(string $relation, ?callable $callback = null): ModelQueryBuilder
    {
        return static::query()->whereDoesntHaveNested($relation, $callback);
    }

    /**
     * Static convenience method for whereDoesntHaveIn.
     *
     * @param string $relation Relationship method name
     * @param array $ids Array of IDs to exclude
     * @param string $column Column to check IDs against (default: 'id')
     * @return ModelQueryBuilder
     */
    public static function whereDoesntHaveIn(string $relation, array $ids, string $column = 'id'): ModelQueryBuilder
    {
        return static::query()->whereDoesntHaveIn($relation, $ids, $column);
    }

    /**
     * Static convenience method for whereDoesntHaveInDateRange.
     *
     * @param string $relation Relationship method name
     * @param string $dateColumn Date column to check
     * @param string|\DateTime $startDate Start date (inclusive)
     * @param string|\DateTime|null $endDate End date (inclusive, optional)
     * @return ModelQueryBuilder
     */
    public static function whereDoesntHaveInDateRange(string $relation, string $dateColumn, string|\DateTime $startDate, string|\DateTime|null $endDate = null): ModelQueryBuilder
    {
        return static::query()->whereDoesntHaveInDateRange($relation, $dateColumn, $startDate, $endDate);
    }

    /**
     * Static convenience method for whereDoesntHaveJsonAttribute.
     *
     * @param string $relation Relationship method name
     * @param string $jsonColumn JSON column name
     * @param string $jsonPath JSON path (e.g., '$.source')
     * @param mixed $value Value to match
     * @return ModelQueryBuilder
     */
    public static function whereDoesntHaveJsonAttribute(string $relation, string $jsonColumn, string $jsonPath, mixed $value): ModelQueryBuilder
    {
        return static::query()->whereDoesntHaveJsonAttribute($relation, $jsonColumn, $jsonPath, $value);
    }

    /**
     * Static convenience method for orWhereHas.
     *
     * @param string $relation Relationship method name
     * @param callable|null $callback Optional callback to constrain the relationship query
     * @param string $operator Comparison operator (>=, =, etc.)
     * @param int $count Count threshold (default: 1)
     * @return ModelQueryBuilder
     */
    public static function orWhereHas(string $relation, ?callable $callback = null, string $operator = '>=', int $count = 1): ModelQueryBuilder
    {
        return static::query()->orWhereHas($relation, $callback, $operator, $count);
    }
}
