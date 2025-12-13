<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Http\{Request, Response};
use Toporia\Framework\Routing\Router;
use Toporia\Framework\Routing\Contracts\RouterInterface;


/**
 * Class RoutingServiceProvider
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
class RoutingServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Router - Singleton with injected dependencies
        $container->singleton(RouterInterface::class, fn(ContainerInterface $c) => new Router(
            $c->get(Request::class),
            $c->get(Response::class),
            $c
        ));

        $container->singleton(Router::class, fn(ContainerInterface $c) => new Router(
            $c->get(Request::class),
            $c->get(Response::class),
            $c
        ));

        $container->singleton('router', fn(ContainerInterface $c) => $c->get(Router::class));
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        /** @var Router $router */
        $router = $container->get(Router::class);

        // Load middleware aliases from config
        $middlewareAliases = $this->getMiddlewareAliases($container);
        $router->setMiddlewareAliases($middlewareAliases);
    }

    /**
     * Get middleware aliases from configuration.
     *
     * @param ContainerInterface $container
     * @return array<string, string>
     */
    protected function getMiddlewareAliases(ContainerInterface $container): array
    {
        try {
            if (!$container->has('config')) {
                return [];
            }

            $config = $container->get('config');
            return $config->get('middleware.aliases', []);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
