<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Exceptions;

/**
 * Class EventException
 *
 * Base exception class for all event-related errors with context support for debugging.
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
class EventException extends \RuntimeException
{
    /**
     * @var array<string, mixed> Additional context data
     */
    protected array $context = [];

    /**
     * Create exception with context.
     *
     * @param string $message Error message
     * @param array<string, mixed> $context Additional context
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = '',
        array $context = [],
        ?\Throwable $previous = null
    ) {
        $this->context = $context;
        parent::__construct($message, 0, $previous);
    }

    /**
     * Get context data.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Create exception for invalid event name.
     *
     * @param string $eventName Invalid event name
     * @return static
     */
    public static function invalidEventName(string $eventName): static
    {
        return new static(
            sprintf('Event name cannot be empty or invalid: "%s"', $eventName),
            ['event_name' => $eventName]
        );
    }

    /**
     * Create exception for invalid wildcard pattern.
     *
     * @param string $pattern Invalid pattern
     * @param string $reason Reason for invalidity
     * @return static
     */
    public static function invalidWildcardPattern(string $pattern, string $reason = ''): static
    {
        $message = sprintf('Invalid wildcard pattern: "%s"', $pattern);
        if ($reason !== '') {
            $message .= ". {$reason}";
        }

        return new static($message, ['pattern' => $pattern, 'reason' => $reason]);
    }
}
