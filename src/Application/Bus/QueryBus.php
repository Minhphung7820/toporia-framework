<?php

declare(strict_types=1);

namespace Toporia\Framework\Application\Bus;

use Toporia\Framework\Application\Contracts\HandlerInterface;
use Toporia\Framework\Application\Contracts\QueryInterface;
use Toporia\Framework\Application\Handler\HandlerResolver;
use Toporia\Framework\Application\Middleware\MiddlewarePipeline;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Query Bus
 *
 * Dispatches queries through middleware pipeline to their handlers.
 * Implements CQRS pattern for read operations.
 *
 * Architecture:
 * - Separates query dispatch from handler execution
 * - Supports middleware for cross-cutting concerns
 * - Automatic handler resolution via HandlerResolver
 *
 * Flow:
 * Query → Middleware Pipeline → Handler → Result
 *
 * SOLID Principles:
 * - Single Responsibility: Dispatches queries only
 * - Open/Closed: Extensible via middleware
 * - Dependency Inversion: Depends on abstractions
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Application\Bus
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class QueryBus
{
    /**
     * @param ContainerInterface $container DI container
     * @param HandlerResolver $resolver Handler resolver
     * @param array<int, string|callable> $middleware Global middleware stack
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly HandlerResolver $resolver,
        private readonly array $middleware = []
    ) {}

    /**
     * Dispatch a query through the middleware pipeline.
     *
     * @param QueryInterface $query The query to dispatch
     * @return mixed Result from the handler
     * @throws \Exception If handler execution fails
     */
    public function ask(QueryInterface $query): mixed
    {
        // Validate query
        $query->validate();

        // Resolve handler
        $handler = $this->resolver->resolve($query);

        // Build middleware pipeline
        $pipeline = new MiddlewarePipeline($this->container);

        // Execute through pipeline
        return $pipeline->send($query)
            ->through($this->middleware)
            ->then(fn(QueryInterface $q) => $handler($q));
    }
}
