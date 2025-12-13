<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

use Toporia\Framework\Config\Repository;
use Toporia\Framework\Container\Contracts\ContainerInterface;

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
    }
}
