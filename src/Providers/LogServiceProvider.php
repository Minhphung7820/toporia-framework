<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Log\LogManager;
use Toporia\Framework\Log\Contracts\LoggerInterface;

/**
 * Class LogServiceProvider
 *
 * Registers logging services into the container.
 * Provides both LogManager (multi-channel) and default logger.
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
final class LogServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Register LogManager singleton
        $container->singleton(LogManager::class, function ($c) {
            $config = $c->get('config')->get('logging', []);
            return new LogManager($config);
        });

        // Register default logger (convenience binding)
        $container->singleton(LoggerInterface::class, function ($c) {
            return $c->get(LogManager::class)->channel();
        });

        // Convenience bindings
        $container->bind('log', fn($c) => $c->get(LogManager::class));
        $container->bind('logger', fn($c) => $c->get(LoggerInterface::class));
    }
}
