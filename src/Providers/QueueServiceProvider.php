<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Queue\Contracts\{Dispatcher, QueueInterface, QueueManagerInterface};
use Toporia\Framework\Queue\{JobDispatcher, QueueManager};

/**
 * Class QueueServiceProvider
 *
 * Registers queue services with multiple driver support.
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
final class QueueServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Register QueueManager (manages multiple queue drivers)
        $container->singleton(QueueManager::class, function ($c) {
            $config = $c->has('config')
                ? $c->get('config')->get('queue', [])
                : $this->getDefaultConfig();

            // Note: Database connection injection is handled lazily by QueueManager
            // when the database driver is actually used, not during registration.
            // This prevents boot-time connection errors.

            return new QueueManager($config, $c);
        });

        // Register queue interface bindings
        $container->bind(QueueManagerInterface::class, fn($c) => $c->get(QueueManager::class));
        $container->bind(QueueInterface::class, fn($c) => $c->get(QueueManager::class)->driver());
        $container->bind('queue', fn($c) => $c->get(QueueManager::class));

        // Register JobDispatcher for job dispatching with DI
        $container->singleton(JobDispatcher::class, function ($c) {
            // Default queue NAME (not connection name)
            // This is the queue name used for organizing jobs (e.g., 'emails', 'reports', 'default')
            // NOT the connection name ('sync', 'database', 'redis')
            $defaultQueueName = 'default';

            return new JobDispatcher($c, $defaultQueueName);
        });

        // Register dispatcher interface and alias
        $container->bind(Dispatcher::class, fn($c) => $c->get(JobDispatcher::class));
        $container->bind('dispatcher', fn($c) => $c->get(JobDispatcher::class));
    }

    /**
     * Get default queue configuration
     *
     * @return array
     */
    private function getDefaultConfig(): array
    {
        return [
            'default' => 'sync',
            'connections' => [
                'sync' => [
                    'driver' => 'sync',
                ],
            ],
        ];
    }
}
