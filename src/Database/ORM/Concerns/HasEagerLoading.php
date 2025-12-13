<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Database\ORM\ModelCollection;
use Toporia\Framework\Database\ORM\Exceptions\RelationNotFoundException;
use Toporia\Framework\Database\ORM\Relations\{BelongsTo, HasOne, HasOneThrough, MorphOne, MorphTo};
use Toporia\Framework\Database\Contracts\RelationInterface;

/**
 * Trait HasEagerLoading
 *
 * Trait providing reusable functionality for HasEagerLoading in the
 * Concerns layer of the Toporia Framework.
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
trait HasEagerLoading
{
    /**
     * Eager loaded relationships.
     *
     * @var array<string, mixed>
     */
    protected array $eagerLoaded = [];

    /**
     * Relationships that should be eager loaded.
     *
     * @var array<string>
     */
    protected static array $eagerLoadDefaults = [];

    /**
     * Eager load relationships for a collection of models.
     *
     * This is the main method that prevents N+1 queries.
     * It loads all relationships in batch queries.
     *
     * Supports nested relationships like 'posts.comments.author'.
     *
     * PERFORMANCE OPTIMIZATIONS:
     * - Early returns for empty inputs
     * - Efficient grouping and batch loading
     * - Thread-safe context-based approach
     *
     * Performance: O(n + m) where n = models, m = relationships
     * Without eager loading: O(n * m) queries
     * With eager loading: O(m) queries
     *
     * Thread-safe implementation using context-based approach instead of static properties.
     * This ensures no conflicts in concurrent/async scenarios or recursive calls.
     *
     * @param ModelCollection<Model> $models Collection of models
     * @param array<string> $relations Relationship names to load
     * @param array|null $context Internal context for recursive calls (do not pass manually)
     * @return void
     *
     * @example
     * ```php
     * $products = ProductModel::all();
     * static::eagerLoadRelations($products, ['category', 'reviews']);
     * // Now $products[0]->category and $products[0]->reviews are loaded
     *
     * // Nested relationships
     * static::eagerLoadRelations($users, ['posts.comments.author']);
     * // Loads users -> posts -> comments -> author in 4 batch queries
     *
     * // Constrained nested relationships
     * static::eagerLoadRelations($users, [
     *     'posts.comments' => fn($q) => $q->where('approved', true)
     * ]);
     * ```
     */
    public static function eagerLoadRelations(ModelCollection $models, array $relations, ?array $context = null): void
    {
        // Early returns for empty inputs
        if ($models->isEmpty() || empty($relations)) {
            return;
        }

        // Initialize context for this call (thread-safe, no static state)
        $context ??= [
            'nestedRelations' => [],
            'nestedConstraints' => [],
        ];

        // Group relationships by type for batch loading
        $grouped = static::groupRelationsByType($models, $relations, $context);

        // Early return if no valid relations found
        if (empty($grouped)) {
            return;
        }

        // Load each group in batch
        foreach ($grouped as $relationName => $relationData) {
            $relationInstances = $relationData['instances'];
            $constraint = $relationData['constraint'] ?? null;
            static::loadRelationBatch($models, $relationName, $relationInstances, $constraint, $context);
        }
    }

    /**
     * Group relationships by type for optimized batch loading.
     *
     * Supports nested relationships like 'posts.comments.author'.
     * Supports eager loading constraints via closures:
     * ['posts' => function($q) { $q->where('published', true); }]
     *
     * PERFORMANCE OPTIMIZATIONS:
     * - Only creates relation instances once per relation type (not per model)
     * - Early validation to skip invalid relations
     * - Efficient array operations with isset() checks
     * - Deduplication of nested relations using array keys for O(1) lookup
     *
     * @param ModelCollection<Model> $models Collection of models
     * @param array<string|\Closure> $relations Relationship names or ['relation' => Closure]
     * @param array &$context Context array for storing nested relations (passed by reference)
     * @return array<string, array{instances: array<RelationInterface>, constraint: \Closure|null}>
     */
    protected static function groupRelationsByType(ModelCollection $models, array $relations, array &$context): array
    {
        if (empty($relations)) {
            return [];
        }

        $grouped = [];
        $nested = []; // Track nested relations for later processing (using keys for O(1) deduplication)
        $constraints = []; // Track constraints for first-level relations
        $nestedConstraints = []; // Track constraints for nested relations

        // Early validation: get first model once
        $firstModel = $models->first();
        if (!$firstModel) {
            return [];
        }

        // CRITICAL FIX: Sort relations to process direct relations BEFORE nested relations
        // This ensures that when both 'image' and 'image.imageable' are defined,
        // the constraint for 'image' is processed and not skipped.
        // Example: ['image.imageable' => fn(), 'image' => fn()] becomes ['image' => fn(), 'image.imageable' => fn()]
        $directRelations = [];
        $nestedRelations = [];
        foreach ($relations as $key => $value) {
            $relationName = is_string($key) ? $key : $value;
            // Direct relations (no dot) come first, nested relations (with dot) come after
            // PERFORMANCE: Use native str_contains() instead of Str::contains() for better performance
            if (!str_contains($relationName, '.')) {
                $directRelations[$key] = $value;
            } else {
                $nestedRelations[$key] = $value;
            }
        }
        // Merge: direct first, then nested
        $sortedRelations = $directRelations + $nestedRelations;

        foreach ($sortedRelations as $key => $value) {
            // Handle format: ['relation' => Closure] or 'relation'
            $relationName = is_string($key) ? $key : $value;
            $constraint = is_string($key) && is_callable($value) ? $value : null;

            // Skip if relationName is not a string
            if (!is_string($relationName) || $relationName === '') {
                continue;
            }

            // Handle nested relations (e.g., 'posts.comments', 'reviews.user.profile')
            // Split only on first dot to support multi-level nesting (handled recursively)
            $parts = explode('.', $relationName, 2);
            $firstLevelRelation = $parts[0];

            // CRITICAL: Throw exception if relation method doesn't exist
            // This helps catch typos and missing relationship definitions early
            if (!method_exists($firstModel, $firstLevelRelation)) {
                $modelClass = get_class($firstModel);
                throw RelationNotFoundException::forRelation($modelClass, $firstLevelRelation, $relationName);
            }

            // Track nested relations for this first-level relation
            if (isset($parts[1])) {
                $nestedRelation = $parts[1]; // Can be 'user' or 'user.profile' (handled recursively)

                // Use array keys for O(1) deduplication instead of in_array (O(n))
                if (!isset($nested[$firstLevelRelation])) {
                    $nested[$firstLevelRelation] = [];
                }
                // Use key-based deduplication for better performance
                $nested[$firstLevelRelation][$nestedRelation] = true;

                // Store constraint for nested relation (not first-level)
                // IMPORTANT: Constraint applies to the FINAL relationship in the path
                // Example: 'reviews.user' => fn($q) => $q->where(...) applies to 'user', not 'reviews'
                // To constrain 'reviews', use: 'reviews' => fn($q) => $q->where(...), 'reviews.user'
                if ($constraint !== null) {
                    if (!isset($nestedConstraints[$firstLevelRelation])) {
                        $nestedConstraints[$firstLevelRelation] = [];
                    }
                    $nestedConstraints[$firstLevelRelation][$nestedRelation] = $constraint;
                }
            } else {
                // This is a first-level relation, store constraint for it
                // Example: 'reviews' => fn($q) => $q->where('helpful_count', '<', 20)
                // IMPORTANT: Always store constraint, even if relation is already grouped
                // This ensures constraints aren't lost when both 'relation' and 'relation.nested' are defined
                // CRITICAL FIX: Remove !isset() check to allow overwriting
                // Due to sorting (direct relations before nested), this ensures direct relation
                // constraints are ALWAYS set, even if $grouped entry was created during nested parsing
                if ($constraint !== null) {
                    $constraints[$firstLevelRelation] = $constraint;
                }
            }

            // Skip grouping if already grouped (early exit for performance)
            // But still allow constraint to be stored above
            if (isset($grouped[$firstLevelRelation])) {
                continue;
            }

            // Get relationship instance from first model (only once per relation type)
            $relationInstance = $firstModel->$firstLevelRelation();

            // CRITICAL: Throw exception if method doesn't return a valid relation
            // This ensures the method is actually a relationship method
            if (!$relationInstance instanceof RelationInterface) {
                $modelClass = get_class($firstModel);
                throw RelationNotFoundException::forRelation(
                    $modelClass,
                    $firstLevelRelation,
                    $relationName
                );
            }

            // PERFORMANCE FIX: Only create ONE relation instance instead of N instances
            // loadRelationBatch() only uses $relationInstances[0], so creating N instances is wasteful
            // For 100 models, this saves 99 unnecessary object creations per relation
            $relationInstance = $firstModel->$firstLevelRelation();

            if (!$relationInstance instanceof RelationInterface) {
                $modelClass = get_class($firstModel);
                throw RelationNotFoundException::forRelation(
                    $modelClass,
                    $firstLevelRelation,
                    $relationName
                );
            }

            // Store single instance in array to maintain interface compatibility with loadRelationBatch
            $grouped[$firstLevelRelation] = [$relationInstance];
        }

        // Store nested relations in context for processing after first level is loaded
        // OPTIMIZATION: Convert array keys back to values only when needed
        if (!empty($nested)) {
            $existingNested = $context['nestedRelations'] ?? [];
            foreach ($nested as $firstLevel => $nestedRels) {
                // Convert keys back to array of values (keys were used for deduplication)
                $nestedRelsArray = array_keys($nestedRels);

                if (!isset($existingNested[$firstLevel])) {
                    $existingNested[$firstLevel] = [];
                }
                // Merge and deduplicate nested relations using array_flip for O(1) deduplication
                // This is more efficient than array_unique for large arrays
                $merged = array_flip(array_merge(
                    array_flip($existingNested[$firstLevel]),
                    array_flip($nestedRelsArray)
                ));
                $existingNested[$firstLevel] = array_values($merged);
            }
            $context['nestedRelations'] = $existingNested;
        }

        // Store nested constraints in context
        // Use proper merge to avoid array_merge_recursive issues with duplicate keys
        if (!empty($nestedConstraints)) {
            $existingConstraints = $context['nestedConstraints'] ?? [];
            foreach ($nestedConstraints as $firstLevel => $nestedConstraintList) {
                if (!isset($existingConstraints[$firstLevel])) {
                    $existingConstraints[$firstLevel] = [];
                }
                // Merge constraints, later ones override earlier ones (standard behavior)
                $existingConstraints[$firstLevel] = array_merge(
                    $existingConstraints[$firstLevel],
                    $nestedConstraintList
                );
            }
            $context['nestedConstraints'] = $existingConstraints;
        }

        // Return grouped relations with constraints
        // OPTIMIZATION: Pre-allocate result array with known size
        // IMPORTANT: Use constraints array which includes both direct and first-level constraints
        $result = [];
        foreach ($grouped as $relationName => $instances) {
            // Skip empty instances array (defensive programming)
            if (empty($instances)) {
                continue;
            }
            $result[$relationName] = [
                'instances' => $instances,
                // Use constraint from $constraints which was populated above (line 199)
                // This ensures constraints work even when 'relation.nested' is defined before 'relation'
                'constraint' => $constraints[$relationName] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Load a relationship in batch for all models.
     *
     * Optimized eager loading using factory method pattern to eliminate reflection overhead.
     * Supports eager loading constraints via closures.
     *
     * PERFORMANCE OPTIMIZATIONS:
     * - Early returns for empty inputs
     * - Efficient array operations
     * - Batch eagerLoaded flag setting
     * - Optimized nested relations handling
     *
     * Performance:
     * - OLD: O(1) reflection overhead per batch load
     * - NEW: O(1) factory method call (10-50x faster than reflection)
     *
     * Clean Architecture:
     * - Open/Closed Principle: Extensible via newEagerInstance() override
     * - Single Responsibility: Each relation handles its own instantiation logic
     *
     * @param ModelCollection<Model> $models Collection of models
     * @param string $relationName Relationship name
     * @param array<RelationInterface> $relationInstances Relationship instances
     * @param \Closure|null $constraint Optional query constraint closure
     * @param array $context Context for nested relations (thread-safe)
     * @return void
     */
    protected static function loadRelationBatch(
        ModelCollection $models,
        string $relationName,
        array $relationInstances,
        ?\Closure $constraint = null,
        array $context = []
    ): void {
        // Early return for empty inputs
        if (empty($relationInstances) || $models->isEmpty()) {
            return;
        }

        // Get first relation instance to determine type
        $firstRelation = $relationInstances[0];

        // Create a fresh query builder for eager loading to avoid side effects
        $originalQuery = $firstRelation->getQuery();
        $freshQuery = $originalQuery->newQuery();

        // Copy table from original query to fresh query (newQuery() doesn't preserve table)
        $table = $originalQuery->getTable();
        if ($table !== null) {
            $freshQuery->table($table);
        }

        // CRITICAL: Create eager instance first, then apply constraint to the RELATION instance
        // This allows constraint callbacks to use relationship-specific methods like:
        // - wherePivot(), withPivot(), orderByPivot() for BelongsToMany/MorphToMany
        // - where(), orderBy(), limit() for all relations
        //
        // Flow:
        // 1. Create eager instance from freshQuery
        // 2. Apply constraint to eager instance (relationship object, NOT query)
        // 3. Relationship methods modify the internal query
        //
        // This matches Toporia's behavior where callbacks receive the relationship instance

        // Create eager instance first
        $eagerRelation = $firstRelation->newEagerInstance($freshQuery);

        // Apply constraint to the RELATIONSHIP instance (not the query)
        if ($constraint !== null) {
            $constraint($eagerRelation);
        }

        // Add eager constraints to query (this will add WHERE IN clause for multiple models)
        // OPTIMIZATION: Convert to array only once
        $modelsArray = $models->all();
        $eagerRelation->addEagerConstraints($modelsArray);

        // MorphTo has special handling - it does its own matching in getEager()
        // This follows Toporia's architecture where MorphTo creates fresh queries per morph type
        if ($eagerRelation instanceof MorphTo) {
            // Store relation name before calling getEager (needed for matchToMorphParents)
            $eagerRelation->match($modelsArray, new ModelCollection([]), $relationName);

            // Pass nested relations to MorphTo so it can load them for each morph type
            // This enables dot notation like 'imageable.comments' to work through MorphTo
            // The nested relations will be applied to ALL morph types (VideoModel, PostModel, etc.)
            $nestedRelations = $context['nestedRelations'] ?? [];
            $nestedConstraints = $context['nestedConstraints'] ?? [];

            if (isset($nestedRelations[$relationName]) && !empty($nestedRelations[$relationName])) {
                $nestedRelationsToLoad = $nestedRelations[$relationName];
                $nestedConstraintsForThisRelation = $nestedConstraints[$relationName] ?? [];

                // Build nested eager loads with proper constraint merging for deep nesting
                // Example: ['comments' => fn(), 'comments.author', 'comments.author.profile']
                // Should merge into: ['comments' => fn() with nested 'author.profile']
                $nestedEagerLoads = static::buildMergedNestedEagerLoads(
                    $nestedRelationsToLoad,
                    $nestedConstraintsForThisRelation
                );

                // Set nested eager loads on MorphTo - these will be applied to ALL morph types
                $eagerRelation->setNestedEagerLoads($nestedEagerLoads);
            }

            // Execute eager loading - MorphTo handles matching internally
            // getEager() creates fresh queries for each morph type and matches results
            // Nested relations are now included via setNestedEagerLoads()
            $eagerRelation->getEager();

            // Set eagerLoaded flag on models
            foreach ($modelsArray as $model) {
                if (!isset($model->eagerLoaded)) {
                    $model->eagerLoaded = [];
                }
                $model->eagerLoaded[$relationName] = true;
            }

            return;
        }

        // Execute query to get all related models (for non-MorphTo relations)
        $results = $eagerRelation->getResults();

        // Early return if no results (avoid unnecessary operations)
        if (empty($results) || ($results instanceof ModelCollection && $results->isEmpty())) {
            // Still need to set empty relations on models to prevent lazy loading
            // CRITICAL: Different relation types return different empty values
            // - BelongsTo, HasOne, MorphTo: return null (single model)
            // - HasMany, BelongsToMany, HasManyThrough: return empty ModelCollection (collection)
            $emptyValue = static::getEmptyRelationValue($eagerRelation);

            foreach ($modelsArray as $model) {
                if (!isset($model->eagerLoaded)) {
                    $model->eagerLoaded = [];
                }
                $model->eagerLoaded[$relationName] = true;
                // Set empty relation to prevent lazy loading with correct type
                $model->setRelation($relationName, $emptyValue);
            }
            return;
        }

        // Match results to parent models (this already sets relations on models)
        $eagerRelation->match($modelsArray, $results, $relationName);

        // Set eagerLoaded flag on models (match() already set the relation)
        // OPTIMIZATION: Batch set eagerLoaded flags
        foreach ($modelsArray as $model) {
            if (!isset($model->eagerLoaded)) {
                $model->eagerLoaded = [];
            }
            $model->eagerLoaded[$relationName] = true;
        }

        // Load nested relationships if any (e.g., 'posts.comments')
        // Use context instead of static properties for thread-safety
        $nestedRelations = $context['nestedRelations'] ?? [];
        $nestedConstraints = $context['nestedConstraints'] ?? [];

        // OPTIMIZATION: Early return if no nested relations
        if (!isset($nestedRelations[$relationName]) || empty($nestedRelations[$relationName])) {
            return;
        }

        // Only process nested relations if results are valid ModelCollection
        if ($results instanceof ModelCollection && !$results->isEmpty()) {
            $nestedRelationsToLoad = $nestedRelations[$relationName];

            // Apply constraints to nested relations if they exist
            // OPTIMIZATION: Pre-allocate array with known size
            $nestedRelationsWithConstraints = [];
            $nestedConstraintsForThisRelation = $nestedConstraints[$relationName] ?? [];

            foreach ($nestedRelationsToLoad as $nestedRelation) {
                // If there's a constraint for this nested relation, use it
                if (isset($nestedConstraintsForThisRelation[$nestedRelation])) {
                    $nestedRelationsWithConstraints[$nestedRelation] = $nestedConstraintsForThisRelation[$nestedRelation];
                } else {
                    // No constraint - use string key consistently to avoid hybrid array issues
                    // This ensures groupRelationsByType() can properly identify relations without constraints
                    $nestedRelationsWithConstraints[$nestedRelation] = null;
                }
            }

            // Recursive call with fresh context for nested relations
            // OPTIMIZATION: Only call if there are relations to load
            if (!empty($nestedRelationsWithConstraints)) {
                static::eagerLoadRelations($results, $nestedRelationsWithConstraints);
            }
        }
    }

    /**
     * Get the appropriate empty value for a relation type.
     *
     * PERFORMANCE: Uses instanceof checks in order of most common relations first.
     *
     * Different relation types return different empty values:
     * - Single model relations: BelongsTo, HasOne, MorphTo, MorphOne, HasOneThrough → return null
     * - Collection relations: HasMany, BelongsToMany, HasManyThrough, MorphMany, MorphToMany, MorphedByMany → return empty ModelCollection
     *
     * This ensures correct behavior matching Toporia's expectations:
     * - Single relations return null when no related model exists (not empty array)
     * - Collection relations return empty ModelCollection when no related models exist
     *
     * @param RelationInterface $relation Relation instance
     * @return mixed null for single relations, empty ModelCollection for collection relations
     */
    protected static function getEmptyRelationValue(RelationInterface $relation): mixed
    {
        // Single model relations (return null when empty)
        // These represent one-to-one or many-to-one relationships
        if (
            $relation instanceof BelongsTo ||
            $relation instanceof HasOne ||
            $relation instanceof MorphTo ||
            $relation instanceof MorphOne ||
            $relation instanceof HasOneThrough
        ) {
            return null;
        }

        // Collection relations (return empty ModelCollection when empty)
        // These represent one-to-many or many-to-many relationships
        // Includes: HasMany, BelongsToMany, HasManyThrough, MorphMany, MorphToMany, MorphedByMany
        return new ModelCollection([]);
    }

    /**
     * Set a relationship on the model.
     *
     * This method is called by the trait but should be provided by the class
     * using the trait (e.g., Model class). Model already has setRelation returning self.
     * We use a wrapper here to ignore the return value for trait compatibility.
     *
     * @param string $relation Relationship name
     * @param mixed $value Relationship value
     * @return void
     */
    protected function setRelationForEagerLoading(string $relation, mixed $value): void
    {
        // Call the parent's setRelation (from Model) and ignore return value
        if (method_exists($this, 'setRelation')) {
            $this->setRelation($relation, $value);
        }
    }

    /**
     * Check if a relationship is eager loaded.
     *
     * PERFORMANCE: Uses isset() for O(1) lookup.
     *
     * @param string $relation Relationship name
     * @return bool
     */
    public function relationLoaded(string $relation): bool
    {
        return isset($this->eagerLoaded[$relation]);
    }

    /**
     * Get all eager loaded relationships.
     *
     * @return array<string>
     */
    public function getEagerLoaded(): array
    {
        return array_keys($this->eagerLoaded);
    }

    /**
     * Set default relationships to eager load.
     *
     * @param array<string> $relations Relationship names
     * @return void
     */
    public static function setEagerLoadDefaults(array $relations): void
    {
        static::$eagerLoadDefaults = $relations;
    }

    /**
     * Get default relationships to eager load.
     *
     * @return array<string>
     */
    public static function getEagerLoadDefaults(): array
    {
        return static::$eagerLoadDefaults;
    }

    /**
     * Lazy eager load relationships for this model instance.
     *
     * Loads relationships after the model has already been retrieved.
     * Useful when you need to conditionally load relationships.
     *
     * PERFORMANCE: Early return if relations are already loaded.
     *
     * Example:
     * ```php
     * $user = User::find(1);
     * // Later, conditionally load posts
     * if ($someCondition) {
     *     $user->load('posts');
     * }
     * // Or with constraints
     * $user->load(['posts' => function($q) {
     *     $q->where('published', true);
     * }]);
     * ```
     *
     * @param string|array<string|\Closure> $relations Relationship names or ['relation' => Closure]
     * @return $this
     */
    public function load(string|array $relations): static
    {
        // Convert single relation to array
        $relations = is_array($relations) ? $relations : [$relations];

        // Early return for empty relations
        if (empty($relations)) {
            return $this;
        }

        // Create collection with this model
        $collection = new ModelCollection([$this]);

        // Use static eager loading method
        static::eagerLoadRelations($collection, $relations);

        return $this;
    }

    /**
     * Lazy eager load relationships only if they haven't been loaded yet.
     *
     * This is more efficient than load() when you're unsure if relationships
     * are already loaded - it avoids duplicate queries.
     *
     * PERFORMANCE: Early returns and efficient filtering.
     *
     * Example:
     * ```php
     * $user = User::with('posts')->find(1); // posts already loaded
     * $user->loadMissing('posts'); // Does nothing - posts already loaded
     * $user->loadMissing('comments'); // Loads comments
     *
     * // Also supports constraints
     * $user->loadMissing(['posts' => fn($q) => $q->where('published', true)]);
     *
     * // Multiple relations
     * $user->loadMissing(['posts', 'comments', 'profile']);
     * ```
     *
     * @param string|array<string|\Closure> $relations Relationship names or ['relation' => Closure]
     * @return $this
     */
    public function loadMissing(string|array $relations): static
    {
        // Convert single relation to array
        $relations = is_array($relations) ? $relations : [$relations];

        // Early return for empty relations
        if (empty($relations)) {
            return $this;
        }

        // Filter out already loaded relations
        // OPTIMIZATION: Pre-allocate array
        $missingRelations = [];

        foreach ($relations as $key => $value) {
            // Handle format: ['relation' => Closure] or 'relation'
            $relationName = is_string($key) ? $key : $value;

            // Skip if not a string
            if (!is_string($relationName) || $relationName === '') {
                continue;
            }

            // Get first level relation name (handle nested like 'posts.comments')
            // OPTIMIZATION: Use explode with limit for better performance
            $firstLevel = explode('.', $relationName, 2)[0];

            // Check if already loaded using isset() for O(1) lookup
            if (!isset($this->eagerLoaded[$firstLevel])) {
                // Preserve the original key/value pair
                if (is_string($key)) {
                    $missingRelations[$key] = $value;
                } else {
                    $missingRelations[] = $value;
                }
            }
        }

        // Only load if there are missing relations
        if (!empty($missingRelations)) {
            $this->load($missingRelations);
        }

        return $this;
    }

    /**
     * Lazy eager load a count of related models only if not already loaded.
     *
     * Example:
     * ```php
     * $user->loadMissingCount('posts');
     * // $user->posts_count is now available
     * ```
     *
     * @param string|array<string|\Closure> $relations Relationship names
     * @return $this
     */
    public function loadMissingCount(string|array $relations): static
    {
        // Convert single relation to array
        $relations = is_array($relations) ? $relations : [$relations];

        // Filter out already loaded counts
        $missingRelations = [];

        foreach ($relations as $key => $value) {
            $relationName = is_string($key) ? $key : $value;

            if (!is_string($relationName)) {
                continue;
            }

            $countAttribute = $relationName . '_count';

            // Check if count already loaded (exists as attribute)
            if (!isset($this->attributes[$countAttribute])) {
                if (is_string($key)) {
                    $missingRelations[$key] = $value;
                } else {
                    $missingRelations[] = $value;
                }
            }
        }

        // Only load if there are missing counts
        if (!empty($missingRelations)) {
            $this->loadCount($missingRelations);
        }

        return $this;
    }

    /**
     * Lazy eager load count of related models.
     *
     * Example:
     * ```php
     * $user = User::find(1);
     * $user->loadCount('posts');
     * // $user->posts_count is now available
     *
     * // With constraints
     * $user->loadCount(['posts' => fn($q) => $q->where('published', true)]);
     * ```
     *
     * @param string|array<string|\Closure> $relations Relationship names
     * @return $this
     */
    public function loadCount(string|array $relations): static
    {
        // Convert single relation to array
        $relations = is_array($relations) ? $relations : [$relations];

        foreach ($relations as $key => $value) {
            $relationName = is_string($key) ? $key : $value;
            $constraint = is_string($key) && is_callable($value) ? $value : null;

            if (!is_string($relationName) || $relationName === '') {
                continue;
            }

            // CRITICAL: Throw exception if relation method doesn't exist
            if (!method_exists($this, $relationName)) {
                $modelClass = get_class($this);
                throw RelationNotFoundException::forRelation($modelClass, $relationName);
            }

            // Get relationship instance
            $relationInstance = $this->$relationName();

            // CRITICAL: Throw exception if method doesn't return a valid relation
            if (!$relationInstance instanceof RelationInterface) {
                $modelClass = get_class($this);
                throw RelationNotFoundException::forRelation($modelClass, $relationName);
            }

            // Get query and apply constraints
            $query = $relationInstance->getQuery();

            if ($constraint !== null) {
                $constraint($query);
            }

            // Get count
            $count = $query->count();

            // Set count as attribute
            $countAttribute = $relationName . '_count';
            $this->setAttribute($countAttribute, $count);
        }

        return $this;
    }

    /**
     * Load aggregate values for relationships.
     *
     * Example:
     * ```php
     * $user->loadSum('posts', 'views');
     * // $user->posts_sum_views is now available
     *
     * $user->loadAvg('reviews', 'rating');
     * // $user->reviews_avg_rating is now available
     * ```
     *
     * @param string $relation Relationship name
     * @param string $column Column to aggregate
     * @param string $function Aggregate function (sum, avg, min, max)
     * @return $this
     */
    public function loadAggregate(string $relation, string $column, string $function = 'sum'): static
    {
        // CRITICAL: Throw exception if relation method doesn't exist
        if (!method_exists($this, $relation)) {
            $modelClass = get_class($this);
            throw RelationNotFoundException::forRelation($modelClass, $relation);
        }

        $relationInstance = $this->$relation();

        // CRITICAL: Throw exception if method doesn't return a valid relation
        if (!$relationInstance instanceof RelationInterface) {
            $modelClass = get_class($this);
            throw RelationNotFoundException::forRelation($modelClass, $relation);
        }

        $query = $relationInstance->getQuery();

        $value = match (strtolower($function)) {
            'sum' => $query->sum($column),
            'avg' => $query->avg($column),
            'min' => $query->min($column),
            'max' => $query->max($column),
            'count' => $query->count($column),
            default => null,
        };

        // Set aggregate as attribute
        $attributeName = "{$relation}_{$function}_{$column}";
        $this->setAttribute($attributeName, $value);

        return $this;
    }

    /**
     * Load sum aggregate for a relationship.
     *
     * @param string $relation Relationship name
     * @param string $column Column to sum
     * @return $this
     */
    public function loadSum(string $relation, string $column): static
    {
        return $this->loadAggregate($relation, $column, 'sum');
    }

    /**
     * Load average aggregate for a relationship.
     *
     * @param string $relation Relationship name
     * @param string $column Column to average
     * @return $this
     */
    public function loadAvg(string $relation, string $column): static
    {
        return $this->loadAggregate($relation, $column, 'avg');
    }

    /**
     * Load minimum aggregate for a relationship.
     *
     * @param string $relation Relationship name
     * @param string $column Column to get minimum
     * @return $this
     */
    public function loadMin(string $relation, string $column): static
    {
        return $this->loadAggregate($relation, $column, 'min');
    }

    /**
     * Load maximum aggregate for a relationship.
     *
     * @param string $relation Relationship name
     * @param string $column Column to get maximum
     * @return $this
     */
    public function loadMax(string $relation, string $column): static
    {
        return $this->loadAggregate($relation, $column, 'max');
    }

    // =========================================================================
    // NESTED EAGER LOADING HELPERS
    // =========================================================================

    /**
     * Build merged nested eager loads with proper constraint handling for deep nesting.
     *
     * This method handles complex cases like:
     * - ['comments' => fn($q) => ..., 'comments.author', 'comments.author.profile']
     * - Should merge into proper format for ModelQueryBuilder::with() to handle
     *
     * The key insight is that when we have:
     * - 'comments' with constraint
     * - 'comments.author' without constraint
     *
     * We need to produce:
     * - ['comments' => fn($q) => $q->where(...)->with('author')]
     *
     * However, since ModelQueryBuilder::with() already handles dot notation natively,
     * we can pass them as-is BUT we need to ensure constraints are wrapped properly
     * to include their nested relations.
     *
     * @param array $nestedRelations Array of relation paths (e.g., ['comments', 'comments.author'])
     * @param array $constraints Array of constraints keyed by relation path
     * @return array Properly formatted eager loads for with()
     */
    protected static function buildMergedNestedEagerLoads(array $nestedRelations, array $constraints): array
    {
        // Group relations by their first-level relation
        // e.g., 'comments.author.profile' -> firstLevel = 'comments', rest = 'author.profile'
        $grouped = [];
        $simpleRelations = [];

        foreach ($nestedRelations as $relation) {
            $parts = explode('.', $relation, 2);
            $firstLevel = $parts[0];

            if (isset($parts[1])) {
                // Has nested part
                if (!isset($grouped[$firstLevel])) {
                    $grouped[$firstLevel] = [
                        'nested' => [],
                        'constraint' => null,
                    ];
                }
                $grouped[$firstLevel]['nested'][] = $parts[1];

                // Check if there's a constraint for this specific path
                if (isset($constraints[$relation])) {
                    // Store constraint for the nested path
                    if (!isset($grouped[$firstLevel]['nestedConstraints'])) {
                        $grouped[$firstLevel]['nestedConstraints'] = [];
                    }
                    $grouped[$firstLevel]['nestedConstraints'][$parts[1]] = $constraints[$relation];
                }
            } else {
                // Simple relation (no dot)
                if (isset($constraints[$relation])) {
                    // Has constraint - will be handled in grouped
                    if (!isset($grouped[$firstLevel])) {
                        $grouped[$firstLevel] = [
                            'nested' => [],
                            'constraint' => null,
                        ];
                    }
                    $grouped[$firstLevel]['constraint'] = $constraints[$relation];
                } else {
                    $simpleRelations[] = $relation;
                }
            }
        }

        // Also check constraints for relations that might only have constraint (no nested specified)
        foreach ($constraints as $path => $constraint) {
            $parts = explode('.', $path, 2);
            $firstLevel = $parts[0];

            if (!isset($parts[1])) {
                // First-level constraint
                if (!isset($grouped[$firstLevel])) {
                    $grouped[$firstLevel] = [
                        'nested' => [],
                        'constraint' => $constraint,
                    ];
                } elseif ($grouped[$firstLevel]['constraint'] === null) {
                    $grouped[$firstLevel]['constraint'] = $constraint;
                }
            }
        }

        // Build result array
        $result = [];

        // Add simple relations (no constraints, no nesting from this level)
        foreach ($simpleRelations as $relation) {
            // Check if this is also in grouped (has deeper nesting)
            if (!isset($grouped[$relation])) {
                $result[] = $relation;
            }
        }

        // Process grouped relations
        foreach ($grouped as $firstLevel => $data) {
            $constraint = $data['constraint'];
            $nestedPaths = $data['nested'];
            $nestedConstraints = $data['nestedConstraints'] ?? [];

            if (empty($nestedPaths) && $constraint === null) {
                // No constraint, no nesting - simple relation
                $result[] = $firstLevel;
            } elseif (empty($nestedPaths) && $constraint !== null) {
                // Has constraint, no deeper nesting
                $result[$firstLevel] = $constraint;
            } elseif (!empty($nestedPaths)) {
                // Has deeper nesting - need to wrap in constraint that adds nested with()
                if ($constraint !== null) {
                    // Wrap existing constraint to also load nested relations
                    $originalConstraint = $constraint;
                    $nestedToLoad = static::buildMergedNestedEagerLoads($nestedPaths, $nestedConstraints);

                    $result[$firstLevel] = function ($query) use ($originalConstraint, $nestedToLoad) {
                        // Apply original constraint
                        $originalConstraint($query);
                        // Add nested eager loads
                        if (!empty($nestedToLoad)) {
                            $query->with($nestedToLoad);
                        }
                    };
                } else {
                    // No constraint at this level, but has nested relations
                    // Recursively build nested loads
                    $nestedToLoad = static::buildMergedNestedEagerLoads($nestedPaths, $nestedConstraints);

                    if (!empty($nestedToLoad)) {
                        // Create wrapper constraint just to add nested with()
                        $result[$firstLevel] = function ($query) use ($nestedToLoad) {
                            $query->with($nestedToLoad);
                        };
                    } else {
                        $result[] = $firstLevel;
                    }
                }
            }
        }

        return $result;
    }
}
