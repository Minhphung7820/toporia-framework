<?php

declare(strict_types=1);

namespace Toporia\Framework\Observer\Contracts;


/**
 * Interface ObservableInterface
 *
 * Contract defining the interface for ObservableInterface implementations
 * in the Observer layer of the Toporia Framework.
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
interface ObservableInterface
{
    /**
     * Attach an observer to this observable.
     *
     * @param ObserverInterface $observer The observer to attach
     * @param string|null $event Specific event to observe (null = all events)
     * @return void
     */
    public function attach(ObserverInterface $observer, ?string $event = null): void;

    /**
     * Detach an observer from this observable.
     *
     * @param ObserverInterface $observer The observer to detach
     * @param string|null $event Specific event to stop observing (null = all events)
     * @return void
     */
    public function detach(ObserverInterface $observer, ?string $event = null): void;

    /**
     * Notify all observers about a state change.
     *
     * @param string $event The event that occurred
     * @param array<string, mixed> $data Additional data about the change
     * @return void
     */
    public function notify(string $event, array $data = []): void;

    /**
     * Get all observers for a specific event.
     *
     * @param string|null $event Event name (null = all events)
     * @return array<ObserverInterface>
     */
    public function getObservers(?string $event = null): array;

    /**
     * Check if this observable has any observers.
     *
     * @param string|null $event Event name (null = any event)
     * @return bool
     */
    public function hasObservers(?string $event = null): bool;
}

