<?php

declare(strict_types=1);

namespace Toporia\Framework\Events;

use Toporia\Framework\Events\Contracts\{EventDispatcherInterface, EventInterface, ListenerInterface, SubscriberInterface, ShouldQueue};
use Toporia\Framework\Events\Exceptions\{EventException, ListenerException, CircularDispatchException, QueueNotAvailableException};
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Queue\Contracts\QueueInterface;

/**
 * Class Dispatcher
 *
 * Advanced event dispatcher with support for priority-based listener execution,
 * wildcard event listeners, class-based listeners, queued listeners, event subscribers,
 * circular dispatch detection, and performance optimizations.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Dispatcher implements EventDispatcherInterface
{
    /**
     * Maximum cache size for wildcard pattern matching.
     */
    private const MAX_WILDCARD_CACHE_SIZE = 1000;

    /**
     * Maximum dispatch depth to prevent infinite loops.
     */
    private const MAX_DISPATCH_DEPTH = 10;

    /**
     * Priority bounds for validation.
     */
    private const MIN_PRIORITY = -1000;
    private const MAX_PRIORITY = 1000;

    /**
     * @var array<string, array<int, array<callable|string|ListenerInterface>>> Event listeners
     */
    private array $listeners = [];

    /**
     * @var array<string, array<callable|ListenerInterface>>|null Sorted listeners cache
     */
    private ?array $sortedListeners = null;

    /**
     * @var array<string, array<int, array<callable|string|ListenerInterface>>> Wildcard listeners
     */
    private array $wildcards = [];

    /**
     * @var array<string, array<callable|ListenerInterface>>|null Sorted wildcard listeners cache
     */
    private ?array $sortedWildcards = null;

    /**
     * @var array<string, bool> Wildcard pattern match cache
     */
    private array $wildcardCache = [];

    /**
     * @var array<string, bool> Cache for hasWildcardListeners results
     */
    private array $hasWildcardCache = [];

    /**
     * @var array<string> Current dispatch stack for circular detection
     */
    private array $dispatchStack = [];

    /**
     * @param ContainerInterface|null $container Container for resolving listeners
     * @param QueueInterface|null $queue Queue for queued listeners
     */
    public function __construct(
        private ?ContainerInterface $container = null,
        private ?QueueInterface $queue = null
    ) {}

    /**
     * {@inheritdoc}
     *
     * @throws EventException If event name is invalid
     */
    public function listen(string $eventName, callable|string|ListenerInterface $listener, int $priority = 0): void
    {
        // Validate event name
        $this->validateEventName($eventName);

        // Validate priority
        $this->validatePriority($priority);

        // Validate wildcard pattern if applicable
        if ($this->isWildcard($eventName)) {
            $this->validateWildcardPattern($eventName);
            $this->wildcards[$eventName][$priority][] = $listener;
            $this->sortedWildcards = null;
            $this->hasWildcardCache = [];
        } else {
            $this->listeners[$eventName][$priority][] = $listener;
        }

        // Invalidate caches
        $this->sortedListeners = null;
        $this->wildcardCache = [];
    }

    /**
     * Register a class-based listener.
     *
     * @param string $eventName Event name
     * @param string|ListenerInterface $listener Listener class name or instance
     * @param int $priority Listener priority
     * @return void
     * @throws EventException If event name is invalid
     */
    public function listenClass(string $eventName, string|ListenerInterface $listener, int $priority = 0): void
    {
        $this->listen($eventName, $listener, $priority);
    }

    /**
     * Register a queued listener.
     *
     * @param string $eventName Event name
     * @param callable|string|ListenerInterface $listener Listener to queue
     * @param int $priority Listener priority
     * @param int $delay Delay in seconds
     * @return void
     * @throws QueueNotAvailableException If queue service not available
     * @throws ListenerException If listener is a closure
     */
    public function listenQueue(string $eventName, callable|string|ListenerInterface $listener, int $priority = 0, int $delay = 0): void
    {
        if ($this->queue === null) {
            $listenerName = is_string($listener) ? $listener : get_debug_type($listener);
            throw QueueNotAvailableException::forListener($listenerName);
        }

        // Prevent queuing closures early (fail fast)
        if ($listener instanceof \Closure) {
            throw ListenerException::closureNotQueueable($eventName);
        }

        $queuedListener = new QueuedListener($listener, $this->queue, $delay);
        $this->listen($eventName, $queuedListener, $priority);
    }

    /**
     * Register an event subscriber.
     *
     * @param SubscriberInterface|string $subscriber Subscriber instance or class name
     * @return void
     * @throws \RuntimeException If container not available for string subscriber
     * @throws \InvalidArgumentException If subscriber doesn't implement SubscriberInterface
     */
    public function subscribe(SubscriberInterface|string $subscriber): void
    {
        // Resolve subscriber from container if string
        if (is_string($subscriber)) {
            if ($this->container === null) {
                throw new \RuntimeException('Container is required for subscriber resolution. Please register ContainerInterface.');
            }

            $resolved = $this->container->get($subscriber);
            if (!$resolved instanceof SubscriberInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Subscriber "%s" must implement SubscriberInterface.', $subscriber)
                );
            }
            $subscriber = $resolved;
        }

        // Get subscriptions from subscriber
        $subscriptions = $subscriber->subscribe($this);

        // Register all subscriptions
        foreach ($subscriptions as $eventName => $listener) {
            if (is_array($listener)) {
                [$callable, $priority] = $listener;
                $this->registerListener($eventName, $callable, $priority);
            } else {
                $this->registerListener($eventName, $listener);
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws CircularDispatchException If dispatch depth exceeded
     */
    public function dispatch(string|EventInterface $event, array $payload = []): EventInterface
    {
        // Convert string to GenericEvent
        if (is_string($event)) {
            $eventName = $event;
            $event = new GenericEvent($eventName, $payload);
        } else {
            $eventName = $event->getName();
        }

        // Check for circular dispatch
        $this->checkCircularDispatch($eventName);

        // Push to dispatch stack
        $this->dispatchStack[] = $eventName;

        try {
            // Get all listeners (direct + wildcard)
            $listeners = $this->getAllListeners($eventName);

            // Dispatch to each listener
            foreach ($listeners as $listener) {
                // Stop if propagation was stopped
                if ($event->isPropagationStopped()) {
                    break;
                }

                $this->callListener($listener, $event, $eventName);
            }

            return $event;
        } finally {
            // Pop from dispatch stack
            array_pop($this->dispatchStack);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasListeners(string $eventName): bool
    {
        // Check direct listeners first (O(1))
        if (!empty($this->listeners[$eventName])) {
            return true;
        }

        // Check cached wildcard result
        if (isset($this->hasWildcardCache[$eventName])) {
            return $this->hasWildcardCache[$eventName];
        }

        // Check wildcard listeners and cache result
        $hasWildcard = $this->hasWildcardListeners($eventName);
        $this->hasWildcardCache[$eventName] = $hasWildcard;

        return $hasWildcard;
    }

    /**
     * {@inheritdoc}
     */
    public function removeListeners(string $eventName): void
    {
        unset($this->listeners[$eventName]);
        $this->invalidateCaches();
    }

    /**
     * {@inheritdoc}
     */
    public function getListeners(string $eventName): array
    {
        return $this->getAllListeners($eventName);
    }

    /**
     * Get all listeners for an event (direct + wildcard).
     *
     * Performance optimized: Uses spread operator instead of array_merge.
     *
     * @param string $eventName Event name
     * @return array<callable|ListenerInterface>
     */
    private function getAllListeners(string $eventName): array
    {
        $listeners = [];

        // Get direct listeners
        if (isset($this->listeners[$eventName])) {
            $listeners = $this->getSortedListeners($eventName);
        }

        // Get wildcard listeners
        $wildcardListeners = $this->getWildcardListeners($eventName);

        if (empty($wildcardListeners)) {
            return $listeners;
        }

        if (empty($listeners)) {
            return $wildcardListeners;
        }

        // Merge both arrays (spread is efficient for this case)
        return [...$listeners, ...$wildcardListeners];
    }

    /**
     * Get sorted listeners for an event.
     *
     * @param string $eventName Event name
     * @return array<callable|ListenerInterface>
     */
    private function getSortedListeners(string $eventName): array
    {
        // Return cached if available
        if (isset($this->sortedListeners[$eventName])) {
            return $this->sortedListeners[$eventName];
        }

        if (!isset($this->listeners[$eventName])) {
            return [];
        }

        // Sort listeners by priority (higher priority first)
        $prioritizedListeners = $this->listeners[$eventName];
        krsort($prioritizedListeners);

        // Flatten and resolve listeners
        $sorted = [];
        foreach ($prioritizedListeners as $listeners) {
            foreach ($listeners as $listener) {
                $sorted[] = $this->resolveListener($listener, $eventName);
            }
        }

        // Cache the sorted result
        $this->sortedListeners[$eventName] = $sorted;

        return $sorted;
    }

    /**
     * Get wildcard listeners matching event name.
     *
     * Performance optimized: Caches sorted wildcard listeners per pattern.
     *
     * @param string $eventName Event name
     * @return array<callable|ListenerInterface>
     */
    private function getWildcardListeners(string $eventName): array
    {
        if (empty($this->wildcards)) {
            return [];
        }

        $listeners = [];

        foreach ($this->wildcards as $pattern => $prioritizedListeners) {
            if ($this->matchesWildcard($pattern, $eventName)) {
                // Get sorted listeners for this pattern (cached)
                $patternListeners = $this->getSortedWildcardListeners($pattern);
                foreach ($patternListeners as $listener) {
                    $listeners[] = $listener;
                }
            }
        }

        return $listeners;
    }

    /**
     * Get sorted listeners for a wildcard pattern.
     *
     * Performance: Sorts once and caches result.
     *
     * @param string $pattern Wildcard pattern
     * @return array<callable|ListenerInterface>
     */
    private function getSortedWildcardListeners(string $pattern): array
    {
        // Return cached if available
        if (isset($this->sortedWildcards[$pattern])) {
            return $this->sortedWildcards[$pattern];
        }

        if (!isset($this->wildcards[$pattern])) {
            return [];
        }

        // Sort by priority (higher first)
        $prioritizedListeners = $this->wildcards[$pattern];
        krsort($prioritizedListeners);

        // Flatten and resolve
        $sorted = [];
        foreach ($prioritizedListeners as $listeners) {
            foreach ($listeners as $listener) {
                $sorted[] = $this->resolveListener($listener);
            }
        }

        // Cache result
        $this->sortedWildcards[$pattern] = $sorted;

        return $sorted;
    }

    /**
     * Check if event name matches wildcard pattern.
     *
     * Performance: Uses bounded LRU-like cache.
     *
     * @param string $pattern Wildcard pattern
     * @param string $eventName Event name
     * @return bool
     */
    private function matchesWildcard(string $pattern, string $eventName): bool
    {
        $cacheKey = "{$pattern}:{$eventName}";

        if (isset($this->wildcardCache[$cacheKey])) {
            return $this->wildcardCache[$cacheKey];
        }

        // Convert wildcard pattern to regex
        $regex = '#^' . str_replace(['*', '.'], ['[^.]*', '\.'], $pattern) . '$#';
        $matches = preg_match($regex, $eventName) === 1;

        // Bounded cache: evict oldest half when full
        if (count($this->wildcardCache) >= self::MAX_WILDCARD_CACHE_SIZE) {
            $this->wildcardCache = array_slice(
                $this->wildcardCache,
                (int)(self::MAX_WILDCARD_CACHE_SIZE / 2),
                null,
                true
            );
        }

        // Cache result
        $this->wildcardCache[$cacheKey] = $matches;

        return $matches;
    }

    /**
     * Check if event name is a wildcard pattern.
     *
     * @param string $eventName Event name
     * @return bool
     */
    private function isWildcard(string $eventName): bool
    {
        return str_contains($eventName, '*');
    }

    /**
     * Check if event has wildcard listeners.
     *
     * @param string $eventName Event name
     * @return bool
     */
    private function hasWildcardListeners(string $eventName): bool
    {
        foreach (array_keys($this->wildcards) as $pattern) {
            if ($this->matchesWildcard($pattern, $eventName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve listener (string to instance).
     *
     * @param callable|string|ListenerInterface $listener Listener
     * @param string|null $eventName Event name for error context
     * @return callable|ListenerInterface
     * @throws ListenerException If listener cannot be resolved
     */
    private function resolveListener(callable|string|ListenerInterface $listener, ?string $eventName = null): callable|ListenerInterface
    {
        // Already resolved
        if (is_callable($listener) || $listener instanceof ListenerInterface) {
            return $listener;
        }

        // Resolve from container
        if (is_string($listener)) {
            if ($this->container !== null) {
                if ($this->container->has($listener)) {
                    return $this->container->get($listener);
                }

                if (class_exists($listener)) {
                    return $this->container->get($listener);
                }
            } elseif (class_exists($listener)) {
                // No container, but class exists - instantiate directly
                return new $listener();
            }

            // Cannot resolve - throw explicit error
            throw ListenerException::unresolvable($listener, $eventName);
        }

        return $listener;
    }

    /**
     * Call listener with event.
     *
     * @param callable|ListenerInterface $listener Listener
     * @param EventInterface $event Event instance
     * @param string $eventName Event name for error context
     * @return void
     */
    private function callListener(callable|ListenerInterface $listener, EventInterface $event, string $eventName): void
    {
        try {
            if ($listener instanceof ListenerInterface) {
                $listener->handle($event);
            } elseif (is_callable($listener)) {
                $listener($event);
            }
        } catch (\Throwable $e) {
            // Wrap exception with context
            $listenerName = is_object($listener) ? get_class($listener) : get_debug_type($listener);
            throw ListenerException::executionFailed($listenerName, $eventName, $e);
        }
    }

    /**
     * Register a listener (internal helper).
     *
     * @param string $eventName Event name
     * @param callable|string|ListenerInterface $listener Listener
     * @param int $priority Priority
     * @return void
     */
    private function registerListener(string $eventName, callable|string|ListenerInterface $listener, int $priority = 0): void
    {
        // Check if listener should be queued
        if ($listener instanceof ShouldQueue || (is_string($listener) && $this->shouldQueueListener($listener))) {
            $this->listenQueue($eventName, $listener, $priority);
            return;
        }

        $this->listen($eventName, $listener, $priority);
    }

    /**
     * Check if listener class should be queued.
     *
     * @param string $listenerClass Listener class name
     * @return bool
     */
    private function shouldQueueListener(string $listenerClass): bool
    {
        if (!class_exists($listenerClass)) {
            return false;
        }

        $reflection = new \ReflectionClass($listenerClass);
        return $reflection->implementsInterface(ShouldQueue::class);
    }

    /**
     * Check for circular dispatch.
     *
     * @param string $eventName Event name
     * @return void
     * @throws CircularDispatchException If circular dispatch detected
     */
    private function checkCircularDispatch(string $eventName): void
    {
        // Check max depth
        if (count($this->dispatchStack) >= self::MAX_DISPATCH_DEPTH) {
            throw CircularDispatchException::maxDepthExceeded(
                $this->dispatchStack,
                $eventName,
                self::MAX_DISPATCH_DEPTH
            );
        }

        // Check self-dispatch (same event already in stack)
        if (in_array($eventName, $this->dispatchStack, true)) {
            throw CircularDispatchException::selfDispatch($eventName);
        }
    }

    /**
     * Validate event name.
     *
     * @param string $eventName Event name
     * @return void
     * @throws EventException If event name is invalid
     */
    private function validateEventName(string $eventName): void
    {
        if (trim($eventName) === '') {
            throw EventException::invalidEventName($eventName);
        }
    }

    /**
     * Validate priority value.
     *
     * @param int $priority Priority value
     * @return void
     * @throws \DomainException If priority out of bounds
     */
    private function validatePriority(int $priority): void
    {
        if ($priority < self::MIN_PRIORITY || $priority > self::MAX_PRIORITY) {
            throw new \DomainException(
                sprintf('Priority must be between %d and %d, got %d.', self::MIN_PRIORITY, self::MAX_PRIORITY, $priority)
            );
        }
    }

    /**
     * Validate wildcard pattern.
     *
     * @param string $pattern Wildcard pattern
     * @return void
     * @throws EventException If pattern is invalid
     */
    private function validateWildcardPattern(string $pattern): void
    {
        // Check for empty segments
        if (str_contains($pattern, '..')) {
            throw EventException::invalidWildcardPattern($pattern, 'Pattern contains empty segments (..)');
        }

        // Check for consecutive wildcards
        if (str_contains($pattern, '**')) {
            throw EventException::invalidWildcardPattern($pattern, 'Pattern contains consecutive wildcards (**)');
        }

        // Validate regex compilation
        $regex = '#^' . str_replace(['*', '.'], ['[^.]*', '\.'], $pattern) . '$#';
        if (@preg_match($regex, '') === false) {
            throw EventException::invalidWildcardPattern($pattern, 'Pattern produces invalid regex');
        }
    }

    /**
     * Invalidate all caches.
     *
     * @return void
     */
    private function invalidateCaches(): void
    {
        $this->sortedListeners = null;
        $this->sortedWildcards = null;
        $this->wildcardCache = [];
        $this->hasWildcardCache = [];
    }

    /**
     * Get all registered event names.
     *
     * @return array<string>
     */
    public function getEventNames(): array
    {
        return array_unique(array_merge(
            array_keys($this->listeners),
            array_keys($this->wildcards)
        ));
    }

    /**
     * Count listeners for an event.
     *
     * @param string $eventName Event name
     * @return int
     */
    public function countListeners(string $eventName): int
    {
        return count($this->getAllListeners($eventName));
    }

    /**
     * Clear all listeners and cache.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->listeners = [];
        $this->wildcards = [];
        $this->dispatchStack = [];
        $this->invalidateCaches();
    }

    /**
     * Clear only caches (useful for long-running processes).
     *
     * This method clears cached data without removing listeners.
     * Call periodically in queue workers or long-running processes
     * to prevent memory bloat.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->invalidateCaches();
    }

    /**
     * Get current dispatch depth.
     *
     * Useful for debugging and testing.
     *
     * @return int
     */
    public function getDispatchDepth(): int
    {
        return count($this->dispatchStack);
    }

    /**
     * Get current dispatch stack.
     *
     * Useful for debugging circular dispatch issues.
     *
     * @return array<string>
     */
    public function getDispatchStack(): array
    {
        return $this->dispatchStack;
    }
}
