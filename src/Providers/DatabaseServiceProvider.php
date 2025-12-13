<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Database\{Connection, DatabaseManager};
use Toporia\Framework\Database\Contracts\ConnectionInterface;
use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Database\Schema\Schema;
use Toporia\Framework\Foundation\ServiceProvider;


/**
 * Class DatabaseServiceProvider
 *
 * Abstract base class for service providers responsible for registering
 * and booting framework services following two-phase lifecycle (register
 * then boot).
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Providers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Database Manager - Singleton for managing multiple connections
        $container->singleton(DatabaseManager::class, function () {
            $config = $this->getDatabaseConfig();
            return new DatabaseManager($config);
        });

        // Default service alias - returns DatabaseManager (not ConnectionProxy)
        // This allows DB::enableQueryLog(), DB::connection(), etc. to work
        $container->singleton('db', fn(ContainerInterface $c) => $c->get(DatabaseManager::class));

        // QueryBuilder accessor service - returns ConnectionProxy for direct table() access
        // This allows QueryBuilder::table('users') to work directly
        $container->singleton('query.builder', fn(ContainerInterface $c) => $c->get(DatabaseManager::class)->connection());

        // Connection interface bindings (for dependency injection)
        $container->bind(ConnectionInterface::class, fn(ContainerInterface $c) => $c->get(DatabaseManager::class)->getConnection());
        $container->bind(Connection::class, fn(ContainerInterface $c) => $c->get(DatabaseManager::class)->getConnection());
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Set default connection for ORM Models and Schema facade
        try {
            $db = $container->get(DatabaseManager::class);
            Model::setConnection($db->connection());

            // Initialize Schema facade with DatabaseManager
            Schema::setManager($db);
        } catch (\Throwable $e) {
            // Database not configured - Models will fail when used
            // This allows application to boot even without database
        }
    }

    /**
     * Get database configuration.
     *
     * Supports multiple database connections. Example config:
     * ```php
     * 'database' => [
     *     'default' => 'mysql',
     *     'connections' => [
     *         'mysql' => [...],
     *         'mysql_logs' => [...],
     *         'pgsql' => [...],
     *     ]
     * ]
     * ```
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getDatabaseConfig(): array
    {
        // Try to get from config service if available
        try {
            $config = container('config');
            $defaultConnection = $config->get('database.default', 'mysql');
            $connections = $config->get('database.connections', []);

            if (!empty($connections)) {
                // Return all connections with 'default' pointing to the default connection config
                $result = $connections;

                // Ensure 'default' key exists for backward compatibility
                if (!isset($result['default']) && isset($result[$defaultConnection])) {
                    $result['default'] = $result[$defaultConnection];
                }

                return $result;
            }
        } catch (\Throwable $e) {
            // Config not available, use fallback
        }

        // Fallback to environment variables
        $driver = env('DB_CONNECTION', 'mysql');
        return [
            'default' => [
                'driver' => $driver,
                'host' => env('DB_HOST', 'localhost'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_NAME', 'project_topo'),
                'username' => env('DB_USER', 'root'),
                'password' => env('DB_PASS', ''),
                'charset' => 'utf8mb4',
            ],
        ];
    }
}
