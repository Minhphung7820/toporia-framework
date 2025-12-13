<?php

declare(strict_types=1);

namespace Toporia\Framework\Application\Handler;

use Toporia\Framework\Application\Contracts\HandlerInterface;
use Toporia\Framework\Application\Exception\HandlerNotFoundException;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Handler Resolver
 *
 * Resolves handlers for commands and queries based on naming conventions.
 *
 * Naming Convention:
 * - Command: CreateProductCommand → CreateProductHandler
 * - Query: GetProductQuery → GetProductHandler
 *
 * Resolves from container or creates instance if not registered.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Application\Handler
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class HandlerResolver
{
    /**
     * @param ContainerInterface $container DI container
     */
    public function __construct(
        private readonly ContainerInterface $container
    ) {}

    /**
     * Resolve handler for a command or query.
     *
     * @param object $message Command or Query object
     * @return HandlerInterface The resolved handler
     * @throws HandlerNotFoundException If handler cannot be resolved
     */
    public function resolve(object $message): HandlerInterface
    {
        $handlerClass = $this->getHandlerClass($message);

        // Try to resolve from container first
        if ($this->container->has($handlerClass)) {
            $handler = $this->container->get($handlerClass);
            if ($handler instanceof HandlerInterface) {
                return $handler;
            }
        }

        // Try to resolve by interface binding (HandlerInterface)
        $handlerId = $this->getHandlerId($message);
        if ($this->container->has($handlerId)) {
            $handler = $this->container->get($handlerId);
            if ($handler instanceof HandlerInterface) {
                return $handler;
            }
        }

        // Try to instantiate directly
        if (class_exists($handlerClass)) {
            // Try to resolve via container with auto-wiring
            try {
                $handler = $this->container->get($handlerClass);
                if ($handler instanceof HandlerInterface) {
                    return $handler;
                }
            } catch (\Exception $e) {
                // Fallback to direct instantiation
                $handler = new $handlerClass();
                if ($handler instanceof HandlerInterface) {
                    return $handler;
                }
            }
        }

        throw HandlerNotFoundException::forMessage($message);
    }

    /**
     * Get handler class name from message class name.
     *
     * Converts: CreateProductCommand → CreateProductHandler
     * Converts: GetProductQuery → GetProductHandler
     *
     * @param object $message Command or Query object
     * @return string Handler class name
     */
    private function getHandlerClass(object $message): string
    {
        $messageClass = get_class($message);

        // Extract class name without namespace
        $lastSeparator = strrpos($messageClass, '\\');
        $messageName = $lastSeparator !== false
            ? substr($messageClass, $lastSeparator + 1)
            : $messageClass;

        $namespace = $lastSeparator !== false
            ? substr($messageClass, 0, $lastSeparator)
            : '';

        // Remove Command/Query suffix and add Handler
        $handlerName = preg_replace('/(Command|Query)$/', 'Handler', $messageName);

        return $namespace !== '' ? $namespace . '\\' . $handlerName : $handlerName;
    }

    /**
     * Get handler identifier for container lookup.
     *
     * @param object $message Command or Query object
     * @return string Handler identifier
     */
    private function getHandlerId(object $message): string
    {
        return get_class($message) . ':Handler';
    }
}
