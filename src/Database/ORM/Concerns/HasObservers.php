<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;


/**
 * Trait HasObservers
 *
 * Flexible model observers support with multiple registration methods.
 *
 * Registration Methods:
 * 1. Model property: protected static array $observers = [ObserverClass::class];
 * 2. Config file: config/observers.php with Model::class => [ObserverClass::class]
 * 3. Runtime: Model::observe(ObserverClass::class) or Model::observe(new Observer())
 *
 * Observer Methods:
 * - creating, created, updating, updated, saving, saved, deleting, deleted
 * - restoring, restored, replicating, retrieved
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
     * Supports multiple input types:
     * - Class name string: Model::observe(MyObserver::class)
     * - Object instance: Model::observe(new MyObserver())
     * - Array of observers: Model::observe([Observer1::class, Observer2::class])
     *
     * @param object|string|array<object|string> $observer Observer(s) to register
     * @return void
     */
    public static function observe(object|string|array $observer): void
    {
        // Handle array of observers
        if (is_array($observer)) {
            foreach ($observer as $obs) {
                static::observe($obs);
            }
            return;
        }

        $class = static::class;

        if (!isset(static::$modelObservers[$class])) {
            static::$modelObservers[$class] = [];
        }

        // Resolve observer if it's a class name
        if (is_string($observer)) {
            $observer = static::resolveObserverInstance($observer);
        }

        // Avoid duplicate observers
        if ($observer !== null) {
            $observerClass = get_class($observer);
            foreach (static::$modelObservers[$class] as $existing) {
                if (get_class($existing) === $observerClass) {
                    return; // Already registered
                }
            }
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
     * Loads observers from multiple sources (in order):
     * 1. Model's static $observers property
     * 2. config/observers.php configuration file
     *
     * @return void
     */
    protected static function bootObservers(): void
    {
        $class = static::class;

        if (isset(static::$observersBooted[$class])) {
            return;
        }

        // Mark as booted early to prevent recursion
        static::$observersBooted[$class] = true;

        // Method 1: Load from $observers property on model
        static::bootObserversFromProperty($class);

        // Method 2: Load from config/observers.php
        static::bootObserversFromConfig($class);
    }

    /**
     * Boot observers from model's $observers property.
     *
     * @param string $class Model class name
     * @return void
     */
    protected static function bootObserversFromProperty(string $class): void
    {
        if (!property_exists($class, 'observers')) {
            return;
        }

        try {
            $reflection = new \ReflectionClass($class);

            if (!$reflection->hasProperty('observers')) {
                return;
            }

            $property = $reflection->getProperty('observers');
            $property->setAccessible(true);

            $observers = $property->isStatic()
                ? $property->getValue()
                : $property->getValue(new $class());

            if (is_array($observers) && !empty($observers)) {
                foreach ($observers as $observer) {
                    static::observe($observer);
                }
            }
        } catch (\ReflectionException) {
            // Skip if reflection fails
        }
    }

    /**
     * Boot observers from config/observers.php.
     *
     * Config format:
     * ```php
     * return [
     *     Model::class => [Observer::class],
     *     Model::class => Observer::class, // Single observer shorthand
     *     Model::class => [
     *         Observer1::class,
     *         ['class' => Observer2::class, 'event' => 'created'],
     *     ],
     * ];
     * ```
     *
     * @param string $class Model class name
     * @return void
     */
    protected static function bootObserversFromConfig(string $class): void
    {
        if (!function_exists('config')) {
            return;
        }

        $allObservers = config('observers', []);

        if (!isset($allObservers[$class])) {
            return;
        }

        $observerConfig = $allObservers[$class];

        // Handle single observer shorthand
        if (is_string($observerConfig)) {
            static::observe($observerConfig);
            return;
        }

        // Handle array of observers
        if (is_array($observerConfig)) {
            foreach ($observerConfig as $observer) {
                if (is_string($observer)) {
                    static::observe($observer);
                } elseif (is_array($observer) && isset($observer['class'])) {
                    // Advanced config with options (future: event filtering, priority)
                    static::observe($observer['class']);
                }
            }
        }
    }

    /**
     * Check if observers are booted for this model.
     *
     * @return bool
     */
    public static function observersBooted(): bool
    {
        return static::$observersBooted[static::class] ?? false;
    }

    /**
     * Force re-boot observers (useful for testing).
     *
     * @return void
     */
    public static function rebootObservers(): void
    {
        unset(static::$observersBooted[static::class]);
        unset(static::$modelObservers[static::class]);
        static::bootObservers();
    }
}
