<?php

declare(strict_types=1);

namespace Toporia\Framework\Log\Channels;

use Toporia\Framework\Log\Contracts\ChannelInterface;

/**
 * Class FileChannel
 *
 * File Channel - Single file logging
 *
 * Writes all logs to a single file.
 * Simple and straightforward for small applications.
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
final class FileChannel implements ChannelInterface
{
    private string $logFile;
    private string $dateFormat;

    public function __construct(string $logFile, string $dateFormat = 'Y-m-d H:i:s')
    {
        $this->logFile = $logFile;
        $this->dateFormat = $dateFormat;

        // Ensure directory exists
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function write(string $level, string $message, array $context = []): void
    {
        $timestamp = now()->format($this->dateFormat);
        $levelUpper = strtoupper($level);

        // Format: [2025-01-11 13:45:23] ERROR: Something went wrong {"user_id":123}
        $logEntry = sprintf(
            "[%s] %s: %s",
            $timestamp,
            $levelUpper,
            $message
        );

        // Add context if present
        if (!empty($context)) {
            $logEntry .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $logEntry .= PHP_EOL;

        // Atomic write to prevent race conditions
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
