<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;


/**
 * Trait HasObservers
 *
 * Trait providing reusable functionality for HasObservers in the Concerns
 * layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasObservers
{
    /**
     * @var array<string, array<object>> Cached observer instances per model class
     */
    protected static array $modelObservers = [];

    /**
     * @var array<string, bool> Track if observers are booted for each class
     */
    protected static array $observersBooted = [];

    /**
     * Register an observer with the model.
     *
     * @param object|string $observer Observer instance or class name
     * @return void
     */
    public static function observe(object|string $observer): void
    {
        $class = static::class;

        if (!isset(static::$modelObservers[$class])) {
            static::$modelObservers[$class] = [];
        }

        // Resolve observer if it's a class name
        if (is_string($observer)) {
            $observer = static::resolveObserverInstance($observer);
        }

        if ($observer !== null) {
            static::$modelObservers[$class][] = $observer;
        }
    }

    /**
     * Get all observers for the model.
     *
     * Note: This is aliased as getModelObservers in Model class to avoid conflict
     * with Observable trait's getObservers() method.
     *
     * @return array<object>
     */
    public static function getModelObservers(): array
    {
        return static::$modelObservers[static::class] ?? [];
    }

    /**
     * Remove all observers from the model.
     *
     * @return void
     */
    public static function flushObservers(): void
    {
        unset(static::$modelObservers[static::class]);
        unset(static::$observersBooted[static::class]);
    }

    /**
     * Fire a model event and call observers.
     *
     * @param string $event Event name (created, updated, deleted, etc.)
     * @return bool False if event should be cancelled
     */
    protected function fireModelEvent(string $event): bool
    {
        // Use modelObservers array directly to avoid method name conflicts with Observable trait
        $observers = static::$modelObservers[static::class] ?? [];

        foreach ($observers as $observer) {
            // Check if observer has the event method
            if (method_exists($observer, $event)) {
                $result = $observer->{$event}($this);

                // If observer returns false, cancel the event
                if ($result === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Resolve observer instance from class name.
     *
     * @param string $class Observer class name
     * @return object|null
     */
    protected static function resolveObserverInstance(string $class): ?object
    {
        // Try container first
        if (function_exists('app')) {
            try {
                return app($class);
            } catch (\Throwable $e) {
                // Fall through to direct instantiation
            }
        }

        // Direct instantiation
        if (class_exists($class)) {
            return new $class();
        }

        return null;
    }

    /**
     * Boot observers for the model.
     * Called automatically when model is first used.
     *
     * @return void
     */
    protected static function bootObservers(): void
    {
        $class = static::class;

        if (isset(static::$observersBooted[$class])) {
            return;
        }

        // Check for $observers property on model
        if (property_exists($class, 'observers')) {
            try {
                $reflection = new \ReflectionClass($class);

                // Check if property exists and is static
                if ($reflection->hasProperty('observers')) {
                    $property = $reflection->getProperty('observers');
                    $property->setAccessible(true);

                    // Get static property value (null for static properties when called without instance)
                    $observers = $property->isStatic()
                        ? $property->getValue()
                        : $property->getValue(new $class());

                    if (is_array($observers) && !empty($observers)) {
                        /** @var array<string> $observers */
                        foreach ($observers as $observer) {
                            static::observe($observer);
                        }
                    }
                }
            } catch (\ReflectionException $e) {
                // If reflection fails, skip observers registration
                // This can happen if the property doesn't exist or is not accessible
            }
        }

        static::$observersBooted[$class] = true;
    }
}
