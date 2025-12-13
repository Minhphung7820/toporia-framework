<?php

declare(strict_types=1);

namespace Toporia\Framework\Socialite\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Socialite\SocialiteManager;
use Toporia\Framework\Http\Contracts\HttpClientInterface;

/**
 * Class SocialiteServiceProvider
 *
 * Registers socialite services.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Socialite\Providers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SocialiteServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     */
    protected bool $defer = true;

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            SocialiteManager::class,
            'socialite',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register Socialite Manager
        $container->singleton(SocialiteManager::class, function ($c) {
            $config = $c->has('config')
                ? $c->get('config')->get('socialite', [])
                : [];

            return new SocialiteManager(
                $c,
                $c->get(HttpClientInterface::class),
                $config
            );
        });

        // Bind alias
        $container->bind('socialite', fn($c) => $c->get(SocialiteManager::class));
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Socialite services are ready
    }
}

