<?php

declare(strict_types=1);

namespace Toporia\Framework\Log;

use Toporia\Framework\Log\Contracts\{ChannelInterface, LoggerInterface};

/**
 * Class Logger
 *
 * Logger - PSR-3 compliant logger implementation
 *
 * Professional logging system with channel-based architecture.
 * Supports multiple log destinations (file, daily, syslog, stderr, stack).
 *
 * Features:
 * - PSR-3 standard compliance
 * - Multiple log channels
 * - Context data support
 * - Placeholder interpolation
 *
 * Example:
 * ```php
 * $logger = new Logger(new DailyFileChannel('/var/log/app'));
 * $logger->error('User {user_id} failed login', ['user_id' => 123]);
 * $logger->info('Payment processed', ['amount' => 99.99]);
 * ```
 *
 * Performance: O(1) for single channel, O(N) for stack channel
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Log
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Logger implements LoggerInterface
{
    private ChannelInterface $channel;

    public function __construct(ChannelInterface $channel)
    {
        $this->channel = $channel;
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        // Validate log level
        if (!LogLevel::isValid($level)) {
            throw new \InvalidArgumentException("Invalid log level: {$level}");
        }

        // Interpolate placeholders in message
        $message = $this->interpolate($message, $context);

        // Write to channel
        $this->channel->write($level, $message, $context);
    }

    /**
     * Interpolate context values into message placeholders.
     *
     * PSR-3 standard: {placeholder} syntax
     *
     * Example:
     * - Message: "User {user_id} failed login attempt {attempt}"
     * - Context: ['user_id' => 123, 'attempt' => 3]
     * - Result:  "User 123 failed login attempt 3"
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        // Build replacement array with braces around keys
        $replace = [];
        foreach ($context as $key => $value) {
            // Only replace scalar values and objects with __toString()
            if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = $value;
            }
        }

        // Interpolate replacement values into message
        return strtr($message, $replace);
    }
}
