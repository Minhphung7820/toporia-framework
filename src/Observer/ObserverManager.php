<?php

declare(strict_types=1);

namespace Toporia\Framework\Observer;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Observer\Contracts\{ObserverInterface, ObserverManagerInterface};

/**
 * Class ObserverManager
 *
 * Central manager for registering and managing observers with performance optimizations.
 *
 * Performance Optimizations:
 * - Observer instance caching (singleton observers)
 * - Lazy observer instantiation (only when needed)
 * - Event-specific observer indexing (O(1) lookup)
 * - Priority-based observer sorting (cached)
 * - Observer class name resolution caching
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Observer
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ObserverManager implements ObserverManagerInterface
{
    /**
     * @var array<string, array<string, array<int, ObserverInterface|string>>> Observers grouped by observable class, event, and priority
     * Format: [observableClass][event][priority][] = observer
     */
    private array $registry = [];

    /**
     * @var array<string, array<string, array<ObserverInterface>>>|null Cached sorted observers
     * Format: [observableClass][event][] = observer (sorted by priority)
     */
    private ?array $sortedCache = null;

    /**
     * @var array<string, ObserverInterface> Cached observer instances (singleton pattern)
     */
    private array $instanceCache = [];

    /**
     * @var ContainerInterface|null Container for dependency injection
     */
    private ?ContainerInterface $container = null;

    /**
     * @param ContainerInterface|null $container Container for observer instantiation
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $observableClass, ObserverInterface|string $observer, ?string $event = null, int $priority = 0): void
    {
        $eventKey = $event ?? '*';

        // Normalize observable class name
        $observableClass = $this->normalizeClassName($observableClass);

        // Store observer (can be class name or instance)
        $this->registry[$observableClass][$eventKey][$priority][] = $observer;

        // Invalidate cache
        $this->sortedCache = null;
    }

    /**
     * {@inheritdoc}
     */
    public function unregister(string $observableClass, ObserverInterface|string $observer, ?string $event = null): void
    {
        $eventKey = $event ?? '*';
        $observableClass = $this->normalizeClassName($observableClass);

        if (!isset($this->registry[$observableClass][$eventKey])) {
            return;
        }

        // Remove observer from all priorities
        foreach ($this->registry[$observableClass][$eventKey] as $priority => $observers) {
            /** @var array<int, ObserverInterface|string> $observers */
            $this->registry[$observableClass][$eventKey][$priority] = array_filter(
                $observers,
                fn($obs) => $obs !== $observer && (is_string($obs) ? $obs !== (is_string($observer) ? $observer : get_class($observer)) : $obs !== $observer)
            );

            // Remove empty priority arrays
            if (empty($this->registry[$observableClass][$eventKey][$priority])) {
                unset($this->registry[$observableClass][$eventKey][$priority]);
            }
        }

        // Remove event key if empty
        if (empty($this->registry[$observableClass][$eventKey])) {
            unset($this->registry[$observableClass][$eventKey]);
        }

        // Invalidate cache
        $this->sortedCache = null;
    }

    /**
     * {@inheritdoc}
     */
    public function getObservers(string $observableClass, ?string $event = null): array
    {
        $eventKey = $event ?? '*';
        $observableClass = $this->normalizeClassName($observableClass);

        // Return cached sorted observers if available
        if (isset($this->sortedCache[$observableClass][$eventKey])) {
            return $this->sortedCache[$observableClass][$eventKey];
        }

        // Get observers for specific event and wildcard
        $allObservers = [];

        // Event-specific observers
        if (isset($this->registry[$observableClass][$eventKey])) {
            $allObservers = array_merge($allObservers, $this->registry[$observableClass][$eventKey]);
        }

        // Wildcard observers (for all events)
        if ($eventKey !== '*' && isset($this->registry[$observableClass]['*'])) {
            $allObservers = array_merge($allObservers, $this->registry[$observableClass]['*']);
        }

        if (empty($allObservers)) {
            $this->sortedCache[$observableClass][$eventKey] = [];
            return [];
        }

        // Sort by priority (higher priority first)
        krsort($allObservers);

        // Flatten and instantiate observers
        $sorted = [];
        foreach ($allObservers as $priority => $observers) {
            foreach ($observers as $observer) {
                $instance = $this->resolveObserver($observer);
                if ($instance) {
                    $sorted[] = $instance;
                }
            }
        }

        // Cache the sorted result
        if (!isset($this->sortedCache[$observableClass])) {
            $this->sortedCache[$observableClass] = [];
        }
        $this->sortedCache[$observableClass][$eventKey] = $sorted;

        return $sorted;
    }

    /**
     * {@inheritdoc}
     */
    public function hasObservers(string $observableClass, ?string $event = null): bool
    {
        return !empty($this->getObservers($observableClass, $event));
    }

    /**
     * {@inheritdoc}
     */
    public function clear(string $observableClass): void
    {
        $observableClass = $this->normalizeClassName($observableClass);
        unset($this->registry[$observableClass]);
        if (isset($this->sortedCache[$observableClass])) {
            unset($this->sortedCache[$observableClass]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clearAll(): void
    {
        $this->registry = [];
        $this->sortedCache = null;
        $this->instanceCache = [];
    }

    /**
     * Resolve observer to instance.
     *
     * Performance: Caches observer instances (singleton pattern)
     *
     * @param ObserverInterface|string $observer Observer instance or class name
     * @return ObserverInterface|null
     */
    private function resolveObserver(ObserverInterface|string $observer): ?ObserverInterface
    {
        // If already an instance, return it
        if ($observer instanceof ObserverInterface) {
            return $observer;
        }

        // If class name, instantiate (with caching)
        if (is_string($observer)) {
            // Check cache first
            if (isset($this->instanceCache[$observer])) {
                return $this->instanceCache[$observer];
            }

            // Try to resolve from container (supports dependency injection)
            if ($this->container) {
                try {
                    $instance = $this->container->get($observer);
                    if ($instance instanceof ObserverInterface) {
                        $this->instanceCache[$observer] = $instance;
                        return $instance;
                    }
                } catch (\Throwable $e) {
                    // Container resolution failed, try direct instantiation
                }
            }

            // Direct instantiation (no dependencies)
            if (class_exists($observer)) {
                try {
                    $instance = new $observer();
                    if ($instance instanceof ObserverInterface) {
                        $this->instanceCache[$observer] = $instance;
                        return $instance;
                    }
                } catch (\Throwable $e) {
                    error_log("Failed to instantiate observer {$observer}: {$e->getMessage()}");
                }
            }
        }

        return null;
    }

    /**
     * Normalize class name (handle leading backslash).
     *
     * @param string $className Class name
     * @return string Normalized class name
     */
    private function normalizeClassName(string $className): string
    {
        return ltrim($className, '\\');
    }

    /**
     * Get all registered observable classes.
     *
     * @return array<string>
     */
    public function getObservableClasses(): array
    {
        return array_keys($this->registry);
    }

    /**
     * Get statistics about registered observers.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        $stats = [
            'observable_classes' => count($this->registry),
            'total_observers' => 0,
            'cached_instances' => count($this->instanceCache),
        ];

        foreach ($this->registry as $observableClass => $events) {
            foreach ($events as $event => $priorities) {
                foreach ($priorities as $priority => $observers) {
                    /** @var array<int, ObserverInterface|string> $observers */
                    $stats['total_observers'] += count($observers);
                }
            }
        }

        return $stats;
    }
}
