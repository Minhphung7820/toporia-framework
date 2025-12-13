<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

/**
 * Trait HasEvents
 *
 * Enhanced model events support with Modern ORM event lifecycle.
 * Provides hooks at every stage of model operations.
 *
 * Event Lifecycle:
 * - **retrieved**: After model is loaded from database
 * - **creating**: Before new model is inserted (can cancel)
 * - **created**: After new model is inserted
 * - **updating**: Before existing model is updated (can cancel)
 * - **updated**: After existing model is updated
 * - **saving**: Before model is saved (create or update, can cancel)
 * - **saved**: After model is saved (create or update)
 * - **deleting**: Before model is deleted (can cancel)
 * - **deleted**: After model is deleted
 * - **restoring**: Before soft-deleted model is restored (can cancel)
 * - **restored**: After soft-deleted model is restored
 * - **replicating**: Before model is replicated/cloned
 *
 * Event Cancellation:
 * - Events can return `false` to cancel the operation (for "ing" events)
 * - Observers and event listeners can prevent operations
 *
 * Performance:
 * - O(N) where N = number of registered event listeners
 * - Minimal overhead when no listeners registered
 * - Static event registry for efficiency
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\ORM\Concerns
 * @since       2025-01-22
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasEvents
{
    /**
     * Static event callbacks per model class.
     *
     * @var array<string, array<string, array<callable>>>
     */
    protected static array $eventCallbacks = [];

    /**
     * Track if events are being dispatched (prevent recursion).
     *
     * @var bool
     */
    protected static bool $dispatchingEvents = true;

    /**
     * Register a model event listener.
     *
     * Example:
     * ```php
     * User::creating(function($user) {
     *     $user->uuid = Str::uuid();
     * });
     *
     * User::updated(function($user) {
     *     Cache::forget('user:' . $user->id);
     * });
     * ```
     *
     * @param string $event Event name
     * @param callable $callback Callback to execute
     * @return void
     */
    public static function registerModelEvent(string $event, callable $callback): void
    {
        $class = static::class;

        if (!isset(static::$eventCallbacks[$class])) {
            static::$eventCallbacks[$class] = [];
        }

        if (!isset(static::$eventCallbacks[$class][$event])) {
            static::$eventCallbacks[$class][$event] = [];
        }

        static::$eventCallbacks[$class][$event][] = $callback;
    }

    /**
     * Register a "retrieved" event listener.
     *
     * @param callable $callback
     * @return void
     */
    public static function retrieved(callable $callback): void
    {
        static::registerModelEvent('retrieved', $callback);
    }

    /**
     * Register a "creating" event listener.
     *
     * @param callable $callback
     * @return void
     */
    public static function creating(callable $callback): void
    {
        static::registerModelEvent('creating', $callback);
    }

    /**
     * Register a "created" event listener.
     *
     * @param callable $callback
     * @return void
     */
    public static function created(callable $callback): void
    {
        static::registerModelEvent('created', $callback);
    }

    /**
     * Register a "updating" event listener.
     *
     * @param callable $callback
     * @return void
     */
    public static function updating(callable $callback): void
    {
        static::registerModelEvent('updating', $callback);
    }

    /**
     * Register a "updated" event listener.
     *
     * @param callable $callback
     * @return void
     */
    public static function updated(callable $callback): void
    {
        static::registerModelEvent('updated', $callback);
    }

    /**
     * Register a "saving" event listener.
     *
     * Fires before both create and update operations.
     *
     * @param callable $callback
     * @return void
     */
    public static function saving(callable $callback): void
    {
        static::registerModelEvent('saving', $callback);
    }

    /**
     * Register a "saved" event listener.
     *
     * Fires after both create and update operations.
     *
     * @param callable $callback
     * @return void
     */
    public static function saved(callable $callback): void
    {
        static::registerModelEvent('saved', $callback);
    }

    /**
     * Register a "deleting" event listener.
     *
     * @param callable $callback
     * @return void
     */
    public static function deleting(callable $callback): void
    {
        static::registerModelEvent('deleting', $callback);
    }

    /**
     * Register a "deleted" event listener.
     *
     * @param callable $callback
     * @return void
     */
    public static function deleted(callable $callback): void
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * Register a "restoring" event listener.
     *
     * Fires when soft-deleted model is being restored.
     *
     * @param callable $callback
     * @return void
     */
    public static function restoring(callable $callback): void
    {
        static::registerModelEvent('restoring', $callback);
    }

    /**
     * Register a "restored" event listener.
     *
     * Fires after soft-deleted model is restored.
     *
     * @param callable $callback
     * @return void
     */
    public static function restored(callable $callback): void
    {
        static::registerModelEvent('restored', $callback);
    }

    /**
     * Register a "replicating" event listener.
     *
     * Fires when model is being replicated/cloned.
     *
     * @param callable $callback
     * @return void
     */
    public static function replicating(callable $callback): void
    {
        static::registerModelEvent('replicating', $callback);
    }

    /**
     * Fire a given model event.
     *
     * Returns false if event was cancelled by a listener.
     *
     * @param string $event Event name
     * @return bool False if cancelled
     */
    protected function fireModelEventCallbacks(string $event): bool
    {
        if (!static::$dispatchingEvents) {
            return true;
        }

        $class = static::class;
        $callbacks = static::$eventCallbacks[$class][$event] ?? [];

        foreach ($callbacks as $callback) {
            $result = $callback($this);

            // If callback returns false, cancel the operation
            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove all event listeners for this model.
     *
     * Useful for testing.
     *
     * @return void
     */
    public static function flushEventListeners(): void
    {
        unset(static::$eventCallbacks[static::class]);
    }

    /**
     * Disable event dispatching temporarily.
     *
     * Example:
     * ```php
     * User::withoutEvents(function() {
     *     User::create(['name' => 'Test']); // No events fired
     * });
     * ```
     *
     * @param callable $callback
     * @return mixed
     */
    public static function withoutEvents(callable $callback): mixed
    {
        $previousValue = static::$dispatchingEvents;
        static::$dispatchingEvents = false;

        try {
            return $callback();
        } finally {
            static::$dispatchingEvents = $previousValue;
        }
    }

    /**
     * Get all event callbacks for this model.
     *
     * @return array<string, array<callable>>
     */
    public static function getEventCallbacks(): array
    {
        return static::$eventCallbacks[static::class] ?? [];
    }
}
