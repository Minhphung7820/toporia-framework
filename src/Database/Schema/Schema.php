<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Schema;

use Toporia\Framework\Database\Contracts\ConnectionInterface;
use Toporia\Framework\Database\DatabaseManager;

/**
 * Class Schema
 *
 * Static facade for database schema operations.
 * Provides Toporia-style static methods for schema manipulation
 * with support for multiple database connections.
 *
 * Usage:
 * ```php
 * // Use default connection
 * Schema::create('users', function (Blueprint $table) {
 *     $table->id();
 *     $table->string('name');
 * });
 *
 * // Use specific connection
 * Schema::connection('mysql_secondary')->create('logs', function (Blueprint $table) {
 *     $table->id();
 *     $table->text('message');
 * });
 *
 * // Check table/column existence
 * if (Schema::hasTable('users')) { ... }
 * if (Schema::hasColumn('users', 'email')) { ... }
 * ```
 *
 * Performance:
 * - O(1) connection resolution via DatabaseManager
 * - Lazy instantiation of SchemaBuilder
 * - Connection caching in DatabaseManager
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Schema
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @method static void create(string $table, callable $callback)
 * @method static void table(string $table, callable $callback)
 * @method static void drop(string $table)
 * @method static void dropIfExists(string $table)
 * @method static void rename(string $from, string $to)
 * @method static bool hasTable(string $table)
 * @method static bool hasColumn(string $table, string $column)
 */
final class Schema
{
    /**
     * Database manager instance.
     */
    private static ?DatabaseManager $manager = null;

    /**
     * Cached SchemaBuilder instances per connection.
     *
     * @var array<string, SchemaBuilder>
     */
    private static array $builders = [];

    /**
     * Set the database manager.
     *
     * Called during application bootstrap to inject DatabaseManager.
     *
     * @param DatabaseManager $manager Database manager instance.
     * @return void
     */
    public static function setManager(DatabaseManager $manager): void
    {
        self::$manager = $manager;
        self::$builders = []; // Clear cached builders when manager changes
    }

    /**
     * Get the database manager.
     *
     * @return DatabaseManager
     * @throws \RuntimeException If manager not set.
     */
    public static function getManager(): DatabaseManager
    {
        if (self::$manager === null) {
            throw new \RuntimeException(
                'Schema facade not initialized. Call Schema::setManager() or register DatabaseServiceProvider.'
            );
        }

        return self::$manager;
    }

    /**
     * Get SchemaBuilder for a specific connection.
     *
     * Example:
     * ```php
     * Schema::connection('mysql_logs')->create('logs', function ($table) {
     *     $table->id();
     *     $table->text('message');
     * });
     * ```
     *
     * @param string $name Connection name from config.
     * @return SchemaBuilder
     */
    public static function connection(string $name): SchemaBuilder
    {
        if (!isset(self::$builders[$name])) {
            $connection = self::getManager()->connection($name);
            self::$builders[$name] = new SchemaBuilder($connection);
        }

        return self::$builders[$name];
    }

    /**
     * Get SchemaBuilder using a connection instance directly.
     *
     * Useful when you already have a connection object.
     *
     * @param ConnectionInterface $connection Database connection.
     * @return SchemaBuilder
     */
    public static function using(ConnectionInterface $connection): SchemaBuilder
    {
        return new SchemaBuilder($connection);
    }

    /**
     * Create a new table on the default connection.
     *
     * @param string $table Table name.
     * @param callable $callback Callback receives Blueprint.
     * @return void
     */
    public static function create(string $table, callable $callback): void
    {
        self::getDefaultBuilder()->create($table, $callback);
    }

    /**
     * Modify an existing table on the default connection.
     *
     * @param string $table Table name.
     * @param callable $callback Callback receives Blueprint.
     * @return void
     */
    public static function table(string $table, callable $callback): void
    {
        self::getDefaultBuilder()->table($table, $callback);
    }

    /**
     * Drop a table on the default connection.
     *
     * @param string $table Table name.
     * @return void
     */
    public static function drop(string $table): void
    {
        self::getDefaultBuilder()->drop($table);
    }

    /**
     * Drop a table if it exists on the default connection.
     *
     * @param string $table Table name.
     * @return void
     */
    public static function dropIfExists(string $table): void
    {
        self::getDefaultBuilder()->dropIfExists($table);
    }

    /**
     * Rename a table on the default connection.
     *
     * @param string $from Current table name.
     * @param string $to New table name.
     * @return void
     */
    public static function rename(string $from, string $to): void
    {
        self::getDefaultBuilder()->rename($from, $to);
    }

    /**
     * Check if a table exists on the default connection.
     *
     * @param string $table Table name.
     * @return bool
     */
    public static function hasTable(string $table): bool
    {
        return self::getDefaultBuilder()->hasTable($table);
    }

    /**
     * Check if a column exists on the default connection.
     *
     * @param string $table Table name.
     * @param string $column Column name.
     * @return bool
     */
    public static function hasColumn(string $table, string $column): bool
    {
        return self::getDefaultBuilder()->hasColumn($table, $column);
    }

    /**
     * Get the default SchemaBuilder.
     *
     * @return SchemaBuilder
     */
    private static function getDefaultBuilder(): SchemaBuilder
    {
        $defaultConnection = self::getManager()->getDefaultConnectionName();

        return self::connection($defaultConnection);
    }

    /**
     * Clear cached builders (useful for testing).
     *
     * @return void
     */
    public static function clearBuilders(): void
    {
        self::$builders = [];
    }

    /**
     * Reset the facade (useful for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$manager = null;
        self::$builders = [];
    }

    /**
     * Forward static calls to the default SchemaBuilder.
     *
     * @param string $method Method name.
     * @param array<mixed> $arguments Method arguments.
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::getDefaultBuilder()->{$method}(...$arguments);
    }
}
