<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue;

use Toporia\Framework\Queue\Contracts\{JobInterface, QueueInterface, QueueManagerInterface};
use Toporia\Framework\Queue\Events\JobQueued;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Events\Contracts\EventDispatcherInterface;

/**
 * Class QueueManager
 *
 * Manages multiple queue drivers and provides a unified interface.
 * Factory for creating queue driver instances.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Queue
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class QueueManager implements QueueManagerInterface
{
    private array $drivers = [];
    private ?string $defaultDriver = null;
    private array $config;
    private ?ContainerInterface $container = null;

    public function __construct(array $config = [], ?ContainerInterface $container = null)
    {
        $this->config = $config;
        $this->container = $container;
        $this->defaultDriver = $config['default'] ?? 'sync';
    }

    /**
     * Get a queue driver instance
     *
     * @param string|null $driver
     * @return QueueInterface
     */
    public function driver(?string $driver = null): QueueInterface
    {
        $driver = $driver ?? $this->defaultDriver;

        if (!isset($this->drivers[$driver])) {
            $this->drivers[$driver] = $this->createDriver($driver);
        }

        return $this->drivers[$driver];
    }

    /**
     * Create a queue driver instance
     *
     * @param string $driver
     * @return QueueInterface
     */
    private function createDriver(string $driver): QueueInterface
    {
        $config = $this->config['connections'][$driver] ?? [];

        return match ($config['driver'] ?? $driver) {
            'sync' => $this->createSyncQueue(),
            'database' => $this->createDatabaseQueue($config),
            'redis' => $this->createRedisQueue($config),
            'rabbitmq' => $this->createRabbitMQQueue($config),
            default => throw new \InvalidArgumentException("Unsupported queue driver: {$driver}"),
        };
    }

    /**
     * Create Redis queue instance with container injection.
     *
     * @param array $config
     * @return RedisQueue
     */
    private function createRedisQueue(array $config): RedisQueue
    {
        // Inject container for dependency injection support
        return new RedisQueue($config, $this->container);
    }

    /**
     * Create sync queue instance with container injection.
     *
     * @return SyncQueue
     */
    private function createSyncQueue(): SyncQueue
    {
        if (!$this->container) {
            throw new \InvalidArgumentException('Container is required for SyncQueue dependency injection');
        }

        return new SyncQueue($this->container);
    }

    /**
     * Create database queue instance with container injection.
     *
     * @param array $config
     * @return DatabaseQueue
     */
    private function createDatabaseQueue(array $config): DatabaseQueue
    {
        // Get database connection from container lazily
        if (isset($config['connection'])) {
            $connection = $config['connection'];
        } elseif ($this->container && $this->container->has('db')) {
            $connection = $this->container->get('db');
        } else {
            throw new \InvalidArgumentException('Database queue requires connection');
        }

        // DatabaseQueue doesn't need container (Worker handles job execution with DI)
        return new DatabaseQueue($connection);
    }

    /**
     * Create RabbitMQ queue instance with container injection.
     *
     * @param array $config
     * @return RabbitMQQueue
     */
    private function createRabbitMQQueue(array $config): RabbitMQQueue
    {
        // Inject container for dependency injection support
        return new RabbitMQQueue($config, $this->container);
    }

    /**
     * Push a job onto the default queue
     *
     * @param JobInterface $job
     * @param string $queue
     * @return string
     */
    public function push(JobInterface $job, string $queue = 'default'): string
    {
        $jobId = $this->driver()->push($job, $queue);

        // Dispatch JobQueued event if dispatcher is available
        $this->dispatchJobQueuedEvent($job, $queue, 0);

        return $jobId;
    }

    /**
     * Push a job with a delay
     *
     * @param JobInterface $job
     * @param int $delay
     * @param string $queue
     * @return string
     */
    public function later(JobInterface $job, int $delay, string $queue = 'default'): string
    {
        $jobId = $this->driver()->later($job, $delay, $queue);

        // Dispatch JobQueued event if dispatcher is available
        $this->dispatchJobQueuedEvent($job, $queue, $delay);

        return $jobId;
    }

    /**
     * Dispatch JobQueued event if event dispatcher is available.
     *
     * @param JobInterface $job
     * @param string $queue
     * @param int $delay
     * @return void
     */
    private function dispatchJobQueuedEvent(JobInterface $job, string $queue, int $delay): void
    {
        if ($this->container && $this->container->has(EventDispatcherInterface::class)) {
            try {
                $dispatcher = $this->container->get(EventDispatcherInterface::class);
                $dispatcher->dispatch(new JobQueued($job, $queue, $delay));
            } catch (\Throwable $e) {
                // Silent fail - event dispatch should not break job queuing
            }
        }
    }

    /**
     * Get all configured connection names
     *
     * @return array
     */
    public function getConnections(): array
    {
        return array_keys($this->config['connections'] ?? []);
    }

    /**
     * Get default driver name
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->defaultDriver ?? 'sync';
    }

    /**
     * Pop a job from the queue
     *
     * @param string $queue
     * @return JobInterface|null
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        return $this->driver()->pop($queue);
    }

    /**
     * Mark a job as failed and store in failed jobs table.
     *
     * @param JobInterface $job
     * @param \Throwable $exception
     * @return void
     */
    public function failed(JobInterface $job, \Throwable $exception): void
    {
        $driver = $this->driver();

        // Store failed job if driver supports it
        if (method_exists($driver, 'storeFailed')) {
            /** @var DatabaseQueue|RedisQueue $driver */
            $driver->storeFailed($job, $exception);
        }

        // Call job's failed() method
        $job->failed($exception);
    }

    /**
     * Get the size of the queue
     *
     * @param string $queue
     * @return int
     */
    public function size(string $queue = 'default'): int
    {
        return $this->driver()->size($queue);
    }

    /**
     * Clear all jobs from the queue
     *
     * @param string $queue
     * @return void
     */
    public function clear(string $queue = 'default'): void
    {
        $this->driver()->clear($queue);
    }
}
