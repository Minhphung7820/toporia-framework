<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Contracts;


/**
 * Interface SubscriberInterface
 *
 * Contract defining the interface for SubscriberInterface implementations
 * in the Event dispatching and listening layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface SubscriberInterface
{
    /**
     * Subscribe to events.
     *
     * Returns an array mapping event names to listeners.
     * Listener can be:
     * - callable (closure or array)
     * - string (method name on this subscriber)
     * - array [callable|string, priority]
     *
     * @param EventDispatcherInterface $dispatcher Event dispatcher instance
     * @return array<string, callable|string|array{0: callable|string, 1: int}> Event => listener mapping
     */
    public function subscribe(EventDispatcherInterface $dispatcher): array;
}

