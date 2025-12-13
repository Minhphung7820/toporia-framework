<?php

declare(strict_types=1);

namespace Toporia\Framework\Log\Channels;

use Toporia\Framework\Log\Contracts\ChannelInterface;

/**
 * Class DailyFileChannel
 *
 * Daily File Channel - Rotating daily log files
 *
 * Creates a new log file each day with format: YYYY-MM-DD.log
 * Automatically rotates logs by date for easy management.
 *
 * Example:
 * - 2025-01-11.log
 * - 2025-01-12.log
 * - 2025-01-13.log
 *
 * Features:
 * - Automatic file rotation by date
 * - Optional log retention (auto-delete old logs)
 * - Thread-safe with file locking
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
final class DailyFileChannel implements ChannelInterface
{
    private string $logPath;
    private string $dateFormat;
    private ?int $daysToKeep;

    /**
     * @param string $logPath Directory path for log files
     * @param string $dateFormat Timestamp format for log entries
     * @param int|null $daysToKeep Number of days to retain logs (null = keep all)
     */
    public function __construct(
        string $logPath,
        string $dateFormat = 'Y-m-d H:i:s',
        ?int $daysToKeep = null
    ) {
        $this->logPath = rtrim($logPath, '/');
        $this->dateFormat = $dateFormat;
        $this->daysToKeep = $daysToKeep;

        // Ensure directory exists
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }

    public function write(string $level, string $message, array $context = []): void
    {
        $logFile = $this->getLogFile();
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
        // Ensure file exists and has secure permissions
        if (!file_exists($logFile)) {
            // SECURITY: Create file with restrictive permissions (owner read/write only)
            // Use @ to suppress errors if directory doesn't exist or permission denied
            @touch($logFile);
            // 0640: Owner read/write, group read, others no access
            // This protects log files from being read by unauthorized users
            @chmod($logFile, 0640);
        }

        // Use @ to suppress permission errors - log to stderr if file write fails
        $result = @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            // If file write fails, try to write to stderr as fallback
            error_log($logEntry);
        }

        // Cleanup old logs if retention policy is set
        if ($this->daysToKeep !== null) {
            $this->cleanupOldLogs();
        }
    }

    /**
     * Get current log file path.
     *
     * Format: /path/to/logs/2025-01-11.log
     *
     * @return string
     */
    private function getLogFile(): string
    {
        $date = now()->format('Y-m-d');
        return $this->logPath . '/' . $date . '.log';
    }

    /**
     * Cleanup logs older than retention period.
     *
     * Called automatically after each write operation.
     * Uses glob() for O(N) file discovery.
     *
     * @return void
     */
    private function cleanupOldLogs(): void
    {
        $cutoffTime = now()->subDays($this->daysToKeep)->getTimestamp();
        $pattern = $this->logPath . '/*.log';

        foreach (glob($pattern) as $file) {
            // Extract date from filename (YYYY-MM-DD.log)
            $filename = basename($file);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.log$/', $filename, $matches)) {
                $fileDate = $matches[1];
                $fileTime = \Toporia\Framework\DateTime\Chronos::parse($fileDate)->getTimestamp();

                if ($fileTime !== false && $fileTime < $cutoffTime) {
                    @unlink($file);
                }
            }
        }
    }
}
