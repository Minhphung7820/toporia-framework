<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Exceptions;

/**
 * Class QueueNotAvailableException
 *
 * Exception thrown when trying to queue a listener but queue service is not available.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events\Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class QueueNotAvailableException extends EventException
{
    /**
     * Create exception for missing queue service.
     *
     * @param string|null $listenerName Listener that requires queue
     * @return static
     */
    public static function forListener(?string $listenerName = null): static
    {
        $message = 'Queue service is required for queued listeners. Please register QueueServiceProvider.';

        if ($listenerName !== null) {
            $message = sprintf('Cannot queue listener "%s". %s', $listenerName, $message);
        }

        return new static($message, ['listener' => $listenerName]);
    }
}
