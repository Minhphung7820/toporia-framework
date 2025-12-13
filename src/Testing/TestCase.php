<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Toporia\Framework\Container\Container;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Testing\Concerns\InteractsWithContainer;
use Toporia\Framework\Testing\Concerns\InteractsWithDatabase;
use Toporia\Framework\Testing\Concerns\InteractsWithHttp;
use Toporia\Framework\Testing\Concerns\InteractsWithTime;
use Toporia\Framework\Testing\Concerns\InteractsWithEvents;
use Toporia\Framework\Testing\Concerns\InteractsWithQueue;
use Toporia\Framework\Testing\Concerns\InteractsWithCache;
use Toporia\Framework\Testing\Concerns\InteractsWithFiles;
use Toporia\Framework\Testing\Concerns\InteractsWithMail;
use Toporia\Framework\Testing\Concerns\InteractsWithBus;
use Toporia\Framework\Testing\Concerns\InteractsWithRealtime;
use Toporia\Framework\Testing\Concerns\PerformanceAssertions;


/**
 * Abstract Class TestCase
 *
 * Abstract base class for TestCase implementations in the Testing layer
 * providing common functionality and contracts.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Testing
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class TestCase extends PHPUnitTestCase
{
    use InteractsWithContainer {
        getFromContainer as getContainer;
    }
    use InteractsWithDatabase;
    use InteractsWithHttp {
        getRequest as get;
    }
    use InteractsWithTime;
    use InteractsWithEvents;
    use InteractsWithQueue;
    use InteractsWithCache;
    use InteractsWithFiles;
    use InteractsWithMail;
    use InteractsWithBus;
    use InteractsWithRealtime;
    use PerformanceAssertions;

    /**
     * Application container instance.
     */
    protected ?ContainerInterface $container = null;

    /**
     * Indicates if migrations have been run.
     */
    protected bool $migrationsRun = false;

    /**
     * Setup before each test.
     *
     * Performance: Minimal setup, lazy initialization
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize container if not already set
        if ($this->container === null) {
            $this->container = new Container();
            $this->setUpContainer();
        }

        // Setup database if needed
        $this->setUpDatabase();

        // Setup other services
        $this->setUpServices();
    }

    /**
     * Cleanup after each test.
     *
     * Performance: Fast cleanup, only essential operations
     */
    protected function tearDown(): void
    {
        // Cleanup in reverse order
        $this->tearDownRealtime();
        $this->tearDownFiles();
        $this->tearDownMail();
        $this->tearDownBus();
        $this->tearDownCache();
        $this->tearDownQueue();
        $this->tearDownEvents();
        $this->tearDownTime();
        $this->tearDownDatabase();
        $this->tearDownServices();
        $this->tearDownContainer();

        parent::tearDown();
    }

    /**
     * Setup container bindings.
     * Override this method to customize container setup.
     */
    protected function setUpContainer(): void
    {
        // Override in child classes to setup container
    }

    /**
     * Setup database for testing.
     * Override this method to customize database setup.
     */
    protected function setUpDatabase(): void
    {
        // Override in child classes to setup database
    }

    /**
     * Setup other services.
     * Override this method to setup additional services.
     */
    protected function setUpServices(): void
    {
        // Override in child classes to setup services
    }

    /**
     * Cleanup database after test.
     */
    protected function tearDownDatabase(): void
    {
        // Implemented in InteractsWithDatabase trait
    }

    /**
     * Cleanup services after test.
     */
    protected function tearDownServices(): void
    {
        // Override in child classes if needed
    }

    /**
     * Cleanup container after test.
     */
    protected function tearDownContainer(): void
    {
        // Implemented in InteractsWithContainer trait
    }

    /**
     * Get the application container.
     *
     * Performance: O(1) - Direct property access
     */
    protected function getContainer(): ContainerInterface
    {
        if ($this->container === null) {
            $this->container = new Container();
            $this->setUpContainer();
        }

        return $this->container;
    }

    /**
     * Set the application container.
     */
    protected function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /**
     * Assert that two values are equal (with type checking).
     *
     * Performance: O(1) - Direct comparison
     */
    protected function assertEqualsStrict(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame($expected, $actual, $message);
    }

    /**
     * Assert that a value is an instance of a class.
     *
     * Performance: O(1) - instanceof check
     */
    protected function assertInstanceOfStrict(string $expected, mixed $actual, string $message = ''): void
    {
        $this->assertInstanceOf($expected, $actual, $message);
    }

    /**
     * Assert that an array has a specific key.
     *
     * Performance: O(1) - Array key check
     */
    protected function assertArrayHasKeyStrict(string|int $key, array $array, string $message = ''): void
    {
        $this->assertArrayHasKey($key, $array, $message);
    }

    /**
     * Assert that a value is null.
     *
     * Performance: O(1) - Null check
     */
    protected function assertNullStrict(mixed $value, string $message = ''): void
    {
        $this->assertNull($value, $message);
    }

    /**
     * Assert that a value is not null.
     *
     * Performance: O(1) - Not null check
     */
    protected function assertNotNullStrict(mixed $value, string $message = ''): void
    {
        $this->assertNotNull($value, $message);
    }
}
