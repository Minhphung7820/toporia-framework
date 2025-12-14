<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\MultiTenancy\TenantManager;
use Toporia\Framework\MultiTenancy\Resolvers\SubdomainResolver;
use Toporia\Framework\MultiTenancy\Resolvers\HeaderResolver;
use Toporia\Framework\MultiTenancy\Resolvers\PathResolver;
use Toporia\Framework\MultiTenancy\Middleware\IdentifyTenant;
use Toporia\Framework\MultiTenancy\Middleware\EnsureTenant;

/**
 * Class MultiTenancyServiceProvider
 *
 * Registers multi-tenancy services and middleware.
 *
 * Configuration (config/tenancy.php):
 *   return [
 *       'enabled' => true,
 *       'model' => App\Domain\Entities\Tenant::class,
 *       'resolvers' => [
 *           'subdomain' => [
 *               'enabled' => true,
 *               'base_domain' => 'example.com',
 *               'excluded' => ['www', 'api', 'admin'],
 *           ],
 *           'header' => [
 *               'enabled' => true,
 *               'name' => 'X-Tenant-ID',
 *           ],
 *           'path' => [
 *               'enabled' => false,
 *               'segment' => 0,
 *               'prefix' => '',
 *           ],
 *       ],
 *       'middleware' => [
 *           'required' => true,
 *           'check_active' => true,
 *       ],
 *   ];
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class MultiTenancyServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register TenantManager as singleton (static class, but provide container binding)
        $container->singleton('tenant', function () {
            return new class {
                public function current() { return TenantManager::current(); }
                public function currentOrFail() { return TenantManager::currentOrFail(); }
                public function check(): bool { return TenantManager::check(); }
                public function id() { return TenantManager::id(); }
                public function set($tenant, bool $dispatchEvents = true): void {
                    TenantManager::set($tenant, $dispatchEvents);
                }
                public function forget(): void { TenantManager::forget(); }
                public function run($tenant, callable $callback) {
                    return TenantManager::run($tenant, $callback);
                }
                public function runWithoutTenant(callable $callback) {
                    return TenantManager::runWithoutTenant($callback);
                }
            };
        });

        // Register middleware aliases
        $container->bind('tenant.identify', function ($container) {
            $config = $this->getConfig($container);
            return new IdentifyTenant(
                required: $config['middleware']['required'] ?? true,
                checkActive: $config['middleware']['check_active'] ?? true
            );
        });

        $container->bind('tenant.ensure', function () {
            return new EnsureTenant();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        $config = $this->getConfig($container);

        if (!($config['enabled'] ?? true)) {
            return;
        }

        // Set event dispatcher if available
        if ($container->has('events')) {
            TenantManager::setEventDispatcher($container->make('events'));
        }

        // Register resolvers
        $this->registerResolvers($container, $config);
    }

    /**
     * Register tenant resolvers based on configuration.
     *
     * @param ContainerInterface $container
     * @param array $config
     * @return void
     */
    private function registerResolvers(ContainerInterface $container, array $config): void
    {
        $modelClass = $config['model'] ?? null;

        if ($modelClass === null || !class_exists($modelClass)) {
            return;
        }

        // Create finder function
        $finder = $this->createTenantFinder($modelClass);

        // Subdomain resolver
        if ($config['resolvers']['subdomain']['enabled'] ?? false) {
            TenantManager::addResolver(new SubdomainResolver(
                finder: $finder,
                baseDomain: $config['resolvers']['subdomain']['base_domain'] ?? '',
                excludedSubdomains: $config['resolvers']['subdomain']['excluded'] ?? ['www', 'api', 'admin']
            ));
        }

        // Header resolver
        if ($config['resolvers']['header']['enabled'] ?? true) {
            TenantManager::addResolver(new HeaderResolver(
                finder: $finder,
                headerName: $config['resolvers']['header']['name'] ?? 'X-Tenant-ID'
            ));
        }

        // Path resolver
        if ($config['resolvers']['path']['enabled'] ?? false) {
            TenantManager::addResolver(new PathResolver(
                finder: $finder,
                segmentIndex: $config['resolvers']['path']['segment'] ?? 0,
                prefix: $config['resolvers']['path']['prefix'] ?? ''
            ));
        }
    }

    /**
     * Create tenant finder function.
     *
     * @param string $modelClass
     * @return callable
     */
    private function createTenantFinder(string $modelClass): callable
    {
        return function (string $identifier) use ($modelClass) {
            // Try to find by slug first, then by ID
            $tenant = $modelClass::where('slug', $identifier)->first();

            if ($tenant === null && is_numeric($identifier)) {
                $tenant = $modelClass::find((int) $identifier);
            }

            return $tenant;
        };
    }

    /**
     * Get tenancy configuration.
     *
     * @param ContainerInterface $container
     * @return array
     */
    private function getConfig(ContainerInterface $container): array
    {
        if (function_exists('config')) {
            return config('tenancy', []);
        }

        return [];
    }
}
