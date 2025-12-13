<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Concurrency\ConcurrencyManager;
use Toporia\Framework\Concurrency\Console\InvokeSerializedClosureCommand;
use Toporia\Framework\Concurrency\Contracts\ClosureSerializerInterface;
use Toporia\Framework\Concurrency\Contracts\ConcurrencyDriverInterface;
use Toporia\Framework\Concurrency\Contracts\ProcessFactoryInterface;
use Toporia\Framework\Concurrency\Drivers\ForkConcurrencyDriver;
use Toporia\Framework\Concurrency\Drivers\ProcessConcurrencyDriver;
use Toporia\Framework\Concurrency\Drivers\SyncConcurrencyDriver;
use Toporia\Framework\Concurrency\Process\ProcessFactory;
use Toporia\Framework\Concurrency\Serialization\SerializableClosureSerializer;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;

/**
 * Concurrency Service Provider
 *
 * Registers all concurrency-related services into the container.
 *
 * Services registered:
 * - ClosureSerializerInterface
 * - ProcessFactoryInterface
 * - ConcurrencyManager
 * - Individual drivers (process, fork, sync)
 * - InvokeSerializedClosureCommand
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class ConcurrencyServiceProvider extends ServiceProvider
{
    /**
     * Register concurrency services.
     */
    public function register(ContainerInterface $container): void
    {
        $this->registerSerializer($container);
        $this->registerProcessFactory($container);
        $this->registerDrivers($container);
        $this->registerManager($container);
        $this->registerCommand($container);
    }

    /**
     * Register closure serializer.
     */
    private function registerSerializer(ContainerInterface $container): void
    {
        $container->singleton(ClosureSerializerInterface::class, function () {
            // Get secret key from config if available
            $secretKey = null;
            if (function_exists('config')) {
                $secretKey = config('concurrency.secret_key');
            }

            return new SerializableClosureSerializer($secretKey);
        });

        // Convenience alias
        $container->bind('concurrency.serializer', function ($c) {
            return $c->get(ClosureSerializerInterface::class);
        });
    }

    /**
     * Register process factory.
     */
    private function registerProcessFactory(ContainerInterface $container): void
    {
        $container->singleton(ProcessFactoryInterface::class, function () {
            $factory = new ProcessFactory();

            // Set defaults from config if available
            if (function_exists('config')) {
                $timeout = config('concurrency.timeout', 60);
                $maxConcurrent = config('concurrency.max_concurrent', 10);

                $factory->defaultTimeout((float) $timeout);
                $factory->defaultConcurrent((int) $maxConcurrent);
            }

            return $factory;
        });

        $container->bind(ProcessFactory::class, function ($c) {
            return $c->get(ProcessFactoryInterface::class);
        });
    }

    /**
     * Register individual drivers.
     */
    private function registerDrivers(ContainerInterface $container): void
    {
        // Process driver
        $container->singleton('concurrency.driver.process', function ($c) {
            $serializer = $c->get(ClosureSerializerInterface::class);
            $processFactory = $c->get(ProcessFactoryInterface::class);

            // Get config values
            $phpBinary = 'php';
            $consoleBinary = 'console';
            $workingDirectory = null;
            $timeout = 60.0;

            if (function_exists('config')) {
                $phpBinary = config('concurrency.drivers.process.binary', 'php');
                $consoleBinary = config('concurrency.drivers.process.command', 'console');
                $workingDirectory = config('concurrency.drivers.process.working_directory');
                $timeout = (float) config('concurrency.timeout', 60);
            }

            // Use base_path() if available
            if ($workingDirectory === null && function_exists('base_path')) {
                $workingDirectory = base_path();
            }

            return new ProcessConcurrencyDriver(
                $processFactory,
                $serializer,
                $phpBinary,
                $consoleBinary,
                $workingDirectory,
                $timeout
            );
        });

        // Fork driver
        $container->singleton('concurrency.driver.fork', function () {
            $maxConcurrent = 4;
            $timeout = 60.0;

            if (function_exists('config')) {
                $maxConcurrent = (int) config('concurrency.max_concurrent', 4);
                $timeout = (float) config('concurrency.timeout', 60);
            }

            return new ForkConcurrencyDriver($maxConcurrent, $timeout);
        });

        // Sync driver
        $container->singleton('concurrency.driver.sync', function () {
            $timeout = 0.0;

            if (function_exists('config')) {
                $timeout = (float) config('concurrency.drivers.sync.timeout', 0);
            }

            return new SyncConcurrencyDriver($timeout);
        });
    }

    /**
     * Register the concurrency manager.
     */
    private function registerManager(ContainerInterface $container): void
    {
        $container->singleton(ConcurrencyManager::class, function ($c) {
            // Build drivers array
            $drivers = [
                'process' => $c->get('concurrency.driver.process'),
                'fork' => $c->get('concurrency.driver.fork'),
                'sync' => $c->get('concurrency.driver.sync'),
            ];

            // Get default driver from config
            $defaultDriver = 'process';
            if (function_exists('config')) {
                $defaultDriver = config('concurrency.default', 'process');
            }

            return new ConcurrencyManager($drivers, $defaultDriver);
        });

        // Interface binding
        $container->bind(ConcurrencyDriverInterface::class, function ($c) {
            return $c->get(ConcurrencyManager::class)->driver();
        });

        // Convenience aliases
        $container->bind('concurrency', function ($c) {
            return $c->get(ConcurrencyManager::class);
        });
    }

    /**
     * Register console command.
     */
    private function registerCommand(ContainerInterface $container): void
    {
        $container->bind(InvokeSerializedClosureCommand::class, function ($c) {
            $command = new InvokeSerializedClosureCommand();
            $command->setSerializer($c->get(ClosureSerializerInterface::class));
            return $command;
        });
    }

    /**
     * Boot the service provider.
     */
    public function boot(ContainerInterface $container): void
    {
        // Register console command if console kernel is available
        if ($container->has('console.kernel')) {
            $kernel = $container->get('console.kernel');
            if (method_exists($kernel, 'registerCommand')) {
                $kernel->registerCommand(InvokeSerializedClosureCommand::class);
            }
        }
    }
}
