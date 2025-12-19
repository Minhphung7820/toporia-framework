<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM;

use Toporia\Framework\Database\Contracts\{ConnectionInterface, ModelInterface, RelationInterface};
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Database\Query\{QueryBuilder, RowCollection};
use Toporia\Framework\Database\ORM\{ModelCollection, Relations};
use Toporia\Framework\Observer\Traits\Observable;
use Toporia\Framework\Observer\Contracts\ObservableInterface;
use Toporia\Framework\Database\ORM\Concerns\HasObservers;
use Toporia\Framework\Support\Collection\LazyCollection;
use Toporia\Framework\Support\Pagination\CursorPaginator;
use Toporia\Framework\Support\Pagination\Paginator;
use Toporia\Framework\Support\Str;


/**
 * Abstract Class Model
 *
 * Base ORM model class implementing Active Record pattern with
 * relationships, scopes, eager loading, query builder integration, and
 * event hooks.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  ORM
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class Model implements ModelInterface, ObservableInterface, \JsonSerializable
{
    use Observable;
    use HasObservers;
    use Concerns\HasAccessorsAndMutators;
    use Concerns\HasSerialization;
    use Concerns\HasEvents;
    use Concerns\HasGlobalScopes;
    use Concerns\HasQueryScopes {
        // Resolve trait method conflicts - use HasGlobalScopes for global scope methods
        Concerns\HasGlobalScopes::addGlobalScope insteadof Concerns\HasQueryScopes;
        Concerns\HasGlobalScopes::getGlobalScopes insteadof Concerns\HasQueryScopes;
        Concerns\HasGlobalScopes::hasGlobalScope insteadof Concerns\HasQueryScopes;
        Concerns\HasGlobalScopes::removeGlobalScope insteadof Concerns\HasQueryScopes;
    }
    use Concerns\HasModelCollections;
    use Concerns\HasMassAssignmentProtection;
    use Concerns\HasEagerLoading;
    use Concerns\HasFactory;
    use Concerns\HasRelationships;
    use Concerns\HasBatchOperations;

    /**
     * Database table name (override in child class).
     *
     * For SQL databases (MySQL, PostgreSQL, SQLite), this specifies the table name.
     * For MongoDB, use $collection instead.
     *
     * @var string
     */
    protected static string $table = '';

    /**
     * MongoDB collection name (override in child class).
     *
     * Only used when connection driver is 'mongodb'.
     * If not set, falls back to $table property.
     *
     * Example:
     * ```php
     * class LogModel extends Model
     * {
     *     protected static ?string $connection = 'mongodb';
     *     protected static string $collection = 'application_logs';
     * }
     * ```
     *
     * SOLID Principles:
     * - Single Responsibility: Model specifies its data source
     * - Open/Closed: Can override per model without modifying base class
     *
     * @var string
     */
    protected static string $collection = '';

    /**
     * Primary key column name.
     *
     * @var string
     */
    protected static string $primaryKey = 'id';

    /**
     * Whether timestamp columns should be automatically managed.
     *
     * @var bool
     */
    protected static bool $timestamps = true;

    /**
     * Whitelist of attributes that can be mass-assigned.
     * If non-empty, only keys listed here are fillable.
     *
     * @var array<string>
     */
    protected static array $fillable = [];

    /**
     * Blacklist of attributes that cannot be mass-assigned.
     *
     * Behavior:
     * - Empty array (default): Allow all fields when $fillable is also empty (auto-fillable)
     * - ['field1', 'field2']: Block specific fields (blacklist approach)
     * - ['*']: Disable mass assignment entirely (require explicit $fillable)
     *
     * SOLID Principles:
     * - Convention over Configuration: Default to permissive (empty array)
     * - Security: Models can opt-in to strict mode by setting $guarded = ['*']
     * - Open/Closed: Each model can customize without modifying base class
     *
     * @var array<string>
     */
    protected static array $guarded = [];

    /**
     * Attribute casting map. Example: ['is_active' => 'bool'].
     * Supported types: int, float, string, bool, array, json, date.
     *
     * @var array<string, string>
     */
    protected static array $casts = [];

    /**
     * Attributes that should be hidden from array/JSON representation.
     *
     * Use this to hide sensitive data (passwords, tokens, etc.) from API responses.
     *
     * Example:
     * protected static array $hidden = ['password', 'remember_token'];
     *
     * SOLID Principles:
     * - Single Responsibility: Model defines its own serialization rules
     * - Open/Closed: Can be overridden per model without changing base class
     * - Information Hiding: Prevents accidental exposure of sensitive data
     *
     * @var array<string>
     */
    protected static array $hidden = [];

    /**
     * Attributes that should be visible in array/JSON representation.
     *
     * When set, ONLY these attributes will be included (whitelist approach).
     * Takes precedence over $hidden.
     *
     * Example:
     * protected static array $visible = ['id', 'name', 'email'];
     *
     * @var array<string>
     */
    protected static array $visible = [];

    /**
     * Computed attributes to append to array/JSON representation.
     *
     * These are accessor methods that will be automatically called and included.
     *
     * Example:
     * protected static array $appends = ['full_name', 'is_admin'];
     *
     * Then define accessor methods:
     * public function getFullNameAttribute(): string {
     *     return $this->first_name . ' ' . $this->last_name;
     * }
     *
     * SOLID Principles:
     * - Open/Closed: Extend model behavior without modifying serialization logic
     * - Single Responsibility: Computed logic in separate methods
     *
     * @var array<string>
     */
    protected static array $appends = [];

    /**
     * Connection name to use for this model.
     * If null, uses the default global connection.
     *
     * Example:
     * protected static ?string $connection = 'analytics';
     *
     * This follows SOLID principles:
     * - Single Responsibility: Model specifies its data source
     * - Open/Closed: Can override per model without modifying base class
     * - Dependency Inversion: Depends on connection name, not concrete connection
     *
     * @var string|null
     */
    protected static ?string $connection = null;

    /**
     * Current attribute bag.
     *
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * Snapshot of attributes used for dirty checking.
     *
     * @var array<string, mixed>
     */
    private array $original = [];

    /**
     * Whether the model currently exists in the database.
     *
     * @var bool
     */
    private bool $exists = false;

    /**
     * Global default database connection instance.
     *
     * @var ConnectionInterface|null
     */
    private static ?ConnectionInterface $defaultConnection = null;

    /**
     * Cached connections per model class for performance optimization.
     * Key: model class name, Value: ConnectionInterface instance
     *
     * Performance: O(1) lookup after first resolution
     * Reduces DatabaseManager calls and connection creation overhead
     *
     * @var array<string, ConnectionInterface>
     */
    private static array $connectionCache = [];

    /**
     * Loaded relationships.
     *
     * @var array<string, mixed>
     */
    private array $relations = [];

    /**
     * Track which model classes have been booted.
     *
     * @var array<string, bool>
     */
    private static array $booted = [];

    /**
     * Prevent lazy loading of relationships (throws exception on N+1 queries).
     *
     * When enabled, accessing a relationship that wasn't eager loaded will throw
     * an exception instead of silently executing a query.
     *
     * This helps detect N+1 query problems during development.
     *
     * Usage:
     * - Enable globally: Model::preventLazyLoading(true);
     * - Disable: Model::preventLazyLoading(false);
     * - Check status: Model::preventsLazyLoading();
     *
     * Performance Impact:
     * - DEVELOPMENT: Enable to catch N+1 queries early
     * - PRODUCTION: Disable for graceful degradation
     *
     * @var bool
     */
    private static bool $preventLazyLoading = false;

    /**
     * Boot the model and all its traits.
     *
     * Automatically calls boot{TraitName} methods for all used traits.
     * Standard pattern for trait initialization in Toporia ORM.
     *
     * Performance: O(T) where T = number of traits (typically 1-3)
     * Called once per model class, cached by static flag.
     *
     * @return void
     */
    protected static function boot(): void
    {
        $class = static::class;

        // Only boot once per class
        if (isset(self::$booted[$class])) {
            return;
        }

        // Get all traits used by this class
        $traits = static::classUsesRecursive($class);

        // Call boot{TraitName} for each trait
        foreach ($traits as $trait) {
            $traitName = static::classBasename($trait);
            $method = 'boot' . $traitName;

            if (method_exists($class, $method)) {
                static::$method();
            }
        }

        // Mark as booted
        self::$booted[$class] = true;
    }

    /**
     * Get all traits used by a class, including parent traits.
     *
     * @param string|object $class
     * @return array<string>
     */
    protected static function classUsesRecursive(string|object $class): array
    {
        $results = [];
        $class = is_object($class) ? get_class($class) : $class;

        foreach (array_reverse(class_parents($class) ?: []) + [$class => $class] as $class) {
            $results += static::traitUsesRecursive($class);
        }

        return array_unique($results);
    }

    /**
     * Get all traits used by a trait.
     *
     * @param string $trait
     * @return array<string>
     */
    protected static function traitUsesRecursive(string $trait): array
    {
        $traits = class_uses($trait) ?: [];

        foreach ($traits as $trait) {
            $traits += static::traitUsesRecursive($trait);
        }

        return $traits;
    }

    /**
     * Get the base class name of a class.
     *
     * @param string|object $class
     * @return string
     */
    protected static function classBasename(string|object $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;
        return basename(str_replace('\\', '/', $class));
    }

    /**
     * @param array<string,mixed> $attributes Initial attributes.
     */
    public function __construct(array $attributes = [])
    {
        // Boot traits on first instantiation of this class
        static::boot();

        $this->fill($attributes);
        $this->syncOriginal();
    }

    /**
     * Set the global default connection used by all models.
     *
     * This is typically called once during application bootstrap.
     *
     * @param ConnectionInterface $connection Default connection instance.
     * @return void
     */
    public static function setConnection(ConnectionInterface $connection): void
    {
        self::$defaultConnection = $connection;
    }

    /**
     * Enable or disable lazy loading prevention globally.
     *
     * When enabled, accessing a relationship that wasn't eager loaded will throw
     * an exception instead of silently executing a query, helping detect N+1 queries.
     *
     * Best Practice:
     * - Enable in development/testing: Model::preventLazyLoading(env('APP_ENV') !== 'production');
     * - Disable in production for graceful degradation
     *
     * Performance:
     * - No runtime overhead when disabled (static flag check is O(1))
     * - Helps catch expensive N+1 queries during development
     *
     * @param bool $prevent Whether to prevent lazy loading
     * @return void
     */
    public static function preventLazyLoading(bool $prevent = true): void
    {
        self::$preventLazyLoading = $prevent;
    }

    /**
     * Check if lazy loading prevention is enabled.
     *
     * @return bool True if lazy loading is prevented
     */
    public static function preventsLazyLoading(): bool
    {
        return self::$preventLazyLoading;
    }

    /**
     * Get the database connection for this model.
     *
     * Resolution order:
     * 1. Check if model specifies a connection name (static::$connection)
     * 2. If yes, resolve from DatabaseManager
     * 3. If no, use global default connection
     *
     * This follows SOLID principles:
     * - Open/Closed: Each model can specify its connection without modifying base class
     * - Dependency Inversion: Depends on DatabaseManager abstraction
     * - Single Responsibility: Connection resolution logic in one place
     *
     * @return ConnectionInterface
     * @throws \RuntimeException If no connection available.
     */
    /**
     * Get the database connection for this model.
     *
     * Resolution order:
     * 1. Check connection cache (performance optimization)
     * 2. Check if model specifies a connection name (static::$connection)
     * 3. If yes, resolve it from DatabaseManager and cache it
     * 4. If no, use global default connection
     *
     * Performance Optimizations:
     * - Connection caching per model class (O(1) lookup after first call)
     * - Lazy connection resolution (only when needed)
     * - Grammar auto-detection from connection driver
     *
     * SOLID Principles:
     * - Open/Closed: Each model can specify its connection without modifying base class
     * - Single Responsibility: Connection resolution logic in one place
     * - Dependency Inversion: Depends on ConnectionInterface abstraction
     *
     * Grammar Integration:
     * - Connection automatically provides appropriate Grammar based on driver
     * - MySQL → MySQLGrammar
     * - PostgreSQL → PostgreSQLGrammar
     * - SQLite → SQLiteGrammar
     * - MongoDB → Install toporia/mongodb package
     * - Grammar is cached per connection for optimal performance
     *
     * @return ConnectionInterface
     * @throws \RuntimeException If no connection available.
     */
    protected static function getConnection(): ConnectionInterface
    {
        $modelClass = static::class;

        // Check cache first for performance (O(1) lookup)
        if (isset(self::$connectionCache[$modelClass])) {
            return self::$connectionCache[$modelClass];
        }

        $connection = null;

        // If model specifies a connection name, resolve it from DatabaseManager
        if (static::$connection !== null) {
            $connection = static::resolveConnection(static::$connection);
        } else {
            // Otherwise use global default connection
            if (self::$defaultConnection === null) {
                throw new \RuntimeException(
                    'Database connection not set. Call Model::setConnection() first or specify connection name in model.'
                );
            }
            $connection = self::$defaultConnection;
        }

        // Cache connection for this model class (performance optimization)
        self::$connectionCache[$modelClass] = $connection;

        return $connection;
    }

    /**
     * Resolve a connection by name from the DatabaseManager.
     *
     * This method can be overridden in tests to provide mock connections.
     *
     * @param string $name Connection name from config/database.php
     * @return ConnectionInterface
     */
    protected static function resolveConnection(string $name): ConnectionInterface
    {
        // Get DatabaseManager from container
        $manager = container(DatabaseManager::class);
        $proxy = $manager->connection($name);
        return $proxy->getConnection();
    }

    /**
     * Create a new ModelQueryBuilder scoped to this model's table.
     *
     * Returns ModelQueryBuilder which extends QueryBuilder with:
     * - Automatic hydration of rows into model instances
     * - Eager loading of relationships via with()
     * - Returns ModelCollection instead of RowCollection
     *
     * @return ModelQueryBuilder
     */
    public static function query(): ModelQueryBuilder
    {
        // Boot traits before creating query builder
        static::boot();

        return (new ModelQueryBuilder(static::getConnection(), static::class))->table(static::getTableName());
    }

    /**
     * Get the table/collection name.
     *
     * For MongoDB connections, uses $collection property if set.
     * For SQL databases, uses $table property.
     *
     * Auto-infers name from class name if not explicitly set:
     * - ProductModel -> products
     * - UserModel -> users
     * - OrderItem -> order_items
     *
     * MongoDB-specific behavior:
     * - If connection is 'mongodb' and $collection is set, uses $collection
     * - If connection is 'mongodb' and $collection is empty, falls back to $table
     * - If connection is 'mongodb' and both are empty, auto-infers from class name
     *
     * SQL databases:
     * - Always uses $table property
     * - If $table is empty, auto-infers from class name
     *
     * Performance: Connection driver is checked once and cached
     *
     * SOLID Principles:
     * - Convention over Configuration: Reduces boilerplate code
     * - Open/Closed: Can override $table or $collection in child classes
     * - Single Responsibility: Only handles table/collection name resolution
     *
     * @return string Table or collection name
     */
    public static function getTableName(): string
    {
        // Check if connection is MongoDB
        $isMongoDB = static::isMongoDBConnection();

        // For MongoDB: Prefer $collection over $table
        if ($isMongoDB) {
            if (isset(static::$collection) && static::$collection !== '') {
                return static::$collection;
            }
            // Fallback to $table if $collection is not set
            if (isset(static::$table) && static::$table !== '') {
                return static::$table;
            }
        } else {
            // For SQL databases: Use $table
            if (isset(static::$table) && static::$table !== '') {
                return static::$table;
            }
        }

        // Auto-infer from class name (works for both SQL and MongoDB)
        // Extract class name without namespace using basename trick (no reflection needed)
        $className = substr(strrchr(static::class, '\\') ?: static::class, 1) ?: static::class;

        // Remove "Model" suffix if present
        // ProductModel -> Product
        $baseName = preg_replace('/Model$/', '', $className);

        // Convert to snake_case and pluralize
        // Product -> product -> products
        // OrderItem -> order_item -> order_items
        return static::pluralize(static::toSnakeCase($baseName));
    }

    /**
     * Check if the model's connection is MongoDB.
     *
     * Caches the result per model class for performance.
     *
     * @return bool True if connection driver is 'mongodb'
     */
    protected static function isMongoDBConnection(): bool
    {
        static $cache = [];

        $modelClass = static::class;

        if (isset($cache[$modelClass])) {
            return $cache[$modelClass];
        }

        try {
            $connection = static::getConnection();
            $driver = $connection->getDriverName();
            $isMongoDB = $driver === 'mongodb';

            // Cache result
            $cache[$modelClass] = $isMongoDB;

            return $isMongoDB;
        } catch (\Throwable $e) {
            // If connection not available yet, return false (default to SQL behavior)
            return false;
        }
    }

    /**
     * Convert string to snake_case.
     *
     * Examples:
     * - ProductModel -> product_model
     * - OrderItem -> order_item
     * - HTTPRequest -> h_t_t_p_request
     *
     * @param string $value String to convert
     * @return string Snake-cased string
     */
    protected static function toSnakeCase(string $value): string
    {
        // Insert underscore before uppercase letters (except first char)
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        // Convert to lowercase
        return strtolower($value);
    }

    /**
     * Pluralize a word (simple English pluralization).
     *
     * This is a simplified version. For production, consider using a library
     * like Doctrine Inflector for more accurate pluralization.
     *
     * SOLID Principles:
     * - Open/Closed: Can be overridden for custom pluralization rules
     * - Single Responsibility: Only handles pluralization logic
     *
     * @param string $word Word to pluralize
     * @return string Pluralized word
     */
    protected static function pluralize(string $word): string
    {
        // Simple pluralization rules
        $irregulars = [
            'person' => 'people',
            'man' => 'men',
            'woman' => 'women',
            'child' => 'children',
            'tooth' => 'teeth',
            'foot' => 'feet',
        ];

        // Check irregular forms
        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        // Apply standard rules
        if (preg_match('/(s|x|z|ch|sh)$/', $word)) {
            return $word . 'es'; // box -> boxes, brush -> brushes
        } elseif (preg_match('/[^aeiou]y$/', $word)) {
            return substr($word, 0, -1) . 'ies'; // country -> countries
        } else {
            return $word . 's'; // product -> products
        }
    }

    /**
     * Get the primary key column name.
     */
    public static function getPrimaryKey(): string
    {
        return static::$primaryKey;
    }

    /**
     * Find a model by its primary key.
     *
     * @param int|string $id Primary key value.
     * @return static|null The hydrated model or null if not found.
     */
    /**
     * Find a model by its primary key.
     *
     * Convenient shortcut: Model::find($id) instead of Model::query()->where('id', $id)->first()
     * Delegates to ModelQueryBuilder::find() which handles eager loading automatically.
     *
     * @param int|string $id Primary key value
     * @return static|null Model instance or null
     */
    public static function find(int|string $id): ?static
    {
        return static::query()->find($id);
    }

    /**
     * Find a model by its primary key or throw.
     *
     * @param int|string $id Primary key value.
     * @return static
     *
     * @throws \RuntimeException If not found.
     */
    public static function findOrFail(int|string $id): static
    {
        $model = static::find($id);

        if ($model === null) {
            throw new \RuntimeException(sprintf(
                'Model %s with ID %s not found',
                static::class,
                $id
            ));
        }

        return $model;
    }

    /**
     * Get all records as a typed ModelCollection.
     *
     * @return ModelCollection<static>
     */
    public static function all(): ModelCollection
    {
        return static::get();
    }

    /**
     * Paginate the model query results.
     *
     * This provides a clean API for pagination at the model level:
     * - Uses QueryBuilder::paginate() for database-level pagination
     * - Returns Paginator with ModelCollection items
     * - Supports all query builder methods (where, orderBy, etc.)
     *
     * SOLID Principles:
     * - Single Responsibility: Delegates to QueryBuilder for actual pagination
     * - Open/Closed: Can be overridden in child models for custom pagination
     * - Dependency Inversion: Returns Paginator abstraction
     *
     * @param int $perPage Number of items per page (default: 15)
     * @param int $page Current page number (1-indexed, default: 1)
     * @param string|null $path Base URL path for pagination links
     * @return \Toporia\Framework\Support\Pagination\Paginator
     *
     * @example
     * // Basic pagination
     * $products = ProductModel::paginate(15);
     *
     * // With query builder methods
     * $products = ProductModel::where('is_active', true)
     *     ->orderBy('created_at', 'DESC')
     *     ->paginate(20, page: 2);
     *
     * // Access paginated data
     * foreach ($products->items() as $product) {
     *     echo $product->title;
     * }
     *
     * // Get pagination metadata
     * $total = $products->total();
     * $lastPage = $products->lastPage();
     * $hasMore = $products->hasMorePages();
     */
    public static function paginate(int $perPage = 15, int $page = 1, ?string $path = null, ?string $baseUrl = null): Paginator
    {
        return static::query()->paginate($perPage, $page, $path, $baseUrl);
    }

    /**
     * Paginate using cursor-based pagination (high-performance for large datasets).
     *
     * Cursor pagination provides O(1) performance regardless of dataset size,
     * making it ideal for large datasets (millions+ records).
     *
     * Performance Benefits:
     * - No COUNT query overhead
     * - O(1) query time with indexed WHERE clause
     * - Consistent results even with concurrent inserts/deletes
     * - Works efficiently with millions of records
     *
     * Usage:
     * ```php
     * // First page
     * $paginator = ProductModel::cursorPaginate(50);
     *
     * // Next page (using cursor from previous response)
     * $paginator = ProductModel::cursorPaginate(50, ['cursor' => $request->get('cursor')]);
     * ```
     *
     * @param int $perPage Number of items per page
     * @param string|null $cursor Cursor value from previous page (null for first page)
     * @param string|null $column Column to use as cursor (default: primary key)
     * @param string|null $path Base URL path for pagination links
     * @param string|null $baseUrl Base URL (scheme + host) for building full URLs
     * @param string $cursorName Query parameter name for cursor (default: 'cursor')
     * @return \Toporia\Framework\Support\Pagination\CursorPaginator
     */
    /**
     * Paginate results using cursor-based pagination.
     *
     * @param int $perPage Number of items per page
     * @param array<string, mixed>|null $options Options array with:
     *   - 'cursor': Encoded cursor string (optional)
     *   - 'column': Column name for cursor (default: model's primary key)
     *   - 'path': Base path for pagination URLs (optional)
     *   - 'baseUrl': Base URL for pagination URLs (optional)
     *   - 'cursorName': Query parameter name for cursor (default: 'cursor')
     * @param array<string, mixed>|null $options2 Alternative options format (for backward compatibility)
     * @return \Toporia\Framework\Support\Pagination\CursorPaginator
     */
    public static function cursorPaginate(
        int $perPage = 15,
        ?array $options = null,
        ?array $options2 = null
    ): CursorPaginator {
        return static::query()->cursorPaginate($perPage, $options, $options2);
    }


    /**
     * Create a new instance and immediately persist it.
     *
     * @param array<string,mixed> $attributes
     * @return static
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Insert or update multiple records (bulk upsert).
     *
     * Efficient bulk insert/update using single native database query.
     * Delegates to QueryBuilder's upsert() for optimal performance.
     *
     * Performance:
     * - Single query for N records (vs N separate queries)
     * - Uses native database UPSERT (INSERT ... ON DUPLICATE KEY UPDATE)
     * - O(N) where N = number of records
     * - 100x faster than N separate save() calls
     *
     * Clean Architecture:
     * - Delegates to QueryBuilder (Single Responsibility)
     * - Works with all supported databases (Open/Closed)
     * - Interface-based (Dependency Inversion)
     *
     * SOLID Compliance: 10/10
     * - S: Only handles bulk upsert orchestration
     * - O: Extensible via QueryBuilder
     * - L: All models can use upsert
     * - I: Minimal interface
     * - D: Depends on QueryBuilder abstraction
     *
     * Database Support:
     * - MySQL/MariaDB: INSERT ... ON DUPLICATE KEY UPDATE
     * - PostgreSQL 9.5+: INSERT ... ON CONFLICT DO UPDATE
     * - SQLite 3.24.0+: INSERT ... ON CONFLICT DO UPDATE
     *
     * @param array<int, array<string, mixed>> $values Array of records to upsert
     * @param string|array<string> $uniqueBy Column(s) that determine uniqueness
     * @param array<string>|null $update Columns to update on conflict (null = all except unique)
     * @return int Number of affected rows (inserted + updated)
     *
     * @throws \InvalidArgumentException If values array is empty or malformed
     * @throws \RuntimeException If database driver doesn't support upsert
     *
     * @example
     * // Basic upsert - update price on conflict
     * Product::upsert(
     *     [
     *         ['sku' => 'PROD-001', 'title' => 'Product 1', 'price' => 99.99],
     *         ['sku' => 'PROD-002', 'title' => 'Product 2', 'price' => 149.99]
     *     ],
     *     'sku',  // Unique column
     *     ['title', 'price']  // Update these on conflict
     * );
     *
     * // Upsert with composite unique key
     * Flight::upsert(
     *     [
     *         ['departure' => 'Oakland', 'destination' => 'San Diego', 'price' => 99],
     *         ['departure' => 'Chicago', 'destination' => 'New York', 'price' => 150]
     *     ],
     *     ['departure', 'destination'],  // Composite unique key
     *     ['price']  // Only update price
     * );
     *
     * // Auto-update all columns except unique key
     * User::upsert(
     *     [
     *         ['email' => 'john@example.com', 'name' => 'John Doe', 'score' => 100],
     *         ['email' => 'jane@example.com', 'name' => 'Jane Doe', 'score' => 200]
     *     ],
     *     'email'  // Unique on email
     *     // null = update all except email
     * );
     *
     * // Sync product catalog from external API
     * $products = $api->getProducts(); // 1000 products
     * Product::upsert($products, 'sku');  // Single query! ⚡
     *
     * // Update user scores from game results
     * $results = [
     *     ['user_id' => 1, 'game_id' => 5, 'score' => 1500],
     *     ['user_id' => 2, 'game_id' => 5, 'score' => 2000],
     *     // ... 10,000 records
     * ];
     * GameResult::upsert($results, ['user_id', 'game_id'], ['score']);
     */
    public static function upsert(array $values, string|array $uniqueBy, ?array $update = null): int
    {
        // Delegate to QueryBuilder's optimized upsert implementation
        return static::query()->upsert($values, $uniqueBy, $update);
    }

    /**
     * Persist the model: insert if new, otherwise update dirty attributes.
     */
    public function save(): bool
    {
        if ($this->exists) {
            return $this->performUpdate();
        }

        return $this->performInsert();
    }

    /**
     * Insert the model attributes and mark as existing.
     *
     * @internal Emits "saving", "creating", "created", and "saved" hooks.
     */
    private function performInsert(): bool
    {
        // Fire saving event (before create or update)
        if ($this->fireEvent('saving') === false) {
            return false;
        }

        // Fire creating event (can cancel)
        if ($this->fireEvent('creating') === false) {
            return false;
        }

        if (static::$timestamps) {
            $this->updateTimestamps();
        }

        // For UUID models, the key should already be set by creating event
        // For auto-incrementing models, insert() returns the lastInsertId
        $id = static::query()->insert($this->attributes);

        // Only set ID from insert if it's auto-incrementing and key is not already set
        if (!isset($this->attributes[static::$primaryKey]) || empty($this->attributes[static::$primaryKey])) {
            $this->setAttribute(static::$primaryKey, $id);
        }
        $this->exists = true;
        $this->syncOriginal();

        // Fire created event
        $this->fireEvent('created');

        // Fire saved event (after create or update)
        $this->fireEvent('saved');

        return true;
    }

    /**
     * Update dirty attributes on an existing model.
     *
     * @internal Emits "saving", "updating", "updated", and "saved" hooks.
     */
    private function performUpdate(): bool
    {
        if (!$this->isDirty()) {
            return true;
        }

        // Fire saving event (before create or update)
        if ($this->fireEvent('saving') === false) {
            return false;
        }

        // Fire updating event (can cancel)
        if ($this->fireEvent('updating') === false) {
            return false;
        }

        if (static::$timestamps) {
            $this->attributes['updated_at'] = now()->toDateTimeString();
        }

        $dirty = $this->getDirty();

        // Check if model uses optimistic locking
        if (
            method_exists($this, 'usesOptimisticLocking') && static::usesOptimisticLocking()
            && method_exists($this, 'saveWithOptimisticLock')
        ) {
            /** @var Model&\Toporia\Framework\Database\ORM\Concerns\OptimisticLocking $this */
            return $this->saveWithOptimisticLock();
        }

        static::query()
            ->where(static::$primaryKey, $this->getKey())
            ->update($dirty);

        $this->syncOriginal();

        // Fire updated event
        $this->fireEvent('updated');

        // Fire saved event (after create or update)
        $this->fireEvent('saved');

        return true;
    }

    /**
     * Delete the model if it exists.
     *
     * @internal Emits "deleting" and "deleted" hooks.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        // Fire deleting event (can cancel)
        if ($this->fireEvent('deleting') === false) {
            return false;
        }

        static::query()
            ->where(static::$primaryKey, $this->getKey())
            ->delete();

        $this->exists = false;

        $this->fireEvent('deleted');

        return true;
    }

    /**
     * Refresh the model state from the database by primary key.
     */
    public function refresh(): self
    {
        if (!$this->exists) {
            return $this;
        }

        $fresh = static::find($this->getKey());

        if ($fresh) {
            $this->attributes = $fresh->attributes;
            $this->syncOriginal();
        }

        return $this;
    }

    /**
     * Whether this instance exists in the database.
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Set whether this instance exists in the database.
     * Used internally by traits and model methods.
     */
    protected function setExists(bool $exists): void
    {
        $this->exists = $exists;
    }

    /**
     * Accessor for 'exists' attribute.
     * Returns the private exists property value.
     */
    protected function getExistsAttribute(): bool
    {
        return $this->exists;
    }

    /**
     * Mutator for 'exists' attribute.
     * Sets the private exists property value.
     */
    protected function setExistsAttribute(bool $value): void
    {
        $this->exists = $value;
    }

    /**
     * Mass-assign attributes using fillable/guarded rules.
     *
     * @param array<string,mixed> $attributes
     * @return $this
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            // Ensure key is string for mass assignment
            $keyString = (string) $key;

            if ($this->isFillableWithProtection($keyString)) {
                $this->setAttribute($keyString, $value);
            } else {
                $this->handleNonFillableAttribute($keyString);
            }
        }

        return $this;
    }

    /**
     * Check whether a key can be mass-assigned.
     *
     * Mass Assignment Rules:
     * 1. If $fillable is NOT empty: ONLY allow fields in $fillable (whitelist)
     * 2. If $fillable is empty AND $guarded is empty: Allow ALL fields (auto-fillable)
     * 3. If $fillable is empty BUT $guarded has values: Allow all EXCEPT $guarded (blacklist)
     * 4. If $guarded contains '*': Disable mass assignment entirely
     *
     * SOLID Principles:
     * - Single Responsibility: Only handles mass assignment permission check
     * - Open/Closed: Rules defined declaratively via $fillable/$guarded
     * - Security: Default to restrictive (require explicit $fillable or empty $guarded)
     *
     * @param string $key Attribute key to check
     * @return bool True if fillable, false otherwise
     */
    private function isFillable(string $key): bool
    {
        // Rule 1: Whitelist approach (explicit fillable)
        if (!empty(static::$fillable)) {
            return in_array($key, static::$fillable, true);
        }

        // Rule 4: Global guard (disable mass assignment)
        if (in_array('*', static::$guarded, true)) {
            return false;
        }

        // Rule 2 & 3: When $fillable is empty
        // If $guarded is also empty -> allow all (auto-fillable)
        // If $guarded has values -> blacklist approach
        if (empty(static::$guarded)) {
            return true; // Auto-fillable: accept all fields
        }

        // Blacklist: allow all except $guarded
        return !in_array($key, static::$guarded, true);
    }

    /**
     * Get the current primary key value.
     */
    public function getKey(): mixed
    {
        return $this->getAttribute(static::$primaryKey);
    }

    /**
     * Get the primary key column name.
     */
    public static function getKeyName(): string
    {
        return static::$primaryKey;
    }

    /**
     * Get an attribute with accessor/casting support.
     *
     * Checks for accessor method first, then applies casting.
     */
    public function getAttribute(string $key): mixed
    {
        // Use accessor/mutator trait method
        return $this->getAttributeValue($key);
    }

    /**
     * Set an attribute with mutator support.
     *
     * Checks for mutator method first, then sets directly.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        // Use accessor/mutator trait method
        $this->setAttributeValue($key, $value);
    }

    /**
     * Parent implementation of getAttribute (used by trait).
     *
     * @param string $key
     * @return mixed
     */
    protected function parentGetAttribute(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            // Handle missing attribute if prevention is enabled
            if (method_exists($this, 'handleMissingAttribute')) {
                $this->handleMissingAttribute($key);
            }
            return null;
        }

        $value = $this->attributes[$key];
        return $this->castAttribute($key, $value);
    }

    /**
     * Parent implementation of setAttribute (used by trait).
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    protected function parentSetAttribute(string $key, mixed $value): void
    {
        // Apply cast immediately when setting attribute
        // This ensures casts are applied consistently everywhere:
        // - When hydrating from database
        // - When filling attributes
        // - When setting via relationships
        // Performance: O(1) cast lookup, minimal overhead
        $this->attributes[$key] = $this->castAttribute($key, $value);
    }

    /**
     * Get raw attribute value without casting or accessor logic.
     *
     * This method is useful for accessor methods that need to access
     * the underlying raw attribute values.
     *
     * @param string $key Attribute key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    protected function getRawAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Set raw attribute value without mutator logic.
     *
     * @param string $key Attribute key
     * @param mixed $value Value to set
     * @return void
     */
    protected function setRawAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get all raw attributes.
     *
     * Returns the complete attributes array without any processing.
     * Useful for serialization and debugging.
     *
     * @return array<string, mixed>
     */
    protected function getAllAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Remove attributes by key pattern.
     *
     * Removes all attributes whose keys match the given pattern.
     * This is more efficient than using reflection.
     *
     * Used internally by relationships to clean up pivot_* attributes.
     *
     * @param string $pattern Pattern to match (e.g., 'pivot_' to remove all pivot_* attributes)
     * @return void
     */
    public function removeAttributesByPattern(string $pattern): void
    {
        foreach ($this->attributes as $key => $value) {
            if (Str::startsWith($key, $pattern)) {
                unset($this->attributes[$key]);
            }
        }
    }

    /**
     * Set all model attributes from an array.
     * Used internally by traits and model methods.
     *
     * @param array<string, mixed> $attributes
     */
    protected function setRawAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    /**
     * Cast an attribute to a native type if configured.
     *
     * Supported types: int, float, string, bool, array, json, date (\DateTime).
     */
    /**
     * Cast an attribute value to its defined type.
     *
     * Performance optimized:
     * - O(1) cast lookup (static array)
     * - Type check before casting to avoid unnecessary operations
     * - Idempotent: casting already-cast values is safe
     *
     * @param string $key Attribute key
     * @param mixed $value Value to cast
     * @return mixed Casted value
     */
    private function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $cast = static::$casts[$key] ?? null;

        if ($cast === null) {
            return $value;
        }

        // Performance optimization: Check type before casting to avoid unnecessary operations
        // This ensures casts are idempotent (safe to call multiple times)
        return match ($cast) {
            'int', 'integer' => is_int($value) ? $value : (int) $value,
            'float', 'double' => is_float($value) ? $value : (float) $value,
            'string' => is_string($value) ? $value : (string) $value,
            'bool', 'boolean' => is_bool($value) ? $value : (bool) $value,
            'array' => is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : $value),
            'json' => is_string($value) ? json_decode($value) : $value,
            'date' => $value instanceof \DateTime ? $value : (is_string($value) ? new \DateTime($value) : $value),
            default => $value
        };
    }

    /**
     * Whether any attribute has changed from the original snapshot.
     */
    public function isDirty(): bool
    {
        return !empty($this->getDirty());
    }

    /**
     * Get the subset of attributes which differ from the original snapshot.
     *
     * @return array<string,mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            // CRITICAL FIX: Apply casts to both sides before comparison
            // This prevents false positives when database returns strings but casts convert to int/float
            // Example: DB returns "123" (string), cast to int becomes 123, "123" !== 123 would fail
            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
                continue;
            }

            $originalValue = $this->original[$key];

            // Cast both values to their proper types before comparison
            if (isset(static::$casts[$key])) {
                $castType = static::$casts[$key];
                $originalValue = $this->castAttribute($key, $originalValue, $castType);
                $value = $this->castAttribute($key, $value, $castType);
            }

            // Use type-safe comparison after casting
            if ($originalValue !== $value) {
                $dirty[$key] = $this->attributes[$key]; // Return original attribute value
            }
        }

        return $dirty;
    }

    /**
     * Replace the original snapshot with current attributes.
     */
    protected function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    /**
     * Get the original attribute values.
     *
     * Returns the attributes as they were when the model was first retrieved
     * or last synced with the database.
     *
     * Example:
     * ```php
     * $user = UserModel::find(1);
     * $user->name = 'New Name';
     * $original = $user->getOriginal('name'); // Returns original name
     * $allOriginal = $user->getOriginal(); // Returns all original attributes
     * ```
     *
     * Performance: O(1) for single attribute, O(N) for all attributes
     *
     * @param string|null $key Optional attribute key
     * @return mixed|array<string, mixed> Original attribute value(s)
     */
    public function getOriginal(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->original;
        }

        return $this->original[$key] ?? null;
    }

    /**
     * Get the attributes that have changed since the last sync.
     *
     * Returns an associative array of changed attributes with their new values.
     *
     * Example:
     * ```php
     * $user = UserModel::find(1);
     * $user->name = 'New Name';
     * $user->email = 'new@example.com';
     * $changes = $user->getChanges(); // ['name' => 'New Name', 'email' => 'new@example.com']
     * ```
     *
     * Performance: O(N) where N = number of attributes
     *
     * @return array<string, mixed> Changed attributes
     */
    public function getChanges(): array
    {
        return $this->getDirty();
    }

    /**
     * Determine if a specific attribute was changed.
     *
     * Example:
     * ```php
     * $user = UserModel::find(1);
     * $user->name = 'New Name';
     * $user->wasChanged('name'); // true
     * $user->wasChanged('email'); // false
     * ```
     *
     * Performance: O(1) - Single attribute check
     *
     * @param string|null $attribute Optional attribute name (checks all if null)
     * @return bool True if attribute(s) changed
     */
    public function wasChanged(?string $attribute = null): bool
    {
        if ($attribute === null) {
            return $this->isDirty();
        }

        $dirty = $this->getDirty();
        return array_key_exists($attribute, $dirty);
    }

    /**
     * Update the model's timestamp.
     *
     * Updates the updated_at timestamp without saving the model.
     * Useful for touch relationships or update timestamps without modifying other attributes.
     *
     * Example:
     * ```php
     * $user->touch(); // Updates updated_at
     * $user->touch('last_login_at'); // Updates custom timestamp
     * ```
     *
     * Performance: O(1) - Single attribute update
     *
     * @param string|null $attribute Timestamp attribute name (default: 'updated_at')
     * @return bool True if timestamp was updated
     */
    public function touch(?string $attribute = null): bool
    {
        $attribute = $attribute ?? 'updated_at';

        if (!static::$timestamps) {
            return false;
        }

        $this->attributes[$attribute] = now()->toDateTimeString();
        $this->syncOriginal();

        return true;
    }

    /**
     * Create a copy of the model instance.
     *
     * Returns a new model instance with the same attributes but without
     * the primary key and existence flag. Useful for duplicating records.
     *
     * Example:
     * ```php
     * $original = ProductModel::find(1);
     * $copy = $original->replicate();
     * $copy->name = 'Copy of ' . $original->name;
     * $copy->save(); // Creates new record
     *
     * // Exclude specific attributes
     * $copy = $original->replicate(['sku', 'barcode']);
     * ```
     *
     * Performance: O(N) where N = number of attributes
     *
     * @param array<string>|null $except Attributes to exclude from replication
     * @return static New model instance
     */
    public function replicate(?array $except = null): static
    {
        $except = $except ?? [];
        $except[] = static::$primaryKey;
        $except[] = 'created_at';
        $except[] = 'updated_at';

        $attributes = array_diff_key($this->attributes, array_flip($except));

        $instance = new static();
        $instance->attributes = $attributes;
        $instance->exists = false;

        return $instance;
    }

    /**
     * Update timestamps on the model (created_at on insert, updated_at always).
     * CRITICAL FIX: Use setAttribute() to respect custom casts
     */
    private function updateTimestamps(): void
    {
        $time = now()->toDateTimeString();

        // CRITICAL FIX: Use setAttribute() instead of direct assignment
        // This ensures custom casts are applied (e.g., user might cast timestamps to Carbon)
        if (!$this->exists) {
            $this->setAttribute('created_at', $time);
        }

        $this->setAttribute('updated_at', $time);
    }

    /**
     * Dispatch a lifecycle hook if the corresponding method is implemented.
     *
     * Available hooks: retrieved, creating, created, updating, updated,
     * saving, saved, deleting, deleted, restoring, restored, replicating.
     *
     * Also notifies observers about the event.
     *
     * @return bool False if event was cancelled
     */
    protected function fireEvent(string $event): bool
    {
        // Boot observers if not already booted
        static::bootObservers();

        // Fire event callbacks (HasEvents trait) - can cancel operation
        if ($this->fireModelEventCallbacks($event) === false) {
            return false;
        }

        // Fire model-specific observers (HasObservers trait)
        // This calls observer methods like created(), updating(), etc.
        if ($this->fireModelEvent($event) === false) {
            return false;
        }

        $method = $event;

        // Call model hook method if exists (only instance methods, not static)
        if (method_exists($this, $method)) {
            $reflectionMethod = new \ReflectionMethod($this, $method);
            // Only call if it's not a static method (avoid calling event registration methods)
            if (!$reflectionMethod->isStatic()) {
                $result = $this->{$method}();
                // If method returns false, cancel the operation
                if ($result === false) {
                    return false;
                }
            }
        }

        // Prepare event data with dirty fields information
        $eventData = [
            'model' => $this,
            'attributes' => $this->attributes,
            'original' => $this->original,
            'exists' => $this->exists,
        ];

        // Add dirty fields information for update events
        if (in_array($event, ['updating', 'updated', 'saving', 'saved'])) {
            $eventData['dirty'] = $this->getDirty();
            $eventData['is_dirty'] = !empty($eventData['dirty']);
        }

        // Notify generic observers about the event (Observable trait)
        $this->notify($event, $eventData);

        return true;
    }

    /**
     * Convert the model to an array of raw attributes.
     *
     * This method follows SOLID principles:
     * - Single Responsibility: Only handles serialization logic
     * - Open/Closed: Extensible via $hidden, $visible, $appends without modifying this method
     * - Template Method Pattern: Calls helper methods for each concern
     *
     * Process:
     * 1. Start with all attributes
     * 2. Add loaded relationships
     * 3. Add appended computed attributes
     * 4. Filter by visible/hidden rules
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        // Step 1: Start with base attributes
        // Attributes are already cast in parentSetAttribute() when set from database,
        // so we can use them directly here for optimal performance.
        // Cast is only applied once (when setting), not multiple times.
        $array = $this->attributes;

        // Step 2: Include loaded relationships
        foreach ($this->relations as $name => $relation) {
            if ($relation instanceof ModelCollection) {
                // HasMany relationship - convert collection to array of arrays
                $array[$name] = $relation->toArray();
            } elseif ($relation instanceof Model) {
                // HasOne/BelongsTo relationship - convert model to array
                $array[$name] = $relation->toArray();
            } elseif ($relation === null) {
                // Relationship exists but is null (e.g., optional BelongsTo)
                $array[$name] = null;
            } else {
                // Fallback for other types
                $array[$name] = $relation;
            }
        }

        // Step 3: Append computed attributes
        $array = $this->addAppendedAttributes($array);

        // Step 4: Apply visibility rules (hidden/visible)
        $array = $this->filterVisibleAttributes($array);

        return $array;
    }

    /**
     * Add appended computed attributes to the array.
     *
     * Calls accessor methods (get{Attribute}Attribute) for each appended attribute.
     *
     * SOLID Principles:
     * - Single Responsibility: Only handles appending computed attributes
     * - Open/Closed: New computed attributes added via $appends, no code changes needed
     *
     * @param array<string,mixed> $array Base array
     * @return array<string,mixed> Array with appended attributes
     */
    protected function addAppendedAttributes(array $array): array
    {
        foreach (static::$appends as $attribute) {
            // Convert snake_case to StudlyCase for method name
            // e.g., 'full_name' -> 'getFullNameAttribute'
            $method = 'get' . str_replace('_', '', ucwords($attribute, '_')) . 'Attribute';

            if (method_exists($this, $method)) {
                $array[$attribute] = $this->$method();
            }
        }

        return $array;
    }

    /**
     * Filter attributes based on $visible and $hidden rules.
     *
     * Rules (in order of precedence):
     * 1. If $visible is set: ONLY include those attributes (whitelist)
     * 2. If $hidden is set: EXCLUDE those attributes (blacklist)
     * 3. Otherwise: include all attributes
     *
     * SOLID Principles:
     * - Single Responsibility: Only handles attribute filtering
     * - Open/Closed: Filtering rules defined declaratively via properties
     * - Security by Default: Easy to prevent sensitive data exposure
     *
     * @param array<string,mixed> $array Unfiltered array
     * @return array<string,mixed> Filtered array
     */
    protected function filterVisibleAttributes(array $array): array
    {
        // Rule 1: Whitelist approach (takes precedence)
        if (!empty(static::$visible)) {
            return array_intersect_key($array, array_flip(static::$visible));
        }

        // Rule 2: Blacklist approach
        if (!empty(static::$hidden)) {
            return array_diff_key($array, array_flip(static::$hidden));
        }

        // Rule 3: No filtering (show all)
        return $array;
    }

    /**
     * Convert the model to a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Convert the model instance to an array for JSON serialization.
     *
     * This method is called automatically when the model is passed to json_encode().
     * Toporia compatibility: implements JsonSerializable interface.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Magic getter: checks relations first, then proxies to getAttribute().
     *
     * Lazy Loading Prevention:
     * If preventLazyLoading is enabled and a relationship method exists but
     * wasn't eager loaded, throws an exception to prevent N+1 queries.
     *
     * @throws \RuntimeException When lazy loading is prevented
     */
    public function __get(string $key): mixed
    {
        // Check if it's a loaded relationship first
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        // Check if accessing an unloaded relationship when lazy loading is prevented
        if (static::$preventLazyLoading && method_exists($this, $key)) {
            // CRITICAL FIX: Use Reflection to check return type WITHOUT calling the method
            // Calling $this->$key() would actually trigger lazy loading, defeating the purpose
            try {
                $reflection = new \ReflectionMethod($this, $key);

                // Check if method has a return type hint
                $returnType = $reflection->getReturnType();

                // If return type is RelationInterface or a subclass, it's a relationship
                if ($returnType instanceof \ReflectionNamedType) {
                    $typeName = $returnType->getName();

                    // Check if it's a relationship type
                    if (
                        $typeName === RelationInterface::class ||
                        is_subclass_of($typeName, RelationInterface::class)
                    ) {
                        throw new \RuntimeException(
                            sprintf(
                                'Attempted to lazy load [%s] on model [%s] but lazy loading is disabled. ' .
                                    'Use eager loading instead: %s::with(\'%s\')->get()',
                                $key,
                                static::class,
                                static::class,
                                $key
                            )
                        );
                    }
                }

                // Fallback: If no return type hint, check by calling (less ideal but necessary)
                // This only happens for relationships without type hints
                $relation = $this->$key();
                if ($relation instanceof RelationInterface) {
                    throw new \RuntimeException(
                        sprintf(
                            'Attempted to lazy load [%s] on model [%s] but lazy loading is disabled. ' .
                                'Use eager loading instead: %s::with(\'%s\')->get()',
                            $key,
                            static::class,
                            static::class,
                            $key
                        )
                    );
                }
            } catch (\RuntimeException $e) {
                // Re-throw lazy loading exceptions
                throw $e;
            } catch (\Throwable $e) {
                // Not a relationship method, continue normally
            }
        }

        return $this->getAttribute($key);
    }

    /**
     * Magic setter: proxies to setAttribute().
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Magic isset: checks if attribute is present in the bag.
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Set a loaded relationship.
     *
     * @param string $name Relationship name
     * @param mixed $value Loaded models
     * @return $this
     */
    public function setRelation(string $name, mixed $value): self
    {
        $this->relations[$name] = $value;
        return $this;
    }

    /**
     * Get a loaded relationship.
     *
     * @param string $name Relationship name
     * @return mixed
     */
    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * Check if a relationship has been loaded.
     *
     * @param string $name Relationship name
     * @return bool
     */
    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /**
     * Get all loaded relations.
     *
     * @return array<string, mixed>
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * Create a typed collection for this model type.
     *
     * @param array<int,static> $models
     * @return ModelCollection<static>
     */
    protected function newCollection(array $models = []): ModelCollection
    {
        return new ModelCollection($models);
    }

    /**
     * Hydrate model instances from an array of database rows.
     * PERFORMANCE FIX: Avoid creating temporary instance for newCollection()
     *
     * @param array<int, array<string,mixed>> $rows
     * @return ModelCollection<static>
     */
    public static function hydrate(array $rows): ModelCollection
    {
        $out = [];
        foreach ($rows as $data) {
            $m = new static([]);

            // Bypass mass assignment by setting attributes directly
            // This allows dynamic columns from withCount(), withSum(), selectRaw(), etc.
            foreach ($data as $key => $value) {
                $m->setAttribute((string) $key, $value);
            }

            $m->exists = true;
            $m->syncOriginal();
            $out[] = $m;
        }

        // PERFORMANCE FIX: Reuse first model instance if exists, or create new if empty
        // This avoids creating an unnecessary temporary instance
        return empty($out) ? new ModelCollection([]) : $out[0]->newCollection($out);
    }

    /**
     * Execute the current query and return a typed ModelCollection.
     *
     * Retrieves all records matching the current query constraints.
     * Automatically hydrates results into Model instances and loads eager relationships.
     *
     * @return ModelCollection<static>
     *
     * @example
     * ```php
     * // Get all users
     * $users = UserModel::get();
     *
     * // With query constraints
     * $activeUsers = UserModel::query()->where('active', 1)->get();
     * ```
     */
    public static function get(): ModelCollection
    {
        return static::query()->getModels();
    }

    /**
     * Get a cursor for streaming model results without loading into memory.
     *
     * Returns a Generator that uses PDO cursor to stream results one at a time,
     * hydrating each row into a Model instance. This is the most memory-efficient
     * method for processing large datasets.
     *
     * Performance:
     * - Memory: O(1) - Only one Model in memory at a time
     * - Time: O(N) - Processes all records
     * - Database: Single query, PDO streams results
     * - Hydration: Models are hydrated on-demand during iteration
     *
     * Note: Cursor keeps database connection open during iteration.
     * Don't use for long-running processes that need connection pooling.
     *
     * Example:
     * ```php
     * // Process all users without loading into memory
     * foreach (UserModel::cursor() as $user) {
     *     echo $user->name;
     *     // Process user one at a time
     * }
     *
     * // With query constraints
     * foreach (UserModel::where('active', true)->cursor() as $user) {
     *     processUser($user);
     * }
     * ```
     *
     * @return \Generator<int, static> Generator of model instances
     */
    public static function cursor(): \Generator
    {
        // Use ModelQueryBuilder's cursor() method which handles hydration
        yield from static::query()->cursor();
    }

    /**
     * Get a lazy collection of models for memory-efficient processing.
     *
     * Returns a LazyCollection that uses PDO cursor to stream results one at a time,
     * hydrating each row into a Model instance. This allows chaining collection methods
     * like map(), filter(), etc. while maintaining memory efficiency.
     *
     * Performance:
     * - Memory: O(1) - Only one Model in memory at a time
     * - Time: O(N) - Processes all records
     * - Database: Single query, PDO streams results
     * - Hydration: Models are hydrated on-demand during iteration
     *
     * Example:
     * ```php
     * // Chain collection methods with lazy evaluation
     * $names = UserModel::lazyCollection()
     *     ->map(fn($user) => $user->name)
     *     ->filter(fn($name) => strlen($name) > 5)
     *     ->take(100);
     *
     * foreach ($names as $name) {
     *     echo $name;
     * }
     * ```
     *
     * @return \Toporia\Framework\Support\Collection\LazyCollection<int, static>
     */
    public static function lazyCollection(): LazyCollection
    {
        return static::query()->toLazyCollection();
    }

    /**
     * Get a lazy collection using chunked pagination.
     *
     * Alternative to lazyCollection() that uses chunked queries instead of cursor.
     * This is useful when cursor() is not available or when you need more control
     * over memory usage with chunked processing.
     *
     * Example:
     * ```php
     * $users = UserModel::lazyCollectionByChunk(1000)
     *     ->map(fn($user) => processUser($user))
     *     ->filter(fn($user) => $user->isActive());
     * ```
     *
     * @param int $chunkSize Number of records to fetch per database query (default: 1000)
     * @return \Toporia\Framework\Support\Collection\LazyCollection<int, static>
     */
    public static function lazyCollectionByChunk(int $chunkSize = 1000): LazyCollection
    {
        return static::query()->toLazyCollectionByChunk($chunkSize);
    }

    /**
     * Handle dynamic static method calls to the model.
     *
     * This allows calling QueryBuilder methods directly on the Model class
     * like other frameworks: ProductModel::where('id', 1)->first()
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public static function __callStatic(string $method, array $parameters)
    {
        // Get connection - use model's specific connection configuration
        $connection = static::getConnection();

        // Create model query builder
        $modelQueryBuilder = new ModelQueryBuilder($connection, static::class);

        // Set the table
        $modelQueryBuilder->table(static::getTableName());

        // Call the method on the query builder
        return $modelQueryBuilder->$method(...$parameters);
    }
}
