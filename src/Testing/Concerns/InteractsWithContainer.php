<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Concerns;

use Toporia\Framework\Container\Contracts\ContainerInterface;

// Stub Mockery class for IDE support when Mockery is not installed
if (!class_exists('\Mockery')) {
    // Define in global namespace
    if (!class_exists('Mockery', false)) {
        class_alias('Toporia\Framework\Testing\Concerns\MockeryStub', 'Mockery');
    }
}

// Stub class in current namespace

/**
 * Class MockeryStub
 *
 * Core class for the Concerns layer providing essential functionality for
 * the Toporia Framework.
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
class MockeryStub
{
    /**
     * @param string|object $class
     * @return object
     */
    public static function mock($class)
    {
        return new class {
            public function __call($method, $args)
            {
                return null;
            }
        };
    }

    /**
     * @param string|object $class
     * @return object
     */
    public static function spy($class)
    {
        return new class {
            public function __call($method, $args)
            {
                return null;
            }
        };
    }
}

/**
 * Container Testing Trait
 *
 * Provides utilities for testing with dependency injection container.
 *
 * Performance:
 * - O(1) service resolution (singleton cache)
 * - Lazy service binding
 * - Efficient mock injection
 */
trait InteractsWithContainer
{
    /**
     * Bind a service in the container.
     *
     * Performance: O(1) - Direct binding
     */
    protected function bind(string $abstract, callable|string|null $concrete, bool $shared = false): void
    {
        $container = $this->getContainer();
        $container->bind($abstract, $concrete, $shared);
    }

    /**
     * Bind a singleton in the container.
     *
     * Performance: O(1) - Direct binding
     */
    protected function singleton(string $abstract, callable|string|null $concrete): void
    {
        $container = $this->getContainer();
        $container->singleton($abstract, $concrete);
    }

    /**
     * Bind an instance in the container.
     *
     * Performance: O(1) - Direct binding
     */
    protected function instance(string $abstract, mixed $instance): void
    {
        $container = $this->getContainer();
        $container->instance($abstract, $instance);
    }

    /**
     * Resolve a service from the container.
     *
     * Performance: O(1) for singletons, O(N) for new instances where N = dependency depth
     */
    protected function make(string $abstract, array $parameters = []): mixed
    {
        $container = $this->getContainer();
        return $container->make($abstract, $parameters);
    }

    /**
     * Get a service from the container.
     *
     * Performance: O(1) - Direct access
     */
    protected function getFromContainer(string $abstract): mixed
    {
        $container = $this->getContainer();
        return $container->get($abstract);
    }

    /**
     * Alias for getFromContainer (backward compatibility).
     */
    protected function getContainerService(string $abstract): mixed
    {
        return $this->getFromContainer($abstract);
    }

    /**
     * Check if a service is bound in the container.
     *
     * Performance: O(1) - Direct check
     */
    protected function has(string $abstract): bool
    {
        $container = $this->getContainer();
        return $container->has($abstract);
    }

    /**
     * Mock a service in the container.
     *
     * Performance: O(1) - Direct binding
     *
     * @param string $abstract Service identifier
     * @param \Closure|null $mock Optional mock closure
     * @return mixed Mock instance (Mockery\MockInterface when Mockery is available)
     * @psalm-suppress UndefinedClass
     */
    protected function mock(string $abstract, \Closure $mock = null): mixed
    {
        // Use Mockery if available, otherwise use fallback
        if (class_exists('\Mockery') && $mock === null) {
            // Real Mockery is available
            /** @var object $mockInstance */
            $mockInstance = \Mockery::mock($abstract);
            $this->instance($abstract, $mockInstance);
            return $mockInstance;
        }

        // Use provided mock closure or create fallback
        $fallbackMock = $mock ? $mock() : new class {
            public function __call($method, $args)
            {
                return null;
            }
        };
        $this->instance($abstract, $fallbackMock);
        return $fallbackMock;
    }

    /**
     * Spy on a service (partial mock).
     *
     * Performance: O(1) - Direct binding
     *
     * @param string $abstract Service identifier
     * @param \Closure|null $spy Optional spy closure
     * @return mixed Spy instance (Mockery\MockInterface when Mockery is available)
     * @psalm-suppress UndefinedClass
     */
    protected function spy(string $abstract, \Closure $spy = null): mixed
    {
        // Use Mockery if available, otherwise use fallback
        if (class_exists('\Mockery') && $spy === null) {
            // Real Mockery is available
            /** @var object $spyInstance */
            $spyInstance = \Mockery::spy($abstract);
            $this->instance($abstract, $spyInstance);
            return $spyInstance;
        }

        // Use provided spy closure or create fallback
        $fallbackSpy = $spy ? $spy() : new class {
            public function __call($method, $args)
            {
                return null;
            }
        };
        $this->instance($abstract, $fallbackSpy);
        return $fallbackSpy;
    }

    /**
     * Cleanup container after test.
     */
    protected function tearDownContainer(): void
    {
        // Container cleanup if needed
        // Override in child classes if specific cleanup is required
    }
}
