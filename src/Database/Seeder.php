<?php

declare(strict_types=1);

namespace Toporia\Framework\Database;

use Toporia\Framework\Database\Contracts\{ConnectionInterface, FactoryInterface, SeederInterface};
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Database\ORM\Model;


/**
 * Abstract Class Seeder
 *
 * Abstract base class for Seeder implementations in the Database query
 * building and ORM layer providing common functionality and contracts.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
abstract class Seeder implements SeederInterface
{
    /**
     * Track which seeders have been called once (for idempotency).
     *
     * @var array<string, bool>
     */
    protected static array $calledOnce = [];

    /**
     * Database manager instance.
     *
     * @var DatabaseManager|null
     */
    protected ?DatabaseManager $db = null;

    /**
     * Connection name to use for seeding.
     *
     * @var string|null
     */
    protected ?string $connection = null;

    /**
     * Whether to use transaction for this seeder.
     *
     * @var bool
     */
    protected bool $useTransaction = true;


    /**
     * Batch size for bulk operations.
     *
     * @var int
     */
    protected int $batchSize = 100;

    /**
     * Progress callback for tracking seeding progress.
     *
     * @var callable(string, int, int): void|null
     */
    protected $progressCallback = null;

    /**
     * Constructor.
     *
     * @param DatabaseManager|null $db Database manager (injected via container)
     */
    public function __construct(?DatabaseManager $db = null)
    {
        $this->db = $db ?? $this->resolveDatabaseManager();
    }

    /**
     * Run the database seeds.
     *
     * Wrapped in transaction if useTransaction() returns true.
     *
     * @return void
     */
    public function run(): void
    {
        if ($this->useTransaction()) {
            $this->runInTransaction();
        } else {
            $this->seed();
        }
    }

    /**
     * Execute seeding logic.
     *
     * Must be implemented by child classes.
     *
     * @return void
     */
    abstract protected function seed(): void;

    /**
     * Run seed in transaction.
     *
     * @return void
     */
    protected function runInTransaction(): void
    {
        $connection = $this->getConnection();

        try {
            $connection->beginTransaction();
            $this->seed();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Call another seeder class.
     *
     * @param class-string<Seeder> $seederClass
     * @return void
     */
    protected function call(string $seederClass): void
    {
        if (!is_subclass_of($seederClass, Seeder::class)) {
            throw new \InvalidArgumentException(
                "Seeder class [{$seederClass}] must extend " . Seeder::class
            );
        }

        /** @var Seeder $seeder */
        $seeder = new $seederClass($this->db);
        $seeder->run();
    }

    /**
     * Call multiple seeders.
     *
     * @param array<int, class-string<Seeder>> $seeders
     * @return void
     */
    protected function callMany(array $seeders): void
    {
        foreach ($seeders as $seederClass) {
            $this->call($seederClass);
        }
    }

    /**
     * Call another seeder class only once (idempotent seeding).
     *
     * Ensures a seeder is only executed once during the entire seeding process,
     * even if called multiple times from different seeders. Useful for shared
     * reference data that should only be seeded once.
     *
     * Usage:
     * ```php
     * // In UserSeeder.php
     * protected function seed(): void
     * {
     *     $this->callOnce(CountrySeeder::class); // Seeds countries once
     *     // ... user seeding logic
     * }
     *
     * // In ProductSeeder.php
     * protected function seed(): void
     * {
     *     $this->callOnce(CountrySeeder::class); // Skipped (already called)
     *     // ... product seeding logic
     * }
     * ```
     *
     * @param class-string<Seeder> $seederClass
     * @return void
     */
    protected function callOnce(string $seederClass): void
    {
        // Check if this seeder has already been called
        if (isset(self::$calledOnce[$seederClass])) {
            return;
        }

        // Mark as called before execution to prevent infinite recursion
        self::$calledOnce[$seederClass] = true;

        // Call the seeder
        $this->call($seederClass);
    }

    /**
     * Reset the callOnce tracking (useful for testing).
     *
     * @return void
     */
    public static function resetCallOnce(): void
    {
        self::$calledOnce = [];
    }

    /**
     * Create models using factory.
     *
     * @param FactoryInterface<TModel>|string $factory Factory instance or class name
     * @param int $count Number of models to create
     * @param array<string, mixed> $attributes Additional attributes
     * @return array<int, Model>
     * @template TModel of Model
     */
    protected function factory(
        FactoryInterface|string $factory,
        int $count = 1,
        array $attributes = []
    ): array {
        if ($count <= 0) {
            return [];
        }

        // Resolve factory if string provided
        if (is_string($factory)) {
            $factory = $factory::new();
        }

        if ($count === 1) {
            return [$factory->create($attributes)];
        }

        return $factory->createMany($count, $attributes);
    }

    /**
     * Batch insert data from factory definitions (most performant).
     *
     * This method generates factory definitions and inserts them directly
     * without creating model instances, which is much faster for large datasets.
     *
     * @param string|FactoryInterface $factory Factory class name or instance
     * @param int $count Number of records to create
     * @param array<string, mixed> $attributes Additional attributes
     * @param string|null $table Table name (inferred from model if null)
     * @return array<int> Inserted IDs
     */
    protected function factoryBatch(
        string|FactoryInterface $factory,
        int $count,
        array $attributes = [],
        ?string $table = null
    ): array {
        if ($count <= 0) {
            return [];
        }

        // Resolve factory if string provided
        if (is_string($factory)) {
            $factory = $factory::new();
        }

        // Get table name from model
        if ($table === null) {
            if (property_exists($factory, 'model')) {
                $modelClass = (new \ReflectionProperty($factory, 'model'))->getValue($factory);

                if ($modelClass && method_exists($modelClass, 'getTableName')) {
                    $table = $modelClass::getTableName();
                } elseif ($modelClass && method_exists($modelClass, 'getTable')) {
                    $table = (new $modelClass)->getTable();
                } else {
                    throw new \InvalidArgumentException("Cannot infer table name. Please provide table parameter.");
                }
            } else {
                throw new \InvalidArgumentException("Factory must have model property or table must be provided.");
            }
        }

        if ($table === null) {
            throw new \InvalidArgumentException("Table name is required for batch insert.");
        }

        // Generate data using factory
        $data = [];
        $definitionMethod = new \ReflectionMethod($factory, 'definition');

        // Access defaultAttributes via reflection
        $defaultAttributesProp = new \ReflectionProperty($factory, 'defaultAttributes');
        $defaultAttributes = $defaultAttributesProp->getValue($factory);

        $sequenceIndexProp = new \ReflectionProperty($factory, 'sequenceIndex');

        for ($i = 0; $i < $count; $i++) {
            $sequenceIndexProp->setValue($factory, $i);

            $row = array_merge($definitionMethod->invoke($factory), $defaultAttributes, $attributes);
            $data[] = $row;
        }

        // Batch insert
        $this->insert($table, $data);

        // Return IDs (approximate - actual IDs depend on auto-increment)
        $connection = $this->getConnection();
        $lastId = (int) $connection->selectOne("SELECT MAX(id) as max_id FROM {$table}")['max_id'] ?? 0;

        return range($lastId - $count + 1, $lastId);
    }

    /**
     * Create models using factory with progress tracking.
     *
     * @param FactoryInterface<TModel> $factory Factory instance
     * @param int $count Number of models to create
     * @param array<string, mixed> $attributes Additional attributes
     * @return array<int, Model>
     * @template TModel of Model
     */
    protected function factoryWithProgress(
        FactoryInterface $factory,
        int $count,
        array $attributes = []
    ): array {
        $total = $count;
        $created = 0;
        $models = [];

        // Create in batches for progress tracking
        while ($created < $total) {
            $batchSize = min($this->batchSize, $total - $created);

            $batch = $factory->createMany($batchSize, $attributes);
            $models = array_merge($models, $batch);

            $created += $batchSize;

            // Report progress
            if ($this->progressCallback) {
                ($this->progressCallback)(
                    static::class,
                    $created,
                    $total
                );
            }
        }

        return $models;
    }

    /**
     * Insert raw data directly into database.
     *
     * More performant than using models for large datasets.
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $data Data to insert
     * @return void
     */
    protected function insert(string $table, array $data): void
    {
        if (empty($data)) {
            return;
        }

        $connection = $this->getConnection();

        // Insert in batches
        foreach (array_chunk($data, $this->batchSize) as $chunk) {
            $columns = array_keys($chunk[0]);
            $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
            $values = array_fill(0, count($chunk), $placeholders);

            $sql = sprintf(
                'INSERT INTO `%s` (%s) VALUES %s',
                $table,
                implode(',', array_map(fn($col) => "`{$col}`", $columns)),
                implode(',', $values)
            );

            $bindings = [];
            foreach ($chunk as $row) {
                foreach ($columns as $column) {
                    $bindings[] = $row[$column] ?? null;
                }
            }

            $connection->execute($sql, $bindings);
        }
    }

    /**
     * Truncate table(s).
     *
     * @param string|array<int, string> $tables Table name(s)
     * @return void
     */
    protected function truncate(string|array $tables): void
    {
        $tables = is_array($tables) ? $tables : [$tables];
        $connection = $this->getConnection();

        // Disable foreign key checks temporarily
        $connection->execute('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $connection->execute("TRUNCATE TABLE {$table}");
            }
        } finally {
            // Re-enable foreign key checks
            $connection->execute('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Get database connection.
     *
     * @return \Toporia\Framework\Database\Contracts\ConnectionInterface
     */
    protected function getConnection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }

    /**
     * Set progress callback.
     *
     * @param callable(string, int, int): void $callback
     * @return static
     */
    public function setProgressCallback(callable $callback): static
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * Set batch size for bulk operations.
     *
     * @param int $size
     * @return static
     */
    public function setBatchSize(int $size): static
    {
        $this->batchSize = max(1, $size);
        return $this;
    }

    /**
     * Set connection name.
     *
     * @param string|null $connection
     * @return static
     */
    public function setConnection(?string $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * Get seeder dependencies.
     *
     * @return array<string> Array of seeder class names
     */
    public function dependencies(): array
    {
        return [];
    }

    /**
     * Whether to use transaction for this seeder.
     *
     * @return bool
     */
    public function useTransaction(): bool
    {
        return $this->useTransaction;
    }

    /**
     * Get Faker instance.
     *
     * @return \Faker\Generator
     */
    protected function faker(): \Faker\Generator
    {
        return \Faker\Factory::create();
    }

    /**
     * Get seeder option (for command-line options).
     *
     * @param string $key Option key.
     * @return string|null Option value.
     */
    protected function getOption(string $key): ?string
    {
        // This can be overridden to read from command options
        return null;
    }

    /**
     * Resolve database manager from container.
     *
     * @return DatabaseManager
     */
    protected function resolveDatabaseManager(): DatabaseManager
    {
        // Try to resolve from container
        if (function_exists('container')) {
            try {
                return container(DatabaseManager::class);
            } catch (\Throwable $e) {
                // Fall through to fallback
            }
        }

        // Fallback: create from config
        $config = config('database', []);
        return new DatabaseManager($config);
    }

    /**
     * Output an info message.
     *
     * @param string $message
     * @return void
     */
    protected function info(string $message): void
    {
        echo "[INFO] {$message}" . PHP_EOL;
    }

    /**
     * Output a line message.
     *
     * @param string $message
     * @return void
     */
    protected function line(string $message): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * Output a success message.
     *
     * @param string $message
     * @return void
     */
    protected function success(string $message): void
    {
        echo "[SUCCESS] {$message}" . PHP_EOL;
    }

    /**
     * Output an error message.
     *
     * @param string $message
     * @return void
     */
    protected function error(string $message): void
    {
        echo "[ERROR] {$message}" . PHP_EOL;
    }

    /**
     * Output a new line.
     *
     * @return void
     */
    protected function newLine(): void
    {
        echo PHP_EOL;
    }

}
