<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\Application;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Support\Vite\Vite;

/**
 * Class ViteServiceProvider
 *
 * Registers Vite asset bundler integration.
 * Provides hot module replacement (HMR) in development and manifest-based asset loading in production.
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
final class ViteServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Register Vite singleton
        $container->singleton(Vite::class, function ($c) {
            $config = $c->get('config')->get('vite', []);
            $app = $c->get(Application::class);

            // Resolve manifest path (support both absolute and relative paths)
            $manifestPath = $config['manifest_path'] ?? null;
            if ($manifestPath === null) {
                $manifestPath = $app->getBasePath('public/build/.vite/manifest.json');
            } elseif (!str_starts_with($manifestPath, '/')) {
                // Relative path - resolve from base path
                $manifestPath = $app->getBasePath($manifestPath);
            }

            return new Vite(
                $manifestPath,
                $config['dev_server_url'] ?? 'http://localhost:5173',
                $config['dev_server_enabled'] ?? false,
                $config['entrypoints'] ?? [],
                $config['build_path'] ?? '/build'
            );
        });

        // Convenience binding
        $container->bind('vite', fn($c) => $c->get(Vite::class));
    }

    public function boot(ContainerInterface $container): void
    {
        // Vite is ready to use after registration
    }
}
