<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Contracts;


/**
 * Interface EventInterface
 *
 * Contract defining the interface for EventInterface implementations in
 * the Event dispatching and listening layer of the Toporia Framework.
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
interface EventInterface
{
    /**
     * Get the event name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if event propagation has been stopped.
     *
     * @return bool
     */
    public function isPropagationStopped(): bool;

    /**
     * Stop event propagation to other listeners.
     *
     * @return void
     */
    public function stopPropagation(): void;
}
