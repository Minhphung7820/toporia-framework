<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Events\Contracts\{EventDispatcherInterface, EventInterface};

/**
 * Class Event
 *
 * Event Service Accessor - Provides static-like access to the event dispatcher.
 * Supports all dispatcher methods including wildcards, queued listeners, and subscribers.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @method static void listen(string $eventName, callable|string|ListenerInterface $listener, int $priority = 0) Register listener
 * @method static void listenClass(string $eventName, string|ListenerInterface $listener, int $priority = 0) Register class-based listener
 * @method static void listenQueue(string $eventName, callable|string|ListenerInterface $listener, int $priority = 0, int $delay = 0) Register queued listener
 * @method static void subscribe(SubscriberInterface|string $subscriber) Register event subscriber
 * @method static EventInterface dispatch(EventInterface|string $event, array $payload = []) Dispatch event
 * @method static array getListeners(string $eventName) Get listeners for event
 * @method static bool hasListeners(string $eventName) Check if event has listeners
 * @method static void removeListeners(string $eventName) Remove all listeners for event
 * @method static array getEventNames() Get all registered event names
 * @method static int countListeners(string $eventName) Count listeners for event
 * @method static void clear() Clear all listeners
 *
 * @see EventDispatcherInterface
 *
 * @example
 * // Dispatch event
 * Event::dispatch('user.created', ['user' => $user]);
 *
 * // Listen to event (closure)
 * Event::listen('user.created', function($event) {
 *     Mail::send($event->get('user'));
 * });
 *
 * // Listen to event (class-based)
 * Event::listenClass('user.created', SendWelcomeEmail::class);
 *
 * // Listen to event (queued)
 * Event::listenQueue('user.created', SendWelcomeEmail::class);
 *
 * // Wildcard listener
 * Event::listen('user.*', function($event) {
 *     Log::info('User event: ' . $event->getName());
 * });
 *
 * // With priority
 * Event::listen('user.created', $listener, priority: 100);
 *
 * // Subscribe to multiple events
 * Event::subscribe(UserEventSubscriber::class);
 */
final class Event extends ServiceAccessor
{
    protected static function getServiceName(): string
    {
        return 'events';
    }
}
