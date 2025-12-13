<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Concerns;

use Toporia\Framework\Events\Contracts\EventDispatcherInterface;
use Toporia\Framework\Database\ORM\Model;

/**
 * Trait HasEvents
 *
 * Provides event dispatching functionality for repositories.
 * Fires events before/after CRUD operations.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository\Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasEvents
{
    /**
     * @var EventDispatcherInterface|null Event dispatcher
     */
    protected ?EventDispatcherInterface $dispatcher = null;

    /**
     * @var bool Whether events are enabled
     */
    protected bool $eventsEnabled = true;

    /**
     * Set event dispatcher.
     *
     * @param EventDispatcherInterface $dispatcher
     * @return static
     */
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): static
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    /**
     * Get event dispatcher.
     *
     * @return EventDispatcherInterface|null
     */
    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->dispatcher;
    }

    /**
     * Enable event dispatching.
     *
     * @return static
     */
    public function enableEvents(): static
    {
        $this->eventsEnabled = true;
        return $this;
    }

    /**
     * Disable event dispatching.
     *
     * @return static
     */
    public function disableEvents(): static
    {
        $this->eventsEnabled = false;
        return $this;
    }

    /**
     * Execute callback without firing events.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withoutEvents(callable $callback): mixed
    {
        $previous = $this->eventsEnabled;
        $this->eventsEnabled = false;

        try {
            return $callback();
        } finally {
            $this->eventsEnabled = $previous;
        }
    }

    /**
     * Fire repository event.
     *
     * @param string $event Event name
     * @param mixed ...$payload Event payload
     * @return void
     */
    protected function fireEvent(string $event, mixed ...$payload): void
    {
        if (!$this->eventsEnabled || $this->dispatcher === null) {
            return;
        }

        $eventName = $this->getEventName($event);
        $this->dispatcher->dispatch($eventName, ...$payload);
    }

    /**
     * Fire event before entity creation.
     *
     * @param array<string, mixed> $attributes
     * @return void
     */
    protected function fireCreating(array $attributes): void
    {
        $this->fireEvent('creating', $attributes, $this);
    }

    /**
     * Fire event after entity creation.
     *
     * @param Model $entity
     * @return void
     */
    protected function fireCreated(Model $entity): void
    {
        $this->fireEvent('created', $entity, $this);
    }

    /**
     * Fire event before entity update.
     *
     * @param Model $entity
     * @param array<string, mixed> $attributes
     * @return void
     */
    protected function fireUpdating(Model $entity, array $attributes): void
    {
        $this->fireEvent('updating', $entity, $attributes, $this);
    }

    /**
     * Fire event after entity update.
     *
     * @param Model $entity
     * @return void
     */
    protected function fireUpdated(Model $entity): void
    {
        $this->fireEvent('updated', $entity, $this);
    }

    /**
     * Fire event before entity deletion.
     *
     * @param Model $entity
     * @return void
     */
    protected function fireDeleting(Model $entity): void
    {
        $this->fireEvent('deleting', $entity, $this);
    }

    /**
     * Fire event after entity deletion.
     *
     * @param Model $entity
     * @return void
     */
    protected function fireDeleted(Model $entity): void
    {
        $this->fireEvent('deleted', $entity, $this);
    }

    /**
     * Fire event after entity retrieval.
     *
     * @param Model $entity
     * @return void
     */
    protected function fireRetrieved(Model $entity): void
    {
        $this->fireEvent('retrieved', $entity, $this);
    }

    /**
     * Fire event before entity restore.
     *
     * @param Model $entity
     * @return void
     */
    protected function fireRestoring(Model $entity): void
    {
        $this->fireEvent('restoring', $entity, $this);
    }

    /**
     * Fire event after entity restore.
     *
     * @param Model $entity
     * @return void
     */
    protected function fireRestored(Model $entity): void
    {
        $this->fireEvent('restored', $entity, $this);
    }

    /**
     * Get full event name with repository prefix.
     *
     * @param string $event Short event name
     * @return string Full event name
     */
    protected function getEventName(string $event): string
    {
        $modelClass = $this->getModelClass();
        $shortClass = $this->getEventClassBasename($modelClass);
        return "repository.{$shortClass}.{$event}";
    }

    /**
     * Get the basename of a class name.
     *
     * @param string $class
     * @return string
     */
    protected function getEventClassBasename(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    /**
     * Register event listener.
     *
     * @param string $event Event name (creating, created, updating, etc.)
     * @param callable $callback
     * @return static
     */
    public function listen(string $event, callable $callback): static
    {
        if ($this->dispatcher !== null) {
            $eventName = $this->getEventName($event);
            $this->dispatcher->listen($eventName, $callback);
        }
        return $this;
    }
}
