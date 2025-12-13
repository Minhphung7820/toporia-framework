<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Hashing\HashManager;

/**
 * Class HashServiceProvider
 *
 * Registers hashing services into the container.
 * Provides password hashing functionality across the application.
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
final class HashServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register HashManager as singleton
        $container->singleton('hash', function ($c) {
            // Get hashing configuration
            $config = [];
            if ($c->has('config')) {
                $config = $c->get('config')->get('hashing', []);
            }

            return new HashManager($config);
        });

        // Register HasherInterface binding (for dependency injection)
        $container->bind(
            \Toporia\Framework\Hashing\Contracts\HasherInterface::class,
            fn($c) => $c->get('hash')->driver()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Nothing to boot for hash service
    }
}
