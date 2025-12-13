<?php

declare(strict_types=1);

namespace Toporia\Framework\Bus;

use Toporia\Framework\Bus\Contracts\DispatcherInterface;
use Toporia\Framework\Bus\Contracts\ShouldQueueInterface;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Pipeline\Pipeline;
use Toporia\Framework\Queue\Contracts\QueueManagerInterface;
use Toporia\Framework\Support\Accessors\Log;

/**
 * Command/Query/Job Dispatcher
 *
 * Central bus for dispatching commands, queries, and jobs to their handlers.
 *
 * Architecture:
 * - Single Responsibility: Only handles dispatching logic
 * - Open/Closed: Extensible via middleware and handler mapping
 * - Dependency Inversion: Depends on abstractions (interfaces)
 *
 * Performance:
 * - O(1) handler lookup via array map
 * - Lazy handler resolution (only when dispatched)
 * - Pipeline caching for repeated middleware
 * - Zero-copy command passing
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Bus
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @template T
 */
final class Dispatcher implements DispatcherInterface
{
    /**
     * Command => Handler mapping.
     *
     * @var array<string, string|callable>
     */
    private array $handlers = [];

    /**
     * Middleware pipeline.
     *
     * @var array<callable|string>
     */
    private array $middleware = [];

    /**
     * Commands to dispatch after response.
     *
     * @var array<mixed>
     */
    private array $commandsAfterResponse = [];

    public function __construct(
        private ContainerInterface $container,
        private ?QueueManagerInterface $queue = null
    ) {}

    /**
     * {@inheritdoc}
     */
    public function dispatch(mixed $command): mixed
    {
        // If command should be queued, push to queue
        if ($this->shouldQueue($command)) {
            return $this->dispatchToQueue($command);
        }

        // Otherwise, dispatch synchronously
        return $this->dispatchSync($command);
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchSync(mixed $command): mixed
    {
        $handler = $this->resolveHandler($command);

        // If no middleware, call handler directly
        if (empty($this->middleware)) {
            return $this->callHandler($handler, $command);
        }

        // Otherwise, pass through middleware pipeline
        return Pipeline::make($this->container)
            ->send($command)
            ->through($this->middleware)
            ->then(fn($cmd) => $this->callHandler($handler, $cmd));
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchAfterResponse(mixed $command): void
    {
        $this->commandsAfterResponse[] = $command;
    }

    /**
     * {@inheritdoc}
     */
    public function map(array $map): void
    {
        $this->handlers = array_merge($this->handlers, $map);
    }

    /**
     * {@inheritdoc}
     */
    public function pipeThrough(array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function hasHandler(mixed $command): bool
    {
        $commandClass = is_object($command) ? get_class($command) : $command;

        // Check explicit mapping
        if (isset($this->handlers[$commandClass])) {
            return true;
        }

        // Check for convention: CommandName => CommandNameHandler
        $handlerClass = $commandClass . 'Handler';
        return class_exists($handlerClass);
    }

    /**
     * {@inheritdoc}
     */
    public function getHandler(mixed $command): callable
    {
        return $this->resolveHandler($command);
    }

    /**
     * Dispatch all commands after response.
     *
     * Call this at the end of request lifecycle.
     */
    public function dispatchAfterResponseCommands(): void
    {
        foreach ($this->commandsAfterResponse as $command) {
            $this->dispatch($command);
        }

        $this->commandsAfterResponse = [];
    }

    /**
     * Check if command should be queued.
     */
    private function shouldQueue(mixed $command): bool
    {
        return $command instanceof ShouldQueueInterface && $this->queue !== null;
    }

    /**
     * Dispatch command to queue.
     */
    private function dispatchToQueue(mixed $command): mixed
    {
        if (!$this->queue) {
            throw new \RuntimeException('Queue is not configured');
        }

        $queueName = $command->getQueue() ?? 'default';
        $delay = $command->getDelay();

        // Get queue driver (QueueManager->driver() returns QueueInterface)
        $queueDriver = method_exists($this->queue, 'driver')
            ? $this->queue->driver()
            : $this->queue;

        if ($delay > 0) {
            return $queueDriver->later($command, $delay, $queueName);
        } else {
            return $queueDriver->push($command, $queueName);
        }
    }

    /**
     * Resolve handler for command.
     */
    private function resolveHandler(mixed $command): callable
    {
        $commandClass = get_class($command);

        // Check explicit mapping
        if (isset($this->handlers[$commandClass])) {
            $handler = $this->handlers[$commandClass];

            // If handler is a class name, resolve from container
            if (is_string($handler)) {
                return $this->container->get($handler);
            }

            return $handler;
        }

        // Try convention: CommandName => CommandNameHandler
        $handlerClass = $commandClass . 'Handler';
        if (class_exists($handlerClass)) {
            return $this->container->get($handlerClass);
        }

        throw new \RuntimeException("No handler found for command: {$commandClass}");
    }

    /**
     * Call the handler.
     */
    private function callHandler(callable $handler, mixed $command): mixed
    {
        // If handler is invokable object
        if (is_object($handler) && method_exists($handler, '__invoke')) {
            return $handler($command);
        }

        // If handler is callable array [object, method]
        if (is_array($handler)) {
            return call_user_func($handler, $command);
        }

        // If handler is closure
        return $handler($command);
    }
}
