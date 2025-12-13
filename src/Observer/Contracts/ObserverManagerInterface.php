<?php

declare(strict_types=1);

namespace Toporia\Framework\Observer\Contracts;


/**
 * Interface ObserverManagerInterface
 *
 * Contract defining the interface for ObserverManagerInterface
 * implementations in the Observer layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Observer\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ObserverManagerInterface
{
    /**
     * Register an observer for a specific observable class and event.
     *
     * @param string $observableClass The class name of the observable (e.g., ProductModel::class)
     * @param ObserverInterface|string $observer Observer instance or class name
     * @param string|null $event Specific event to observe (null = all events)
     * @param int $priority Observer priority (higher = executed first, default: 0)
     * @return void
     */
    public function register(string $observableClass, ObserverInterface|string $observer, ?string $event = null, int $priority = 0): void;

    /**
     * Unregister an observer.
     *
     * @param string $observableClass The class name of the observable
     * @param ObserverInterface|string $observer Observer instance or class name
     * @param string|null $event Specific event (null = all events)
     * @return void
     */
    public function unregister(string $observableClass, ObserverInterface|string $observer, ?string $event = null): void;

    /**
     * Get all observers for a specific observable class and event.
     *
     * @param string $observableClass The class name of the observable
     * @param string|null $event Event name (null = all events)
     * @return array<ObserverInterface> Sorted by priority (highest first)
     */
    public function getObservers(string $observableClass, ?string $event = null): array;

    /**
     * Check if an observable class has observers.
     *
     * @param string $observableClass The class name of the observable
     * @param string|null $event Event name (null = any event)
     * @return bool
     */
    public function hasObservers(string $observableClass, ?string $event = null): bool;

    /**
     * Clear all observers for a specific observable class.
     *
     * @param string $observableClass The class name of the observable
     * @return void
     */
    public function clear(string $observableClass): void;

    /**
     * Clear all observers.
     *
     * @return void
     */
    public function clearAll(): void;
}

