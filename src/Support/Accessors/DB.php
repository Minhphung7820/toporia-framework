<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;

/**
 * Class DB
 *
 * DB Service Accessor - Provides static-like access to the database manager.
 * All methods are automatically delegated to the underlying service via __callStatic().
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @method static ConnectionProxy connection(?string $name = null) Get connection proxy
 * @method static ConnectionInterface getConnection(?string $name = null) Get connection directly
 * @method static void setDefaultConnection(string $name) Set default connection
 * @method static string getDefaultConnection() Get default connection name
 * @method static void enableQueryLog() Enable query logging
 * @method static void disableQueryLog() Disable query logging
 * @method static array getQueryLog() Get the query log
 * @method static void flushQueryLog() Clear the query log
 * @method static \Toporia\Framework\Database\Query\Expression raw(string $value) Create raw SQL expression for query building
 * @method static \Toporia\Framework\Database\DatabaseCollection select(string $sql, array $bindings = []) Execute raw SELECT query
 * @method static int statement(string $sql, array $bindings = []) Execute raw INSERT/UPDATE/DELETE statement
 * @method static bool unprepared(string $sql) Execute unprepared SQL (DDL statements)
 *
 * @see DatabaseManager
 *
 * @example
 * // Get default connection and use it
 * DB::connection()->table('users')->get();
 *
 * // Get named connection
 * DB::connection('mysql')->table('users')->get();
 *
 * // Create raw expression for query building (like Toporia's DB::raw())
 * DB::table('users')
 *     ->select(DB::raw('COUNT(*) as total'))
 *     ->get();
 *
 * DB::table('orders')
 *     ->where(DB::raw('DATE(created_at)'), '=', '2024-01-01')
 *     ->get();
 *
 * // Execute raw SELECT query
 * DB::select('SELECT * FROM users WHERE status = ?', ['active']);
 *
 * // Execute raw statements
 * DB::statement('UPDATE users SET status = ? WHERE id = ?', ['inactive', 1]);
 * DB::unprepared('TRUNCATE TABLE cache');
 */
final class DB extends ServiceAccessor
{
    /**
     * Get the service name for this accessor.
     *
     * This is the only method needed - all other methods are automatically
     * delegated to the underlying service via __callStatic().
     *
     * @return string Service name in container
     */
    protected static function getServiceName(): string
    {
        return 'db';
    }
}
