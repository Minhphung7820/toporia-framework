<?php

declare(strict_types=1);

namespace Toporia\Framework\Log\Channels;

use Toporia\Framework\Log\Contracts\ChannelInterface;

use Toporia\Framework\Log\LogLevel;

/**
 * Class SyslogChannel
 *
 * Syslog Channel - System logger
 *
 * Writes logs to system syslog daemon.
 * Useful for production servers, centralized logging, and log aggregation.
 *
 * Logs appear in:
 * - Linux: /var/log/syslog or /var/log/messages
 * - macOS: /var/log/system.log
 *
 * Performance: O(1) write operation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Log\Channels
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SyslogChannel implements ChannelInterface
{
    private string $ident;
    private int $facility;

    /**
     * @param string $ident Application identifier
     * @param int $facility Syslog facility (LOG_USER, LOG_LOCAL0, etc.)
     */
    public function __construct(string $ident = 'php', int $facility = LOG_USER)
    {
        $this->ident = $ident;
        $this->facility = $facility;

        // Open syslog connection
        openlog($this->ident, LOG_PID | LOG_ODELAY, $this->facility);
    }

    public function write(string $level, string $message, array $context = []): void
    {
        $priority = $this->mapLevelToPriority($level);

        // Add context if present
        if (!empty($context)) {
            $message .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Write to syslog
        syslog($priority, $message);
    }

    /**
     * Map PSR-3 log level to syslog priority.
     *
     * @param string $level
     * @return int
     */
    private function mapLevelToPriority(string $level): int
    {
        return match ($level) {
            LogLevel::EMERGENCY => LOG_EMERG,
            LogLevel::ALERT => LOG_ALERT,
            LogLevel::CRITICAL => LOG_CRIT,
            LogLevel::ERROR => LOG_ERR,
            LogLevel::WARNING => LOG_WARNING,
            LogLevel::NOTICE => LOG_NOTICE,
            LogLevel::INFO => LOG_INFO,
            LogLevel::DEBUG => LOG_DEBUG,
            default => LOG_INFO,
        };
    }

    public function __destruct()
    {
        closelog();
    }
}
