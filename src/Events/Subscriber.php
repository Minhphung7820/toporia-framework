<?php

declare(strict_types=1);

namespace Toporia\Framework\Events;

use Toporia\Framework\Events\Contracts\{EventDispatcherInterface, SubscriberInterface};


/**
 * Abstract Class Subscriber
 *
 * Abstract base class for Subscriber implementations in the Event
 * dispatching and listening layer providing common functionality and
 * contracts.
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
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
abstract class Subscriber implements SubscriberInterface
{
    /**
     * Subscribe to events.
     *
     * Override this method to define event subscriptions.
     *
     * @param EventDispatcherInterface $dispatcher Event dispatcher instance
     * @return array<string, callable|string|array{0: callable|string, 1: int}> Event => listener mapping
     */
    public function subscribe(EventDispatcherInterface $dispatcher): array
    {
        return [];
    }

    /**
     * Helper method to create listener mapping with priority.
     *
     * @param callable|string $listener Listener callable or method name
     * @param int $priority Listener priority
     * @return array{0: callable|string, 1: int}
     */
    protected function listener(callable|string $listener, int $priority = 0): array
    {
        return [$listener, $priority];
    }
}

