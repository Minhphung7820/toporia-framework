<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Http\{Request, Response};
use Toporia\Framework\Http\Contracts\{RequestInterface, ResponseInterface};


/**
 * Class HttpServiceProvider
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
class HttpServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Request - Created per request
        // Note: Session is injected lazily by StartSession middleware, NOT here
        // This keeps SessionServiceProvider deferred for API routes
        $container->bind(RequestInterface::class, fn() => Request::capture());
        $container->bind(Request::class, fn($c) => $c->get(RequestInterface::class));
        $container->bind('request', fn($c) => $c->get(RequestInterface::class));

        // Response - Created per request
        $container->bind(ResponseInterface::class, fn() => new Response());
        $container->bind(Response::class, fn() => new Response());
        $container->bind('response', fn() => new Response());
    }
}
