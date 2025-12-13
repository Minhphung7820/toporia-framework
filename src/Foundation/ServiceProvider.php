<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

use Toporia\Framework\Foundation\Contracts\ServiceProviderInterface;
use Toporia\Framework\Container\Contracts\ContainerInterface;


/**
 * Abstract Class ServiceProvider
 *
 * Abstract base class for service providers responsible for registering
 * and booting framework services following two-phase lifecycle (register
 * then boot).
 *
 * Features:
 * - Two-phase lifecycle (register then boot)
 * - Deferred loading support for performance optimization
 * - Publish configuration and assets
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Foundation
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
abstract class ServiceProvider implements ServiceProviderInterface
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * Set to true in subclass to enable lazy-loading.
     * Must also implement provides() to list provided services.
     *
     * @var bool
     */
    protected bool $defer = false;

    /**
     * The paths that should be published.
     * Structure: [providerClass => [sourcePath => destinationPath, ...], ...]
     *
     * @var array<string, array<string, string>>
     */
    protected static array $publishes = [];

    /**
     * The paths that should be published by group.
     *
     * @var array<string, array<string, string>>
     */
    protected static array $publishGroups = [];

    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Override in subclass if needed
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Override in subclass if needed
    }

    /**
     * {@inheritdoc}
     */
    public function provides(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function isDeferred(): bool
    {
        return $this->defer;
    }

    /**
     * Merge the given configuration with the existing configuration.
     *
     * @param string $path Config file path
     * @param string $key Config key
     * @param ContainerInterface $container
     * @return void
     */
    protected function mergeConfigFrom(string $path, string $key, ContainerInterface $container): void
    {
        if (!$container->has('config')) {
            return;
        }

        $config = $container->get('config');

        if (!method_exists($config, 'set') || !method_exists($config, 'get')) {
            return;
        }

        $existingConfig = $config->get($key, []);
        $packageConfig = require $path;

        $config->set($key, array_merge($packageConfig, $existingConfig));
    }

    /**
     * Register paths to be published by the publish command.
     *
     * @param array<string, string> $paths Source => Destination paths
     * @param string|null $group Optional group name
     * @return void
     */
    protected function publishes(array $paths, ?string $group = null): void
    {
        $class = static::class;

        if (!isset(static::$publishes[$class])) {
            static::$publishes[$class] = [];
        }

        static::$publishes[$class] = array_merge(static::$publishes[$class], $paths);

        if ($group !== null) {
            if (!isset(static::$publishGroups[$group])) {
                static::$publishGroups[$group] = [];
            }

            static::$publishGroups[$group] = array_merge(static::$publishGroups[$group], $paths);
        }
    }

    /**
     * Get the paths to publish.
     *
     * @param string|null $provider Provider class name or null for all
     * @param string|null $group Group name or null for all
     * @return array<string, string>
     */
    public static function pathsToPublish(?string $provider = null, ?string $group = null): array
    {
        if ($group !== null && isset(static::$publishGroups[$group])) {
            return static::$publishGroups[$group];
        }

        if ($provider !== null && isset(static::$publishes[$provider])) {
            return static::$publishes[$provider];
        }

        if ($provider === null && $group === null) {
            $paths = [];
            foreach (static::$publishes as $providerPaths) {
                $paths = array_merge($paths, $providerPaths);
            }
            return $paths;
        }

        return [];
    }

    /**
     * Register a view namespace for the provider.
     *
     * @param string $path View path
     * @param string $namespace View namespace
     * @param ContainerInterface $container
     * @return void
     */
    protected function loadViewsFrom(string $path, string $namespace, ContainerInterface $container): void
    {
        if (!$container->has('view')) {
            return;
        }

        $view = $container->get('view');

        if (method_exists($view, 'addNamespace')) {
            $view->addNamespace($namespace, $path);
        }
    }

    /**
     * Register a translation namespace for the provider.
     *
     * @param string $path Translation path
     * @param string $namespace Translation namespace
     * @param ContainerInterface $container
     * @return void
     */
    protected function loadTranslationsFrom(string $path, string $namespace, ContainerInterface $container): void
    {
        if (!$container->has('translator')) {
            return;
        }

        $translator = $container->get('translator');

        if (method_exists($translator, 'addNamespace')) {
            $translator->addNamespace($namespace, $path);
        }
    }

    /**
     * Register database migrations for the provider.
     *
     * @param string|array $paths Migration paths
     * @param ContainerInterface $container
     * @return void
     */
    protected function loadMigrationsFrom(string|array $paths, ContainerInterface $container): void
    {
        if (!$container->has('migrator')) {
            return;
        }

        $migrator = $container->get('migrator');
        $paths = is_array($paths) ? $paths : [$paths];

        if (method_exists($migrator, 'path')) {
            foreach ($paths as $path) {
                $migrator->path($path);
            }
        }
    }

    /**
     * Register console commands.
     *
     * @param array<string> $commands Command class names
     * @param ContainerInterface $container
     * @return void
     */
    protected function commands(array $commands, ContainerInterface $container): void
    {
        if (!$container->has('console')) {
            return;
        }

        $console = $container->get('console');

        if (method_exists($console, 'addCommands')) {
            $console->addCommands($commands);
        }
    }
}
