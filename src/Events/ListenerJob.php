<?php

declare(strict_types=1);

namespace Toporia\Framework\Events;

use Toporia\Framework\Queue\Contracts\JobInterface;
use Toporia\Framework\Events\Contracts\{EventInterface, ListenerInterface};
use Toporia\Framework\Events\Exceptions\ListenerException;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class ListenerJob
 *
 * Job for executing queued event listeners with efficient serialization
 * and lazy listener resolution.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ListenerJob implements JobInterface
{
    private string $id;
    private string $queue = 'default';
    private int $attempts = 0;
    private int $maxAttempts = 3;

    /**
     * @var ListenerInterface|callable|string
     */
    private $listener;

    /**
     * @var EventInterface
     */
    private EventInterface $event;

    /**
     * @param ListenerInterface|callable|string $listener Listener to execute
     * @param EventInterface $event Event instance
     * @throws ListenerException If listener is a Closure (not serializable)
     */
    public function __construct(
        ListenerInterface|callable|string $listener,
        EventInterface $event
    ) {
        // CRITICAL: Closures cannot be serialized for queue storage
        // Only class-based listeners or string class names can be queued
        if ($listener instanceof \Closure) {
            throw ListenerException::closureNotQueueable();
        }

        $this->id = uniqid('listener_job_', true);
        $this->listener = $listener;
        $this->event = $event;
    }

    /**
     * Execute the job.
     *
     * @param ContainerInterface $container Container for resolving listeners
     * @return void
     * @throws ListenerException If listener cannot be resolved or execution fails
     */
    public function handle(ContainerInterface $container): void
    {
        $listener = $this->resolveListener($container);

        if ($listener === null) {
            throw ListenerException::unresolvable(
                is_string($this->listener) ? $this->listener : get_debug_type($this->listener)
            );
        }

        try {
            if ($listener instanceof ListenerInterface) {
                $listener->handle($this->event);
            } elseif (is_callable($listener)) {
                $listener($this->event);
            }
        } catch (\Throwable $e) {
            // Wrap non-ListenerException errors with context
            if (!$e instanceof ListenerException) {
                throw ListenerException::executionFailed(
                    is_string($this->listener) ? $this->listener : get_debug_type($this->listener),
                    $this->event->getName(),
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * {@inheritdoc}
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * {@inheritdoc}
     */
    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    /**
     * {@inheritdoc}
     */
    public function failed(\Throwable $exception): void
    {
        // Log failure or handle as needed
        error_log("ListenerJob failed: " . $exception->getMessage());
    }

    /**
     * {@inheritdoc}
     */
    public function getTimeout(): int
    {
        return 60; // Default 60 seconds timeout for event listeners
    }

    /**
     * {@inheritdoc}
     */
    public function timeout(): void
    {
        error_log("ListenerJob timed out: " . $this->displayName());
    }

    /**
     * {@inheritdoc}
     */
    public function getBackoffDelay(): int
    {
        return 3; // Default 3 seconds backoff delay
    }

    /**
     * Resolve listener from container if needed.
     *
     * @param ContainerInterface $container
     * @return ListenerInterface|callable|null Null if listener cannot be resolved
     */
    private function resolveListener(ContainerInterface $container): ListenerInterface|callable|null
    {
        // String listener (class name) - resolve from container
        if (is_string($this->listener)) {
            if ($container->has($this->listener)) {
                return $container->get($this->listener);
            }

            if (class_exists($this->listener)) {
                return $container->get($this->listener);
            }

            // String listener that cannot be resolved
            return null;
        }

        // Already resolved listener
        if ($this->listener instanceof ListenerInterface || is_callable($this->listener)) {
            return $this->listener;
        }

        return null;
    }

    /**
     * Get job display name.
     *
     * @return string
     */
    public function displayName(): string
    {
        $listenerName = is_string($this->listener)
            ? $this->listener
            : get_debug_type($this->listener);

        return "Event Listener: {$listenerName}";
    }

    /**
     * Get job unique ID.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return md5(serialize([$this->listener, $this->event->getName()]));
    }
}
