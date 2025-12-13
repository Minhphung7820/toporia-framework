<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;

/**
 * Class QueryBuilder
 *
 * QueryBuilder Service Accessor - Provides static-like access to QueryBuilder via default connection.
 * This is a convenience accessor for quick query building.
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
 * All methods are automatically delegated to the underlying ConnectionProxy via __callStatic().
 *
 * @method static \Toporia\Framework\Database\Query\QueryBuilder table(string $table) Create QueryBuilder for table
 * @method static \Toporia\Framework\Database\Contracts\ConnectionInterface getConnection() Get underlying connection
 * @method static array getConfig() Get connection configuration
 * @method static \PDO getPdo() Get PDO instance
 *
 * @see ConnectionProxy
 * @see \Toporia\Framework\Database\Query\QueryBuilder
 *
 * @example
 * // Quick query building
 * QueryBuilder::table('users')->where('status', 'active')->get();
 *
 * // With conditions
 * QueryBuilder::table('products')
 *     ->where('price', '>', 100)
 *     ->orderBy('created_at', 'DESC')
 *     ->limit(10)
 *     ->get();
 *
 * // Join queries
 * QueryBuilder::table('users')
 *     ->join('orders', 'users.id', '=', 'orders.user_id')
 *     ->select('users.*', 'orders.total')
 *     ->get();
 */
final class QueryBuilder extends ServiceAccessor
{
    /**
     * Get the service name for this accessor.
     *
     * Returns 'query.builder' which is the ConnectionProxy for default connection.
     * This allows QueryBuilder::table() to work directly via ServiceAccessor's __callStatic().
     *
     * @return string Service name in container
     */
    protected static function getServiceName(): string
    {
        return 'query.builder';
    }
}
