<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Audit\AuditManager;
use Toporia\Framework\Audit\Contracts\AuditDriverInterface;
use Toporia\Framework\Audit\Drivers\DatabaseDriver;
use Toporia\Framework\Audit\Drivers\FileDriver;
use Toporia\Framework\Audit\Middleware\SetAuditContext;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class AuditServiceProvider
 *
 * Registers audit logging services with the container.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class AuditServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function register(ContainerInterface $container): void
    {
        // Register AuditManager singleton
        $container->singleton(AuditManager::class, function (ContainerInterface $c) {
            $config = [];

            if (function_exists('config')) {
                $config = config('audit', []);
            }

            return new AuditManager($config);
        });

        // Alias
        $container->alias('audit', AuditManager::class);

        // Register drivers
        $container->bind(DatabaseDriver::class, function (ContainerInterface $c) {
            $config = function_exists('config')
                ? (config('audit.drivers.database') ?? [])
                : [];

            return new DatabaseDriver($config);
        });

        $container->bind(FileDriver::class, function (ContainerInterface $c) {
            $config = function_exists('config')
                ? (config('audit.drivers.file') ?? [])
                : [];

            return new FileDriver($config);
        });

        // Register middleware
        $container->bind(SetAuditContext::class, function (ContainerInterface $c) {
            return new SetAuditContext(
                $c->make(AuditManager::class)
            );
        });
    }

    /**
     * Bootstrap services.
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function boot(ContainerInterface $container): void
    {
        // Register middleware alias
        $this->registerMiddlewareAlias($container);
    }

    /**
     * Register middleware alias.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerMiddlewareAlias(ContainerInterface $container): void
    {
        if (!$container->has('middleware.aliases')) {
            return;
        }

        $aliases = $container->get('middleware.aliases');

        if (is_array($aliases)) {
            $aliases['audit'] = SetAuditContext::class;
            $container->instance('middleware.aliases', $aliases);
        }
    }
}
