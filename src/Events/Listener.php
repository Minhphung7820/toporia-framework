<?php

declare(strict_types=1);

namespace Toporia\Framework\Events;

use Toporia\Framework\Events\Contracts\{EventInterface, ListenerInterface};


/**
 * Abstract Class Listener
 *
 * Abstract base class for Listener implementations in the Event
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
abstract class Listener implements ListenerInterface
{
    /**
     * Handle the event.
     *
     * @param EventInterface $event The event instance
     * @return void
     */
    public function handle(EventInterface $event): void
    {
        // Override in subclasses
    }
}
