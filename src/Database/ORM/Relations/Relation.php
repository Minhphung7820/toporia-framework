<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Relations;

use Toporia\Framework\Database\Contracts\RelationInterface;
use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Database\Query\QueryBuilder;
use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Support\Str;


/**
 * Abstract Class Relation
 *
 * Abstract base class for Relation implementations in the Relations layer
 * providing common functionality and contracts.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Relations
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class Relation implements RelationInterface
{
    // =========================================================================
    // GLOBAL MORPH MAP
    // =========================================================================

    /**
     * Global morph type to class name map.
     *
     * This allows using short aliases instead of full class names in database.
     * Example: 'post' => App\Models\Post::class
     *
     * @var array<string, class-string<Model>>
     */
    protected static array $morphMap = [];

    /**
     * Get or set the morph map for polymorphic relationships.
     *
     * When called without arguments, returns the current morph map.
     * When called with a map, sets/merges the morph map.
     *
     * Performance: O(1) for get, O(n) for merge where n = map size
     *
     * @param array<string, class-string<Model>>|null $map Morph map to set/merge
     * @param bool $merge Whether to merge with existing map (default: true)
     * @return array<string, class-string<Model>> Current morph map
     *
     * @example
     * ```php
     * // Set morph map (typically in ServiceProvider)
     * Relation::morphMap([
     *     'post' => Post::class,
     *     'video' => Video::class,
     *     'user' => User::class,
     * ]);
     *
     * // Get morph map
     * $map = Relation::morphMap();
     *
     * // Replace entire map (don't merge)
     * Relation::morphMap(['post' => Post::class], false);
     * ```
     */
    public static function morphMap(?array $map = null, bool $merge = true): array
    {
        if ($map === null) {
            return static::$morphMap;
        }

        static::$morphMap = $merge
            ? array_merge(static::$morphMap, $map)
            : $map;

        return static::$morphMap;
    }

    /**
     * Get the model class for a given morph type.
     *
     * Resolves alias to class name using morph map.
     * Returns the input if no mapping exists (assumes full class name).
     *
     * Performance: O(1) - Direct array lookup
     *
     * @param string $alias Morph type alias or class name
     * @return string Resolved class name
     *
     * @example
     * ```php
     * // With morph map: ['post' => App\Models\Post::class]
     * Relation::getMorphedModel('post'); // Returns 'App\Models\Post'
     * Relation::getMorphedModel('App\Models\Post'); // Returns 'App\Models\Post'
     * ```
     */
    public static function getMorphedModel(string $alias): string
    {
        return static::$morphMap[$alias] ?? $alias;
    }

    /**
     * Get the morph alias for a given model class.
     *
     * Returns the alias if found in morph map, otherwise returns the class name.
     *
     * Performance: O(n) - Linear search through map values
     *
     * @param string|object $model Model class name or instance
     * @return string Morph alias or class name
     *
     * @example
     * ```php
     * // With morph map: ['post' => App\Models\Post::class]
     * Relation::getMorphAlias(Post::class); // Returns 'post'
     * Relation::getMorphAlias($postInstance); // Returns 'post'
     * Relation::getMorphAlias(Comment::class); // Returns 'App\Models\Comment'
     * ```
     */
    public static function getMorphAlias(string|object $model): string
    {
        $className = is_object($model) ? get_class($model) : $model;

        // Search for alias in morph map
        $alias = array_search($className, static::$morphMap, true);

        return $alias !== false ? $alias : $className;
    }

    /**
     * Check if a morph type alias exists in the map.
     *
     * @param string $alias Morph type alias to check
     * @return bool True if alias exists in morph map
     */
    public static function hasMorphAlias(string $alias): bool
    {
        return isset(static::$morphMap[$alias]);
    }

    /**
     * Clear the global morph map.
     *
     * Useful for testing or resetting state.
     *
     * @return void
     */
    public static function clearMorphMap(): void
    {
        static::$morphMap = [];
        static::$morphMapLoaded = false;
    }

    /**
     * Flag to track if morph map has been loaded from config.
     *
     * @var bool
     */
    protected static bool $morphMapLoaded = false;

    /**
     * Load morph map from configuration file.
     *
     * This method loads morph type aliases from config/morphs.php
     * and optionally performs auto-discovery of model classes.
     *
     * Should be called once during application boot (e.g., in ServiceProvider).
     *
     * Performance: O(n) where n = number of models (only runs once)
     *
     * @param string|null $configPath Custom config file path (for testing)
     * @return array<string, class-string<Model>> Loaded morph map
     *
     * @example
     * ```php
     * // In ServiceProvider boot() method
     * Relation::loadMorphMapFromConfig();
     *
     * // With custom config path (for testing)
     * Relation::loadMorphMapFromConfig('/path/to/custom/morphs.php');
     * ```
     */
    public static function loadMorphMapFromConfig(?string $configPath = null): array
    {
        if (static::$morphMapLoaded && $configPath === null) {
            return static::$morphMap;
        }

        // Determine config file path
        $configFile = $configPath ?? static::getMorphConfigPath();

        if (!file_exists($configFile)) {
            static::$morphMapLoaded = true;
            return static::$morphMap;
        }

        // Load config
        $config = require $configFile;

        if (!is_array($config)) {
            static::$morphMapLoaded = true;
            return static::$morphMap;
        }

        // Load explicit morph map
        if (isset($config['map']) && is_array($config['map'])) {
            static::morphMap($config['map']);
        }

        // Perform auto-discovery if enabled
        if (isset($config['auto_discover']) && $config['auto_discover'] === true) {
            $paths = $config['discovery_paths'] ?? ['app/Models'];
            $namespace = $config['discovery_namespace'] ?? 'App\\Models';

            static::discoverMorphTypes($paths, $namespace);
        }

        static::$morphMapLoaded = true;
        return static::$morphMap;
    }

    /**
     * Get the default morph config file path.
     *
     * @return string
     */
    protected static function getMorphConfigPath(): string
    {
        // Try to use Toporia's base_path() if available
        if (function_exists('base_path')) {
            return base_path('config/morphs.php');
        }

        // Fallback: assume we're in vendor/toporia/framework/src/...
        // Go up to project root
        $dir = __DIR__;
        while ($dir !== '/' && !file_exists($dir . '/config/morphs.php')) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return $dir . '/config/morphs.php';
    }

    /**
     * Discover morph types from model classes.
     *
     * Scans the given directories for model classes that define
     * a static $morphAlias property and registers them in the morph map.
     *
     * Performance: O(n * m) where n = number of files, m = class loading time
     * This is only called once during application boot.
     *
     * @param array<string> $paths Directories to scan (relative to base path)
     * @param string $baseNamespace Base namespace for models
     * @return array<string, class-string<Model>> Discovered morph types
     *
     * @example
     * ```php
     * // In your model class
     * class Post extends Model
     * {
     *     public static string $morphAlias = 'post';
     * }
     *
     * // Auto-discovery will register: 'post' => App\Models\Post::class
     * ```
     */
    public static function discoverMorphTypes(array $paths, string $baseNamespace = 'App\\Models'): array
    {
        $discovered = [];
        $basePath = static::getBasePath();

        foreach ($paths as $path) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $path;

            if (!is_dir($fullPath)) {
                continue;
            }

            // Scan directory for PHP files
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    // Calculate relative path from models directory
                    $relativePath = str_replace(
                        [$fullPath . DIRECTORY_SEPARATOR, '.php'],
                        ['', ''],
                        $file->getPathname()
                    );

                    // Convert path to class name
                    $className = $baseNamespace . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

                    // Check if class exists and has morphAlias property
                    if (!class_exists($className, true)) {
                        continue;
                    }

                    // Check for static morphAlias property
                    try {
                        $reflection = new \ReflectionClass($className);

                        // Only process Model subclasses
                        if (!$reflection->isSubclassOf(Model::class)) {
                            continue;
                        }

                        // Check for public static property
                        if ($reflection->hasProperty('morphAlias')) {
                            $property = $reflection->getProperty('morphAlias');
                            if ($property->isStatic() && $property->isPublic()) {
                                $alias = $property->getValue();
                                if (is_string($alias) && $alias !== '') {
                                    $discovered[$alias] = $className;
                                }
                            }
                        }

                        // Also check for getMorphAlias static method
                        if ($reflection->hasMethod('getMorphAlias')) {
                            $method = $reflection->getMethod('getMorphAlias');
                            if ($method->isStatic() && $method->isPublic() && $method->getNumberOfRequiredParameters() === 0) {
                                try {
                                    $alias = $className::getMorphAlias();
                                    // Only use if different from class name (custom alias)
                                    if (is_string($alias) && $alias !== '' && $alias !== $className) {
                                        $discovered[$alias] = $className;
                                    }
                                } catch (\Throwable $e) {
                                    // Method threw exception, skip
                                }
                            }
                        }
                    } catch (\ReflectionException $e) {
                        // Skip classes that can't be reflected
                        continue;
                    }
                }
            }
        }

        // Merge discovered types into morph map (explicit config takes priority)
        if (!empty($discovered)) {
            // Discovered types have lower priority - only add if not already set
            foreach ($discovered as $alias => $className) {
                if (!isset(static::$morphMap[$alias])) {
                    static::$morphMap[$alias] = $className;
                }
            }
        }

        return $discovered;
    }

    /**
     * Get the application base path.
     *
     * @return string
     */
    protected static function getBasePath(): string
    {
        // Try to use Toporia's base_path() if available
        if (function_exists('base_path')) {
            return base_path();
        }

        // Fallback: find project root by looking for composer.json
        $dir = __DIR__;
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return $dir;
    }

    /**
     * Reset the morph map loaded flag.
     *
     * Useful for testing or when config changes.
     *
     * @return void
     */
    public static function resetMorphMapLoaded(): void
    {
        static::$morphMapLoaded = false;
    }

    /**
     * Check if morph map has been loaded from config.
     *
     * @return bool
     */
    public static function isMorphMapLoaded(): bool
    {
        return static::$morphMapLoaded;
    }

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    /**
     * @param QueryBuilder $query Query builder for related model
     * @param Model $parent Parent model instance
     * @param string $foreignKey Foreign key column name
     * @param string $localKey Local key column name
     */
    public function __construct(
        protected QueryBuilder $query,
        protected Model $parent,
        protected string $foreignKey,
        protected string $localKey
    ) {}

    /**
     * Create a new instance for eager loading without parent constraints.
     *
     * This factory method creates a fresh relation instance with a clean query,
     * avoiding the need for reflection to manipulate private properties.
     *
     * Performance: O(1) - Direct instantiation, no reflection overhead
     * Clean Architecture: Open/Closed - Extensible without modifying base class
     *
     * @param QueryBuilder $freshQuery Fresh query builder without constraints
     * @return static New relation instance ready for eager loading
     */
    public function newEagerInstance(QueryBuilder $freshQuery): static
    {
        return new static($freshQuery, $this->parent, $this->foreignKey, $this->localKey);
    }

    /**
     * Copy where constraints from original query to new query, excluding parent-specific constraints.
     *
     * This ensures that constraints defined in relationship methods (like ->where('slug', 'like', '%Repellat%'))
     * are preserved when eager loading.
     *
     * @param QueryBuilder $newQuery The new query builder to apply constraints to
     * @param array $excludeColumns Columns to exclude (e.g., foreign key columns)
     * @return void
     */
    protected function copyWhereConstraints(QueryBuilder $newQuery, array $excludeColumns = []): void
    {
        $originalWheres = $this->query->getWheres();

        foreach ($originalWheres as $where) {
            // Skip if column is in exclude list (parent-specific constraints)
            if (isset($where['column'])) {
                $column = $where['column'];

                // Check if column should be excluded
                $shouldExclude = false;
                foreach ($excludeColumns as $excludePattern) {
                    if (is_string($excludePattern)) {
                        if ($column === $excludePattern || Str::endsWith($column, '.' . $excludePattern)) {
                            $shouldExclude = true;
                            break;
                        }
                    } elseif (is_callable($excludePattern)) {
                        if ($excludePattern($column)) {
                            $shouldExclude = true;
                            break;
                        }
                    }
                }

                if ($shouldExclude) {
                    continue;
                }
            }

            // Apply the where constraint to the new query based on type
            match ($where['type'] ?? '') {
                'basic' => $newQuery->where(
                    $where['column'],
                    $where['operator'] ?? '=',
                    $where['value'] ?? null,
                    $where['boolean'] ?? 'AND'
                ),
                'Null' => $newQuery->whereNull($where['column'], $where['boolean'] ?? 'AND'),
                'NotNull' => $newQuery->whereNotNull($where['column'], $where['boolean'] ?? 'AND'),
                'In' => $newQuery->whereIn($where['column'], $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                'NotIn' => $newQuery->whereNotIn($where['column'], $where['values'] ?? [], $where['boolean'] ?? 'AND'),
                'Raw' => $newQuery->whereRaw(
                    $where['sql'] ?? '',
                    $where['bindings'] ?? [],
                    $where['boolean'] ?? 'AND'
                ),
                'nested' => $newQuery->where(function ($q) use ($where, $excludeColumns) {
                    // For nested queries, recursively apply constraints
                    if (isset($where['query']) && method_exists($where['query'], 'getWheres')) {
                        $nestedWheres = $where['query']->getWheres();
                        foreach ($nestedWheres as $nestedWhere) {
                            if (isset($nestedWhere['column'])) {
                                $nestedColumn = $nestedWhere['column'];
                                $shouldExclude = false;
                                foreach ($excludeColumns as $excludePattern) {
                                    if (is_string($excludePattern)) {
                                        if ($nestedColumn === $excludePattern || Str::endsWith($nestedColumn, '.' . $excludePattern)) {
                                            $shouldExclude = true;
                                            break;
                                        }
                                    } elseif (is_callable($excludePattern)) {
                                        if ($excludePattern($nestedColumn)) {
                                            $shouldExclude = true;
                                            break;
                                        }
                                    }
                                }
                                if ($shouldExclude) {
                                    continue;
                                }
                            }

                            if ($nestedWhere['type'] === 'basic') {
                                $q->where(
                                    $nestedWhere['column'],
                                    $nestedWhere['operator'] ?? '=',
                                    $nestedWhere['value'] ?? null,
                                    $nestedWhere['boolean'] ?? 'AND'
                                );
                            }
                        }
                    }
                }, $where['boolean'] ?? 'AND'),
                default => null, // Skip unknown types
            };
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Get the parent model instance.
     *
     * @return Model Parent model instance
     */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * {@inheritdoc}
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * {@inheritdoc}
     */
    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    /**
     * Set the query builder for this relation.
     *
     * Allows replacing the query without reflection, following Open/Closed principle.
     *
     * Performance: O(1) - Direct property assignment
     * Clean Architecture: Setter method instead of reflection manipulation
     *
     * @param QueryBuilder $query New query builder instance
     * @return $this
     */
    public function setQuery(QueryBuilder $query): static
    {
        $this->query = $query;
        return $this;
    }

    /**
     * Add basic WHERE constraint based on parent model.
     *
     * @return $this
     */
    public function addConstraints(): static
    {
        if ($this->parent->exists()) {
            $this->query->where(
                $this->foreignKey,
                $this->parent->getAttribute($this->localKey)
            );
        }

        return $this;
    }

    /**
     * Set WHERE IN constraint for eager loading.
     *
     * OPTIMIZED: Automatically applies soft delete scope if related model uses soft deletes.
     * PERFORMANCE: Uses array_flip for O(n) deduplication instead of array_unique O(n log n).
     *
     * IMPORTANT: Wraps existing WHERE conditions in nested group to handle OR operator precedence.
     * Without wrapping: WHERE a = 1 OR b = 2 AND foreign_key IN (...) → WRONG (OR has lower precedence)
     * With wrapping: WHERE (a = 1 OR b = 2) AND foreign_key IN (...) → CORRECT
     *
     * @param array<int, Model> $models
     * @return void
     */
    public function addEagerConstraints(array $models): void
    {
        // Early return for empty models
        if (empty($models)) {
            return;
        }

        // OPTIMIZATION: Pre-allocate array with estimated size
        // Use array_flip for O(n) deduplication instead of array_unique O(n log n)
        $keys = [];
        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            if ($key !== null) {
                // Use key as array key for automatic deduplication (O(1) lookup)
                $keys[$key] = true;
            }
        }

        // Convert back to array of values only if we have keys
        if (!empty($keys)) {
            // array_keys is O(n) and preserves order, better than array_unique for large arrays
            $uniqueKeys = array_keys($keys);

            // CRITICAL FIX: Wrap existing WHERE conditions to handle OR operator precedence
            // If user callback has OR conditions, we need to wrap them in parentheses
            // Example: (is_capital = 1 OR population > 5M) AND country_id IN (...)
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
                            // Determine boolean operator (first condition has no boolean, subsequent use their stored boolean)
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

            // Add whereIn for eager loading (always use AND to ensure proper filtering)
            $this->query->whereIn($this->foreignKey, $uniqueKeys);
        }

        // Apply soft delete scope if related model uses soft deletes
        $relatedClass = $this->getRelatedClass();
        if ($relatedClass !== '') {
            $this->applySoftDeleteScope($this->query, $relatedClass);
        }
    }

    /**
     * Execute the relationship query.
     *
     * Subclasses must implement getResults() for specific return types.
     */
    abstract public function getResults(): mixed;

    /**
     * Match eager loaded results to their parent models.
     *
     * Subclasses must implement match() for specific matching logic.
     */
    abstract public function match(array $models, mixed $results, string $relationName): array;

    // =========================================================================
    // SOFT DELETE SUPPORT
    // =========================================================================

    /**
     * Check if related model class uses soft deletes.
     *
     * Performance: O(1) - Single method_exists check
     *
     * @param string $modelClass Model class name
     * @return bool
     */
    protected function relatedModelUsesSoftDeletes(string $modelClass): bool
    {
        return method_exists($modelClass, 'usesSoftDeletes') && $modelClass::usesSoftDeletes();
    }

    /**
     * Get deleted_at column name from related model.
     *
     * Performance: O(1) - Single method call
     *
     * @param string $modelClass Model class name
     * @return string
     */
    protected function getDeletedAtColumn(string $modelClass): string
    {
        if (method_exists($modelClass, 'getDeletedAtColumn')) {
            return $modelClass::getDeletedAtColumn();
        }

        return 'deleted_at';
    }

    /**
     * Apply soft delete scope to query if related model uses soft deletes.
     *
     * Performance: O(1) - Single WHERE clause addition
     * Only adds WHERE deleted_at IS NULL if model uses soft deletes
     * Respects withTrashed() by checking skipGlobalScopes flag
     *
     * @param QueryBuilder $query Query builder
     * @param string $modelClass Related model class name
     * @param string|null $tableAlias Optional table alias for qualified column
     * @return void
     */
    protected function applySoftDeleteScope(QueryBuilder $query, string $modelClass, ?string $tableAlias = null): void
    {
        if (!$this->relatedModelUsesSoftDeletes($modelClass)) {
            return;
        }

        // Check if query has skipGlobalScopes flag (from withTrashed())
        // If ModelQueryBuilder with skipGlobalScopes = true, don't apply SoftDeletes scope
        // Use public method to avoid reflection overhead (O(1) instead of O(n) reflection calls)
        if (
            $query instanceof ModelQueryBuilder
            && $query->isSkippingGlobalScopes()
        ) {
            return; // Skip applying SoftDeletes scope when withTrashed() is used
        }

        $deletedAtColumn = $this->getDeletedAtColumn($modelClass);
        $qualifiedColumn = $tableAlias ? "{$tableAlias}.{$deletedAtColumn}" : $deletedAtColumn;

        $query->whereNull($qualifiedColumn);
    }

    /**
     * Get related model class name.
     *
     * Subclasses should override this to return the related model class.
     *
     * @return string Fully qualified class name of the related model
     */
    public function getRelatedClass(): string
    {
        // Default implementation - subclasses should override
        return '';
    }

    /**
     * Copy custom select and orderBy from source query to target query.
     *
     * This helper ensures eager loading constraint closures that contain
     * select() and orderBy() calls are properly applied to queries.
     *
     * Usage: Call this when rebuilding queries in getResults() methods.
     *
     * @param QueryBuilder $sourceQuery Source query with constraints
     * @param QueryBuilder $targetQuery Target query to copy to
     * @param bool $addDefaultSelect Whether to add SELECT * if no custom select
     * @return void
     */
    protected function copySelectAndOrderBy(
        QueryBuilder $sourceQuery,
        QueryBuilder $targetQuery,
        bool $addDefaultSelect = true
    ): void {
        // Copy custom select from constraint closure if provided
        $customColumns = $sourceQuery->getColumns();
        if (!empty($customColumns)) {
            // User provided custom select - use it
            $targetQuery->select($customColumns);
        } elseif ($addDefaultSelect) {
            // No custom select - use default SELECT *
            $targetQuery->select('*');
        }

        // Copy orderBy from constraint closure if provided
        $customOrders = $sourceQuery->getOrders();
        if (!empty($customOrders)) {
            foreach ($customOrders as $order) {
                if (!isset($order['column'])) continue;
                $targetQuery->orderBy($order['column'], $order['direction'] ?? 'ASC');
            }
        }
    }

    /**
     * Get the order direction for a specific column from query.
     *
     * Helper method for cursor pagination.
     *
     * @param QueryBuilder $query Query builder
     * @param string $column Column name
     * @return string|null 'ASC' or 'DESC', or null if not found
     */
    protected function getOrderDirectionForColumn(QueryBuilder $query, string $column): ?string
    {
        // PERFORMANCE FIX: Use public method instead of reflection
        $orders = $query->getOrders();

        // Find order by for this column
        foreach ($orders as $order) {
            if (isset($order['column']) && $order['column'] === $column) {
                return $order['direction'] ?? 'ASC';
            }
        }

        return null;
    }

    /**
     * Ensure query is ordered by cursor column.
     *
     * Helper method for cursor pagination.
     *
     * @param QueryBuilder $query Query builder
     * @param string $column Cursor column
     * @param string $direction Order direction
     * @return QueryBuilder
     */
    protected function ensureOrderByCursorColumn(QueryBuilder $query, string $column, string $direction): QueryBuilder
    {
        // PERFORMANCE FIX: Use public method instead of reflection
        // Check if column is already ordered
        $isOrdered = false;
        $orders = $query->getOrders();

        foreach ($orders as $order) {
            if (isset($order['column']) && $order['column'] === $column) {
                $isOrdered = true;
                break;
            }
        }

        // Add cursor column as primary sort if not already present
        if (!$isOrdered) {
            $query->orderBy($column, $direction);
        }

        return $query;
    }

    /**
     * Encode cursor value for pagination.
     *
     * Helper method for cursor pagination.
     *
     * @param mixed $value Cursor value
     * @param string $column Column name
     * @return string Encoded cursor
     */
    protected function encodeCursor(mixed $value, string $column): string
    {
        $data = [
            'column' => $column,
            'value' => $value,
            'ts' => now()->getTimestamp(),
        ];

        return base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Decode cursor value from pagination.
     *
     * Helper method for cursor pagination.
     *
     * @param string $cursor Encoded cursor
     * @param string $column Expected column name
     * @return mixed|null Decoded cursor value or null if invalid
     */
    protected function decodeCursor(string $cursor, string $column): mixed
    {
        try {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false) {
                return null;
            }

            $data = json_decode($decoded, true);
            if (!is_array($data) || !isset($data['value'])) {
                return null;
            }

            // Security: Validate column matches (prevents column injection)
            if (isset($data['column']) && $data['column'] !== $column) {
                return null;
            }

            return $data['value'];
        } catch (\Throwable $e) {
            // Invalid cursor format - return null to start from beginning
            return null;
        }
    }

    // =========================================================================
    // EAGER LOADING OPTIMIZATION HELPERS
    // =========================================================================

    /**
     * Recursively search for WHERE IN clause in nested WHERE conditions.
     *
     * Used by relationships to detect eager loading patterns and apply
     * window function optimizations when needed.
     *
     * IMPORTANT: Default maxDepth of 25 supports very complex nested queries
     * while preventing stack overflow attacks. Real-world queries rarely exceed 5-10 levels.
     *
     * @param array $whereList List of WHERE clauses
     * @param string $tableName Table name to search for in WHERE IN
     * @param string|null $columnName Optional column name to match (default: any column)
     * @param int $maxDepth Maximum recursion depth (default: 25)
     * @param int $currentDepth Current recursion depth (internal)
     * @return bool True if WHERE IN found, false otherwise
     */
    protected function findWhereInRecursive(
        array $whereList,
        string $tableName,
        ?string $columnName = null,
        int $maxDepth = 25,
        int $currentDepth = 0
    ): bool {
        // Recursion depth limit to prevent stack overflow
        // Max depth 25 is generous - typical queries have 3-5 levels max
        if ($currentDepth >= $maxDepth) {
            return false;
        }

        foreach ($whereList as $whereClause) {
            $type = strtolower($whereClause['type'] ?? '');

            // Direct WHERE IN - check if it matches our criteria
            if ($type === 'in') {
                // If tableName is empty, match any WHERE IN (used by HasMany/MorphMany)
                if ($tableName === '') {
                    return true;
                }

                $column = $whereClause['column'] ?? '';
                // Optimize: Single str_contains check if no specific column
                if ($columnName === null) {
                    if (str_contains($column, $tableName)) {
                        return true;
                    }
                } else {
                    // Cache str_contains results to avoid repeated calls
                    $hasTable = str_contains($column, $tableName);
                    if ($hasTable && str_contains($column, $columnName)) {
                        return true;
                    }
                }
            }

            // Nested WHERE closure - recurse into it
            if ($type === 'nested' && isset($whereClause['query'])) {
                $nestedQueryBuilder = $whereClause['query'];
                if ($nestedQueryBuilder instanceof QueryBuilder) {
                    if ($this->findWhereInRecursive(
                        $nestedQueryBuilder->getWheres(),
                        $tableName,
                        $columnName,
                        $maxDepth,
                        $currentDepth + 1
                    )) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if custom columns array should be treated as default selection.
     *
     * When QueryBuilder has no explicit select(), getColumns() returns ["*"].
     * This helper standardizes the check across all relationship types.
     *
     * Edge cases handled:
     * - `[]` - No select, use default
     * - `['*']` - Wildcard select, use default
     * - `['*', 'some_column']` - Mixed with wildcard, use default
     * - `['col1', 'col2']` - Custom columns only, use custom
     *
     * @param array $columns Columns array from QueryBuilder::getColumns()
     * @return bool True if should use default table.* selection
     */
    protected function shouldUseDefaultSelect(array $columns): bool
    {
        // Empty or exact ['*'] - use default
        if (empty($columns) || $columns === ['*']) {
            return true;
        }

        // Check if wildcard exists anywhere in array (handles ['*', 'col'] edge case)
        // If user explicitly added '*', they want all columns, so use default table-qualified select
        return in_array('*', $columns, true);
    }

    // =========================================================================
    // MAGIC METHODS - QUERY BUILDER PROXY
    // =========================================================================

    /**
     * Forward method calls to the underlying query builder.
     *
     * This allows relationship instances to act as query builders in eager loading callbacks.
     * Supports:
     * - Query builder methods: $query->where(), $query->orderBy(), $query->limit(), etc.
     * - Local scopes: $query->published(), $query->active(), etc.
     *
     * @param string $method Method name
     * @param array $parameters Method parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        // Check if this is a local scope call on the related model
        // Local scopes are defined as scopeXxx() methods in the model
        $relatedClass = $this->getRelatedClass();
        if (!empty($relatedClass) &&
            method_exists($relatedClass, 'hasLocalScope') &&
            call_user_func([$relatedClass, 'hasLocalScope'], $method)) {
            call_user_func([$relatedClass, 'applyLocalScope'], $this->query, $method, ...$parameters);
            return $this;
        }

        // Forward to query builder and return $this for fluent chaining
        $result = $this->query->$method(...$parameters);

        // If query builder returns itself, return the relationship instance instead
        // This maintains fluent interface for method chaining
        return $result === $this->query ? $this : $result;
    }
}
