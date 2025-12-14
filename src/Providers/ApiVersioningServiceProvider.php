<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\ApiVersioning\ApiVersion;
use Toporia\Framework\ApiVersioning\Resolvers\HeaderResolver;
use Toporia\Framework\ApiVersioning\Resolvers\PathResolver;
use Toporia\Framework\ApiVersioning\Resolvers\QueryResolver;
use Toporia\Framework\ApiVersioning\Resolvers\AcceptHeaderResolver;
use Toporia\Framework\ApiVersioning\Middleware\ResolveApiVersion;
use Toporia\Framework\ApiVersioning\Middleware\EnsureApiVersion;

/**
 * Class ApiVersioningServiceProvider
 *
 * Registers API versioning services and middleware.
 *
 * Configuration (config/api.php):
 *   return [
 *       'versioning' => [
 *           'enabled' => true,
 *           'default' => 'v1',
 *           'supported' => ['v3', 'v2', 'v1'], // Newest first
 *           'deprecated' => [
 *               'v1' => '2025-12-31',
 *           ],
 *           'resolvers' => [
 *               'header' => ['enabled' => true, 'names' => ['X-API-Version']],
 *               'path' => ['enabled' => true, 'prefix' => 'api'],
 *               'query' => ['enabled' => false, 'param' => 'api_version'],
 *               'accept' => ['enabled' => false, 'vendor' => 'vnd.api'],
 *           ],
 *       ],
 *   ];
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class ApiVersioningServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register middleware aliases
        $container->bind('api.version', function () {
            return new ResolveApiVersion();
        });

        $container->bind('api.version.exact', function ($container, $params) {
            return EnsureApiVersion::exact($params['version'] ?? 'v1');
        });

        $container->bind('api.version.min', function ($container, $params) {
            return EnsureApiVersion::min($params['version'] ?? 'v1');
        });

        $container->bind('api.version.max', function ($container, $params) {
            return EnsureApiVersion::max($params['version'] ?? 'v1');
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        $config = $this->getConfig($container);

        if (!($config['versioning']['enabled'] ?? true)) {
            return;
        }

        // Configure ApiVersion
        $this->configureApiVersion($config['versioning'] ?? []);
    }

    /**
     * Configure ApiVersion manager.
     *
     * @param array $config
     * @return void
     */
    private function configureApiVersion(array $config): void
    {
        // Set supported versions
        if (!empty($config['supported'])) {
            ApiVersion::setSupportedVersions($config['supported']);
        }

        // Set default version
        if (!empty($config['default'])) {
            ApiVersion::setDefaultVersion($config['default']);
        }

        // Register deprecated versions
        foreach ($config['deprecated'] ?? [] as $version => $sunsetDate) {
            ApiVersion::deprecate($version, $sunsetDate);
        }

        // Register resolvers
        $this->registerResolvers($config['resolvers'] ?? []);
    }

    /**
     * Register version resolvers.
     *
     * @param array $config
     * @return void
     */
    private function registerResolvers(array $config): void
    {
        // Header resolver (highest priority)
        if ($config['header']['enabled'] ?? true) {
            ApiVersion::addResolver(new HeaderResolver(
                headerNames: $config['header']['names'] ?? ['X-API-Version', 'Accept-Version']
            ));
        }

        // Path resolver
        if ($config['path']['enabled'] ?? true) {
            ApiVersion::addResolver(new PathResolver(
                prefix: $config['path']['prefix'] ?? 'api'
            ));
        }

        // Accept header resolver
        if ($config['accept']['enabled'] ?? false) {
            ApiVersion::addResolver(new AcceptHeaderResolver(
                vendor: $config['accept']['vendor'] ?? 'vnd.api'
            ));
        }

        // Query resolver (lowest priority)
        if ($config['query']['enabled'] ?? false) {
            ApiVersion::addResolver(new QueryResolver(
                paramName: $config['query']['param'] ?? 'api_version'
            ));
        }
    }

    /**
     * Get API configuration.
     *
     * @param ContainerInterface $container
     * @return array
     */
    private function getConfig(ContainerInterface $container): array
    {
        if (function_exists('config')) {
            return config('api', []);
        }

        return [];
    }
}
