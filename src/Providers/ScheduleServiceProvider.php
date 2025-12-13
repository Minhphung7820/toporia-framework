<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Console\Scheduling\{CacheMutex, Scheduler};
use Toporia\Framework\Console\Scheduling\Contracts\MutexInterface;

/**
 * Class ScheduleServiceProvider
 *
 * Registers the task scheduler service.
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
final class ScheduleServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Register mutex
        $container->singleton(MutexInterface::class, function ($c) {
            return new CacheMutex($c->get('cache'));
        });

        // Register scheduler
        $container->singleton(Scheduler::class, function ($c) {
            $scheduler = new Scheduler();
            $scheduler->setContainer($c);
            $scheduler->setMutex($c->get(MutexInterface::class));

            // Set base path for maintenance mode check
            if ($c->has('app')) {
                $app = $c->get('app');
                if (method_exists($app, 'getBasePath')) {
                    $scheduler->setBasePath($app->getBasePath());
                }
            }

            return $scheduler;
        });
        $container->bind('schedule', fn($c) => $c->get(Scheduler::class));
    }

    public function boot(ContainerInterface $container): void
    {
        // Scheduler is now ready with container injected
        // Scheduled tasks are defined in App\Infrastructure\Providers\ScheduleServiceProvider
    }
}
