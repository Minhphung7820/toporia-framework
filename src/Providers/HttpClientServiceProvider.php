<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Http\Client\ClientManager;
use Toporia\Framework\Http\Contracts\{ClientManagerInterface, HttpClientInterface};

/**
 * Class HttpClientServiceProvider
 *
 * Registers HTTP client services for calling external APIs.
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
final class HttpClientServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Register ClientManager singleton
        $container->singleton(ClientManager::class, function ($c) {
            $config = $c->has('config')
                ? $c->get('config')->get('http', [])
                : $this->getDefaultConfig();

            return new ClientManager($config);
        });

        // Bind interfaces
        $container->bind(ClientManagerInterface::class, fn($c) => $c->get(ClientManager::class));
        $container->bind(HttpClientInterface::class, fn($c) => $c->get(ClientManager::class)->client());

        // Bind aliases
        $container->bind('http', fn($c) => $c->get(ClientManager::class));
        $container->bind('http.client', fn($c) => $c->get(ClientManager::class));
    }

    public function boot(ContainerInterface $container): void
    {
        // HTTP client is ready to use
    }

    /**
     * Get default HTTP client configuration
     *
     * @return array
     */
    private function getDefaultConfig(): array
    {
        return [
            'default' => 'default',
            'clients' => [
                'default' => [
                    'driver' => 'rest',
                    'timeout' => 30,
                ],
            ],
        ];
    }
}
