<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

use Toporia\Framework\Config\Repository;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Log\Contracts\LoggerInterface;

/**
 * Class LoadConfiguration
 *
 * Loads all configuration files from config directory into the container.
 * This should be called early in the bootstrap process, right after
 * Application instance is created.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Foundation
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class LoadConfiguration
{
    /**
     * Bootstrap configuration loading.
     *
     * Loads all config files and registers them in the container.
     *
     * @param Application $app Application instance
     * @return void
     */
    public static function bootstrap(Application $app): void
    {
        $container = $app->getContainer();

        // Only load if not already loaded
        if ($container->has('config')) {
            return;
        }

        // Create config repository
        $cachePath = $app->path('storage/framework/config.php');
        $config = new Repository([], $cachePath);

        // Set config directory for lazy loading
        // Config files will be loaded on first access (lazy loading)
        // Or from cache if available
        $configPath = $app->path('config');
        if (is_dir($configPath)) {
            $config->loadDirectory($configPath, eager: false); // Lazy load or load from cache
        }

        // Register in container as singleton
        $container->singleton(Repository::class, fn() => $config);
        $container->singleton('config', fn(ContainerInterface $c) => $c->get(Repository::class));

        // Register PackageManifest as singleton for package auto-discovery
        // This must be done here (not in ConfigServiceProvider) because LoadConfiguration
        // runs before RegisterProviders, so ConfigServiceProvider::register() never runs
        $container->singleton(PackageManifest::class, function (ContainerInterface $c) use ($app) {
            $basePath = $app->getBasePath();

            // Logger not available yet during early bootstrap
            $logger = $c->has(LoggerInterface::class) ? $c->get(LoggerInterface::class) : null;

            return new PackageManifest(
                $basePath . '/bootstrap/cache/packages.php',
                $basePath,
                $basePath . '/vendor',
                $basePath . '/packages',
                $logger
            );
        });

        // Merge package middleware from manifest
        self::mergePackageMiddleware($app, $config);
    }

    /**
     * Merge middleware from packages into the middleware configuration.
     *
     * This method loads middleware groups and aliases from packages auto-discovered
     * via PackageManifest and merges them into the application middleware config.
     *
     * Performance: O(N) where N = number of package middleware entries
     *
     * @param Application $app
     * @param Repository $config
     * @return void
     */
    private static function mergePackageMiddleware(Application $app, Repository $config): void
    {
        // Get package manifest
        $basePath = $app->getBasePath();
        $manifestPath = $basePath . '/bootstrap/cache/packages.php';
        $vendorPath = $basePath . '/vendor';
        $packagesPath = $basePath . '/packages';

        // Logger not available yet during early bootstrap
        $manifest = new PackageManifest($manifestPath, $basePath, $vendorPath, $packagesPath, null);

        // Get package middleware
        $packageMiddleware = $manifest->middleware();

        if (empty($packageMiddleware)) {
            return;
        }

        // Get current middleware config
        $currentMiddleware = $config->get('middleware', []);
        $currentGroups = $currentMiddleware['groups'] ?? [];
        $currentAliases = $currentMiddleware['aliases'] ?? [];

        // Merge package middleware groups
        if (!empty($packageMiddleware['groups'])) {
            foreach ($packageMiddleware['groups'] as $groupName => $middlewareList) {
                if (!isset($currentGroups[$groupName])) {
                    $currentGroups[$groupName] = [];
                }

                // Append package middleware to group (avoid duplicates)
                foreach ($middlewareList as $middleware) {
                    if (!in_array($middleware, $currentGroups[$groupName], true)) {
                        $currentGroups[$groupName][] = $middleware;
                    }
                }
            }
        }

        // Merge package middleware aliases
        if (!empty($packageMiddleware['aliases'])) {
            foreach ($packageMiddleware['aliases'] as $alias => $class) {
                // Package aliases don't override app aliases
                if (!isset($currentAliases[$alias])) {
                    $currentAliases[$alias] = $class;
                }
            }
        }

        // Update config with merged middleware
        $config->set('middleware.groups', $currentGroups);
        $config->set('middleware.aliases', $currentAliases);
    }
}
