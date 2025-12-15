<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Http\Contracts\RequestInterface;
use Toporia\Framework\Http\Request;
use Toporia\Framework\Http\Response;
use Toporia\Framework\Http\Middleware\MiddlewarePipeline;
use Toporia\Framework\Routing\Contracts\RouterInterface;


/**
 * Class Kernel
 *
 * Core class for the HTTP request and response handling layer providing
 * essential functionality for the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
class Kernel
{
    /**
     * Global middleware stack.
     * These middleware are run on every request.
     *
     * @var array<string>
     */
    protected array $middleware = [];

    /**
     * Middleware aliases for route-specific middleware.
     * Maps short names to middleware class names.
     *
     * @var array<string, string>
     */
    protected array $middlewareAliases = [];

    /**
     * Middleware pipeline builder.
     *
     * @var MiddlewarePipeline|null
     */
    protected ?MiddlewarePipeline $pipeline = null;

    /**
     * @param ContainerInterface $container
     * @param RouterInterface $router
     */
    public function __construct(
        protected ContainerInterface $container,
        protected RouterInterface $router
    ) {}

    /**
     * Handle an incoming HTTP request.
     *
     * Executes the global middleware pipeline before routing.
     * Global middleware wraps the entire application, running on every request.
     *
     * Pipeline flow:
     * 1. Global Middleware (outermost layer)
     * 2. Router dispatch (route matching + route middleware)
     * 3. Controller/Action
     *
     * @param RequestInterface $request
     * @return void
     */
    public function handle(RequestInterface $request): void
    {
        // Bind the request to container so dependencies can resolve it
        $this->container->instance(RequestInterface::class, $request);
        $this->container->instance(Request::class, $request);

        // Get or create response instance
        $response = $this->container->get(Response::class);

        // If no global middleware, delegate directly to router (fast path)
        if (empty($this->middleware)) {
            $this->router->dispatch();
            return;
        }

        // Build global middleware pipeline around router dispatch
        $pipeline = $this->getMiddlewarePipeline();
        $coreHandler = fn(Request $req, Response $res) => $this->router->dispatch();

        $handler = $pipeline->build($this->middleware, $coreHandler);

        // Execute pipeline
        $handler($request, $response);
    }

    /**
     * Set global middleware.
     *
     * @param array<string> $middleware
     * @return self
     */
    public function setMiddleware(array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    /**
     * Get global middleware.
     *
     * @return array<string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Set middleware aliases.
     *
     * @param array<string, string> $aliases
     * @return self
     */
    public function setMiddlewareAliases(array $aliases): self
    {
        $this->middlewareAliases = $aliases;
        return $this;
    }

    /**
     * Get middleware aliases.
     *
     * @return array<string, string>
     */
    public function getMiddlewareAliases(): array
    {
        return $this->middlewareAliases;
    }

    /**
     * Resolve middleware alias to class name.
     *
     * @param string $alias
     * @return string
     */
    public function resolveMiddleware(string $alias): string
    {
        return $this->middlewareAliases[$alias] ?? $alias;
    }

    /**
     * Get or create middleware pipeline.
     *
     * Lazy initialization with alias registration.
     *
     * @return MiddlewarePipeline
     */
    protected function getMiddlewarePipeline(): MiddlewarePipeline
    {
        if ($this->pipeline === null) {
            $this->pipeline = new MiddlewarePipeline($this->container, $this->middlewareAliases);
        }

        return $this->pipeline;
    }
}
