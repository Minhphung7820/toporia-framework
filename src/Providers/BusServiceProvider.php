<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Bus\Bus;
use Toporia\Framework\Bus\Contracts\BatchRepositoryInterface;
use Toporia\Framework\Bus\Contracts\DispatcherInterface;
use Toporia\Framework\Bus\DatabaseBatchRepository;
use Toporia\Framework\Bus\Dispatcher;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;

/**
 * Class BusServiceProvider
 *
 * Registers Command/Query/Job Bus services.
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
final class BusServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register dispatcher
        $container->singleton(DispatcherInterface::class, function ($c) {
            return new Dispatcher(
                container: $c,
                queue: $c->has('queue') ? $c->get('queue') : null
            );
        });

        $container->bind('bus', fn($c) => $c->get(DispatcherInterface::class));

        // Register batch repository (if database available)
        if ($container->has('db')) {
            $container->singleton(BatchRepositoryInterface::class, function ($c) {
                return new DatabaseBatchRepository($c->get('db'));
            });

            $container->bind('bus.batch', fn($c) => $c->get(BatchRepositoryInterface::class));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Initialize Bus facade
        $dispatcher = $container->get(DispatcherInterface::class);
        Bus::setDispatcher($dispatcher);

        // Initialize batch repository if available
        if ($container->has(BatchRepositoryInterface::class)) {
            $repository = $container->get(BatchRepositoryInterface::class);
            Bus::setBatchRepository($repository);
        }

        // Load command => handler mappings from config
        $this->loadCommandMappings($container, $dispatcher);
    }

    /**
     * Load command => handler mappings from config.
     */
    private function loadCommandMappings(ContainerInterface $container, DispatcherInterface $dispatcher): void
    {
        if (!$container->has('config')) {
            return;
        }

        $config = $container->get('config');
        $mappings = $config->get('bus.mappings', []);

        if (!empty($mappings)) {
            $dispatcher->map($mappings);
        }

        // Load middleware
        $middleware = $config->get('bus.middleware', []);
        if (!empty($middleware)) {
            $dispatcher->pipeThrough($middleware);
        }
    }
}
