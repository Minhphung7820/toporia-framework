<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Contracts;


/**
 * Interface EventDispatcherInterface
 *
 * Contract defining the interface for EventDispatcherInterface
 * implementations in the Event dispatching and listening layer of the
 * Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface EventDispatcherInterface
{
    /**
     * Register an event listener.
     *
     * @param string $eventName Event name or class (supports wildcards: 'user.*')
     * @param callable|string|ListenerInterface $listener Callable, class name, or listener instance
     * @param int $priority Higher priority listeners execute first (default: 0)
     * @return void
     */
    public function listen(string $eventName, callable|string|ListenerInterface $listener, int $priority = 0): void;

    /**
     * Dispatch an event to all registered listeners.
     *
     * @param string|EventInterface $event Event name or event object
     * @param array $payload Event data (used if event is string)
     * @return EventInterface The event object after dispatch
     */
    public function dispatch(string|EventInterface $event, array $payload = []): EventInterface;

    /**
     * Check if an event has listeners.
     *
     * @param string $eventName Event name
     * @return bool
     */
    public function hasListeners(string $eventName): bool;

    /**
     * Remove all listeners for an event.
     *
     * @param string $eventName Event name
     * @return void
     */
    public function removeListeners(string $eventName): void;

    /**
     * Get all listeners for an event.
     *
     * @param string $eventName Event name
     * @return array<callable|ListenerInterface>
     */
    public function getListeners(string $eventName): array;
}
