<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Consumer;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Realtime\Consumer\Contracts\ConsumerHandlerInterface;

/**
 * Class ConsumerHandlerRegistry
 *
 * Registry for consumer handlers. Manages handler registration,
 * discovery, and instantiation via dependency injection.
 *
 * Handlers can be registered:
 * 1. Via config file (config/consumers.php)
 * 2. Programmatically via register()
 * 3. Via auto-discovery from configured paths
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Consumer
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ConsumerHandlerRegistry
{
    /**
     * Registered handler classes.
     *
     * @var array<string, class-string<ConsumerHandlerInterface>>
     */
    private array $handlers = [];

    /**
     * Cached handler instances.
     *
     * @var array<string, ConsumerHandlerInterface>
     */
    private array $instances = [];

    /**
     * @param ContainerInterface $container DI container
     */
    public function __construct(
        private readonly ContainerInterface $container
    ) {}

    /**
     * Register a handler class.
     *
     * @param string $name Handler name (e.g., 'SendOrderCreated')
     * @param class-string<ConsumerHandlerInterface> $class Handler class
     * @return void
     */
    public function register(string $name, string $class): void
    {
        $this->handlers[$name] = $class;
    }

    /**
     * Register multiple handlers at once.
     *
     * @param array<string, class-string<ConsumerHandlerInterface>> $handlers
     * @return void
     */
    public function registerMany(array $handlers): void
    {
        foreach ($handlers as $name => $class) {
            $this->register($name, $class);
        }
    }

    /**
     * Check if a handler is registered.
     *
     * @param string $name Handler name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /**
     * Get a handler instance by name.
     *
     * Uses container for dependency injection.
     * Caches instances for reuse.
     *
     * @param string $name Handler name
     * @return ConsumerHandlerInterface
     * @throws \InvalidArgumentException If handler not found
     */
    public function get(string $name): ConsumerHandlerInterface
    {
        // Check cache first
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        // Get handler class
        if (!isset($this->handlers[$name])) {
            throw new \InvalidArgumentException(
                "Consumer handler [{$name}] not found. Available handlers: " . implode(', ', array_keys($this->handlers))
            );
        }

        $class = $this->handlers[$name];

        // Resolve via container for dependency injection
        $handler = $this->container->get($class);

        if (!$handler instanceof ConsumerHandlerInterface) {
            throw new \InvalidArgumentException(
                "Handler [{$name}] must implement ConsumerHandlerInterface"
            );
        }

        // Cache and return
        $this->instances[$name] = $handler;
        return $handler;
    }

    /**
     * Get all registered handler names.
     *
     * @return array<string>
     */
    public function getNames(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Get all registered handlers with their classes.
     *
     * @return array<string, class-string<ConsumerHandlerInterface>>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    /**
     * Get handler info for display.
     *
     * @return array<array{name: string, class: string, channels: array, driver: ?string}>
     */
    public function getHandlerInfo(): array
    {
        $info = [];

        foreach ($this->handlers as $name => $class) {
            try {
                $handler = $this->get($name);
                $info[] = [
                    'name' => $name,
                    'class' => $class,
                    'channels' => $handler->getChannels(),
                    'driver' => $handler->getDriver(),
                    'max_retries' => $handler->getMaxRetries(),
                    'consumer_group' => $handler->getConsumerGroup(),
                ];
            } catch (\Throwable $e) {
                $info[] = [
                    'name' => $name,
                    'class' => $class,
                    'channels' => [],
                    'driver' => null,
                    'max_retries' => 0,
                    'consumer_group' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $info;
    }

    /**
     * Auto-discover handlers from a directory.
     *
     * @param string $directory Directory path
     * @param string $namespace Base namespace for classes
     * @return int Number of handlers discovered
     */
    public function discover(string $directory, string $namespace): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $discovered = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($directory . '/', '', $file->getPathname());
            $className = $namespace . '\\' . str_replace(
                ['/', '.php'],
                ['\\', ''],
                $relativePath
            );

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (!$reflection->implementsInterface(ConsumerHandlerInterface::class)) {
                continue;
            }

            // Get handler name from class
            $shortName = $reflection->getShortName();
            if (str_ends_with($shortName, 'Handler')) {
                $shortName = substr($shortName, 0, -7);
            }

            $this->register($shortName, $className);
            $discovered++;
        }

        return $discovered;
    }

    /**
     * Clear all registered handlers and cached instances.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->handlers = [];
        $this->instances = [];
    }
}
