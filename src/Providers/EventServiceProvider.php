<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Events\Dispatcher;
use Toporia\Framework\Events\Contracts\EventDispatcherInterface;
use Toporia\Framework\Foundation\ServiceProvider;


/**
 * Class EventServiceProvider
 *
 * Abstract base class for service providers responsible for registering
 * and booting framework services following two-phase lifecycle (register
 * then boot).
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
class EventServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Event Dispatcher - Singleton with container and queue support
        $container->singleton(EventDispatcherInterface::class, function ($c) {
            $queue = $c->has('queue') ? $c->get('queue') : null;
            return new Dispatcher($c, $queue);
        });

        $container->singleton(Dispatcher::class, function ($c) {
            $queue = $c->has('queue') ? $c->get('queue') : null;
            return new Dispatcher($c, $queue);
        });

        $container->singleton('events', function ($c) {
            $queue = $c->has('queue') ? $c->get('queue') : null;
            return new Dispatcher($c, $queue);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Auto-discover and register event listeners
        $this->discoverListeners($container);
    }

    /**
     * Discover and register event listeners.
     *
     * @param ContainerInterface $container
     * @return void
     */
    private function discoverListeners(ContainerInterface $container): void
    {
        // This can be extended to auto-discover listeners from directories
        // For now, listeners should be registered in EventServiceProvider subclasses
    }
}
