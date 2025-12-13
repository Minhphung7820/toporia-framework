<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Cache\Contracts\CacheInterface;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Realtime\Consumer\ConsumerHandlerRegistry;
use Toporia\Framework\Realtime\Consumer\ConsumerProcessManager;

/**
 * Class ConsumerServiceProvider
 *
 * Registers consumer handler services and loads handler configurations.
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
final class ConsumerServiceProvider extends ServiceProvider
{
    // Note: Consumer should NOT be deferred because:
    // Console commands need it (broker:consume, broker:consumers, etc.)

    /**
     * Track if handlers have been registered.
     */
    private static bool $handlersRegistered = false;

    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register ConsumerHandlerRegistry
        $container->singleton(ConsumerHandlerRegistry::class, function ($c) {
            return new ConsumerHandlerRegistry($c);
        });

        // Register ConsumerProcessManager
        $container->singleton(ConsumerProcessManager::class, function ($c) {
            $cache = $c->get(CacheInterface::class);
            $config = function_exists('config') ? config('consumers.process', []) : [];
            $heartbeatTimeout = (int) ($config['heartbeat_timeout'] ?? 60);

            return new ConsumerProcessManager($cache, $heartbeatTimeout);
        });
    }

    /**
     * Bootstrap consumer services.
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function boot(ContainerInterface $container): void
    {
        // Prevent duplicate registration
        if (self::$handlersRegistered) {
            return;
        }

        $this->registerHandlers($container);
        $this->autoDiscoverHandlers($container);

        self::$handlersRegistered = true;
    }

    /**
     * Register handlers from configuration.
     *
     * @param ContainerInterface $container
     * @return void
     */
    private function registerHandlers(ContainerInterface $container): void
    {
        $config = function_exists('config') ? config('consumers', []) : [];
        $handlers = $config['handlers'] ?? [];

        if (empty($handlers)) {
            return;
        }

        /** @var ConsumerHandlerRegistry $registry */
        $registry = $container->get(ConsumerHandlerRegistry::class);
        $registry->registerMany($handlers);
    }

    /**
     * Auto-discover handlers from configured paths.
     *
     * @param ContainerInterface $container
     * @return void
     */
    private function autoDiscoverHandlers(ContainerInterface $container): void
    {
        $config = function_exists('config') ? config('consumers', []) : [];
        $discoveryPaths = $config['discovery'] ?? [];

        if (empty($discoveryPaths)) {
            return;
        }

        /** @var ConsumerHandlerRegistry $registry */
        $registry = $container->get(ConsumerHandlerRegistry::class);

        foreach ($discoveryPaths as $path => $namespace) {
            $registry->discover($path, $namespace);
        }
    }
}
