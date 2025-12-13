<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Session\SessionManager;
use Toporia\Framework\Session\Store;
use Toporia\Framework\Session\Middleware\StartSession;

/**
 * Class SessionServiceProvider
 *
 * Registers session management services.
 *
 * Session is started lazily by StartSession middleware (added to 'web' group),
 * not automatically on every request. This improves performance for API routes.
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
final class SessionServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * Session is only needed for web routes (via StartSession middleware),
     * not for API routes or console commands.
     */
    protected bool $defer = true;

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            SessionManager::class,
            Store::class,
            StartSession::class,
            'session.manager',
            'session',
        ];
    }

    public function register(ContainerInterface $container): void
    {
        // Session Manager
        $container->singleton(SessionManager::class, function ($c) {
            $config = $c->has('config')
                ? $c->get('config')->get('session', [])
                : [];

            // Unwrap ConnectionProxy to get actual ConnectionInterface
            $db = $c->has('db') ? $c->get('db') : null;
            $connection = $db && method_exists($db, 'getConnection')
                ? $db->getConnection()
                : $db;

            $redis = $c->has('redis') ? $c->get('redis') : null;
            $cookieJar = $c->has('cookie') ? $c->get('cookie') : null;

            return new SessionManager($config, $connection, $redis, $cookieJar);
        });

        $container->bind('session.manager', fn($c) => $c->get(SessionManager::class));

        // Default session store
        $container->singleton(Store::class, function ($c) {
            $manager = $c->get(SessionManager::class);
            return $manager->store();
        });

        $container->bind('session', fn($c) => $c->get(Store::class));

        // StartSession middleware
        $container->bind(StartSession::class, function ($c) {
            return new StartSession($c->get(Store::class));
        });
    }

    public function boot(ContainerInterface $container): void
    {
        // Session is started by StartSession middleware, not here.
        // This allows API routes to skip session entirely for better performance.
    }
}
