<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\Application;
use Toporia\Framework\Foundation\PackageManifest;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\View\ViewFactory;
use Toporia\Framework\View\View;
use Toporia\Framework\View\Contracts\ViewFactoryInterface;

/**
 * Class ViewServiceProvider
 *
 * Registers view and component services into the container.
 * Provides templating and view component functionality.
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
final class ViewServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register ViewFactory as singleton
        $container->singleton('view', function ($c) {
            $config = $this->getConfig($c);

            $factory = new ViewFactory($config['paths'] ?? []);

            // Register components
            foreach ($config['components'] ?? [] as $alias => $class) {
                $factory->component($class, $alias);
            }

            // Register component namespaces
            foreach ($config['namespaces'] ?? [] as $prefix => $namespace) {
                $factory->componentNamespace($prefix, $namespace);
            }

            return $factory;
        });

        // Register ViewFactoryInterface binding
        $container->bind(
            ViewFactoryInterface::class,
            fn($c) => $c->get('view')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Share common data with all views
        /** @var ViewFactory $viewFactory */
        $viewFactory = $container->get('view');

        // Load package views from manifest
        $this->loadPackageViews($container, $viewFactory);

        // Share app config if available
        if ($container->has('config')) {
            $viewFactory->share('config', $container->get('config'));
        }

        // Share auth user if available
        if ($container->has('auth')) {
            $viewFactory->composer('*', function (View $view) use ($container) {
                $auth = $container->get('auth');
                $view->with('currentUser', $auth->user());
            });
        }
    }

    /**
     * Load package view paths and namespaces from manifest.
     *
     * @param ContainerInterface $container
     * @param ViewFactory $viewFactory
     * @return void
     */
    private function loadPackageViews(ContainerInterface $container, ViewFactory $viewFactory): void
    {
        if (!$container->has(Application::class)) {
            return;
        }

        // Get package manifest singleton (performance: reuse across all providers)
        $manifest = $container->get(PackageManifest::class);

        // Get all package views
        $packageViews = $manifest->views();

        if (empty($packageViews)) {
            return;
        }

        // Load each package's views
        foreach ($packageViews as $packageName => $viewConfig) {
            // Add view paths
            if (isset($viewConfig['paths']) && is_array($viewConfig['paths'])) {
                foreach ($viewConfig['paths'] as $path) {
                    if (is_dir($path)) {
                        $viewFactory->addLocation($path);
                    }
                }
            }

            // Register view namespaces
            if (isset($viewConfig['namespaces']) && is_array($viewConfig['namespaces'])) {
                foreach ($viewConfig['namespaces'] as $namespace => $path) {
                    if (is_dir($path)) {
                        $viewFactory->addNamespace($namespace, $path);
                    }
                }
            }
        }
    }

    /**
     * Get view configuration.
     *
     * @param ContainerInterface $container
     * @return array<string, mixed>
     */
    private function getConfig(ContainerInterface $container): array
    {
        if (!$container->has('config')) {
            // Default paths
            $basePath = dirname(__DIR__, 4);

            return [
                'paths' => [
                    $basePath . '/resources/views',
                ],
                'components' => [],
                'namespaces' => [],
            ];
        }

        $config = $container->get('config');
        $basePath = $config->get('app.base_path', dirname(__DIR__, 4));

        return [
            'paths' => $config->get('view.paths', [
                $basePath . '/resources/views',
            ]),
            'components' => $config->get('view.components', []),
            'namespaces' => $config->get('view.namespaces', []),
        ];
    }
}
