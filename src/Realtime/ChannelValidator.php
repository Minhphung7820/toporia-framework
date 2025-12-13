<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime;

use Toporia\Framework\Realtime\Exceptions\ChannelException;

/**
 * Class ChannelValidator
 *
 * Validator for channel and event names.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ChannelValidator
{
    /**
     * Maximum channel name length.
     */
    private const MAX_CHANNEL_LENGTH = 200;

    /**
     * Maximum event name length.
     */
    private const MAX_EVENT_LENGTH = 100;

    /**
     * Allowed channel name pattern.
     * Allows: alphanumeric, dots, dashes, underscores, colons
     */
    private const CHANNEL_PATTERN = '/^[a-zA-Z0-9._\-:]+$/';

    /**
     * Allowed event name pattern.
     * Allows: alphanumeric, dots, dashes, underscores, colons
     */
    private const EVENT_PATTERN = '/^[a-zA-Z0-9._\-:]+$/';

    /**
     * Reserved channel prefixes.
     */
    private const RESERVED_PREFIXES = [
        'private-',
        'presence-',
        'cache-',
    ];

    /**
     * Validate channel name.
     *
     * @param string $channel Channel name
     * @throws ChannelException If channel name is invalid
     */
    public static function validateChannel(string $channel): void
    {
        if (empty($channel)) {
            throw ChannelException::invalidChannelName($channel, 'Channel name cannot be empty');
        }

        if (strlen($channel) > self::MAX_CHANNEL_LENGTH) {
            throw ChannelException::invalidChannelName(
                $channel,
                'Channel name exceeds maximum length of ' . self::MAX_CHANNEL_LENGTH
            );
        }

        if (!preg_match(self::CHANNEL_PATTERN, $channel)) {
            throw ChannelException::invalidChannelName(
                $channel,
                'Channel name contains invalid characters. Allowed: alphanumeric, dots, dashes, underscores, colons'
            );
        }

        // Check for path traversal attempts
        if (str_contains($channel, '..') || str_contains($channel, '//')) {
            throw ChannelException::invalidChannelName(
                $channel,
                'Channel name contains suspicious patterns'
            );
        }
    }

    /**
     * Validate event name.
     *
     * @param string $event Event name
     * @throws ChannelException If event name is invalid
     */
    public static function validateEvent(string $event): void
    {
        if (empty($event)) {
            throw ChannelException::invalidChannelName($event, 'Event name cannot be empty');
        }

        if (strlen($event) > self::MAX_EVENT_LENGTH) {
            throw ChannelException::invalidChannelName(
                $event,
                'Event name exceeds maximum length of ' . self::MAX_EVENT_LENGTH
            );
        }

        if (!preg_match(self::EVENT_PATTERN, $event)) {
            throw ChannelException::invalidChannelName(
                $event,
                'Event name contains invalid characters. Allowed: alphanumeric, dots, dashes, underscores, colons'
            );
        }
    }

    /**
     * Check if channel is private.
     *
     * @param string $channel Channel name
     * @return bool
     */
    public static function isPrivate(string $channel): bool
    {
        return str_starts_with($channel, 'private-');
    }

    /**
     * Check if channel is presence.
     *
     * @param string $channel Channel name
     * @return bool
     */
    public static function isPresence(string $channel): bool
    {
        return str_starts_with($channel, 'presence-');
    }

    /**
     * Check if channel requires authentication.
     *
     * @param string $channel Channel name
     * @return bool
     */
    public static function requiresAuth(string $channel): bool
    {
        return self::isPrivate($channel) || self::isPresence($channel);
    }

    /**
     * Get channel type.
     *
     * @param string $channel Channel name
     * @return string Channel type ('public', 'private', 'presence')
     */
    public static function getType(string $channel): string
    {
        if (self::isPresence($channel)) {
            return 'presence';
        }

        if (self::isPrivate($channel)) {
            return 'private';
        }

        return 'public';
    }

    /**
     * Sanitize channel name (remove invalid characters).
     *
     * @param string $channel Channel name
     * @return string Sanitized channel name
     */
    public static function sanitize(string $channel): string
    {
        // Remove any characters not in allowed pattern
        $sanitized = preg_replace('/[^a-zA-Z0-9._\-:]/', '', $channel);

        // Truncate to max length
        return substr($sanitized ?? '', 0, self::MAX_CHANNEL_LENGTH);
    }
}
