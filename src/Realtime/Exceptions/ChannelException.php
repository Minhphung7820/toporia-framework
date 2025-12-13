<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Exceptions;

/**
 * Class ChannelException
 *
 * Exception for channel-related errors.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class ChannelException extends RealtimeException
{
    /**
     * Create exception for invalid channel name.
     *
     * @param string $channel Channel name
     * @param string $reason Validation failure reason
     * @return static
     */
    public static function invalidChannelName(string $channel, string $reason): static
    {
        return new static(
            "Invalid channel name '{$channel}': {$reason}",
            ['channel' => $channel, 'reason' => $reason]
        );
    }

    /**
     * Create exception for authorization failure.
     *
     * @param string $channel Channel name
     * @param string|null $userId User ID if available
     * @return static
     */
    public static function unauthorized(string $channel, ?string $userId = null): static
    {
        $context = ['channel' => $channel];
        if ($userId !== null) {
            $context['user_id'] = $userId;
        }

        return new static(
            "Unauthorized access to channel '{$channel}'",
            $context
        );
    }

    /**
     * Create exception for channel not found.
     *
     * @param string $channel Channel name
     * @return static
     */
    public static function notFound(string $channel): static
    {
        return new static(
            "Channel '{$channel}' not found",
            ['channel' => $channel]
        );
    }
}
