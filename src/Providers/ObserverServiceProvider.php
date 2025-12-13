<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Observer\ObserverManager;
use Toporia\Framework\Observer\Contracts\ObserverManagerInterface;

/**
 * Class ObserverServiceProvider
 *
 * Registers the observer manager and bootstraps observers.
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
final class ObserverServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register Observer Manager as singleton
        $container->singleton(ObserverManagerInterface::class, function ($c) {
            return new ObserverManager($c);
        });

        $container->singleton(ObserverManager::class, function ($c) {
            return $c->get(ObserverManagerInterface::class);
        });

        $container->bind('observer', fn($c) => $c->get(ObserverManagerInterface::class));
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Bootstrap observers from application
        // This allows application-level observers to be registered
        $this->bootstrapObservers($container);
    }

    /**
     * Bootstrap observers from application configuration.
     *
     * @param ContainerInterface $container Container instance
     * @return void
     */
    private function bootstrapObservers(ContainerInterface $container): void
    {
        $manager = $container->get(ObserverManagerInterface::class);

        // Get observer configuration
        $observers = config('observers', []);

        // Register observers from config
        foreach ($observers as $observableClass => $observerConfig) {
            if (is_string($observerConfig)) {
                // Simple: single observer class
                $manager->register($observableClass, $observerConfig);
            } elseif (is_array($observerConfig)) {
                // Advanced: multiple observers with options
                foreach ($observerConfig as $observer) {
                    if (is_string($observer)) {
                        $manager->register($observableClass, $observer);
                    } elseif (is_array($observer)) {
                        $observerClass = $observer['class'] ?? null;
                        $event = $observer['event'] ?? null;
                        $priority = (int) ($observer['priority'] ?? 0);

                        if ($observerClass) {
                            $manager->register($observableClass, $observerClass, $event, $priority);
                        }
                    }
                }
            }
        }
    }
}

