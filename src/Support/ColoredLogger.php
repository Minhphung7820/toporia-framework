<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;

/**
 * Class ColoredLogger
 *
 * Colored Console Logger - Beautiful colored output for console commands.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * Features:
 * - ANSI color support (info, success, warning, error)
 * - Timestamp formatting with timezone support
 * - Performance optimized (O(1) operations)
 * - Clean Architecture (no dependencies)
 * - SOLID principles (Single Responsibility)
 *
 * Usage:
 * ```php
 * $logger = new ColoredLogger('Asia/Ho_Chi_Minh');
 * $logger->info('Processing job...');
 * $logger->success('Job completed!');
 * $logger->warning('Retrying job...');
 * $logger->error('Job failed!');
 * ```
 */
final class ColoredLogger
{
    // ANSI Color Codes
    private const COLOR_RESET = "\033[0m";
    private const COLOR_GREEN = "\033[32m";
    private const COLOR_YELLOW = "\033[33m";
    private const COLOR_RED = "\033[31m";
    private const COLOR_BLUE = "\033[34m";
    private const COLOR_CYAN = "\033[36m";
    private const COLOR_GRAY = "\033[90m";
    private const COLOR_WHITE = "\033[97m";

    // Log Level Icons
    private const ICON_INFO = 'ℹ';
    private const ICON_SUCCESS = '✓';
    private const ICON_WARNING = '⚠';
    private const ICON_ERROR = '✗';

    private string $timezone;
    private bool $useColors;

    /**
     * @param string $timezone Timezone (e.g., 'Asia/Ho_Chi_Minh', 'UTC')
     * @param bool $useColors Enable/disable colors (auto-detect if null)
     */
    public function __construct(
        string $timezone = 'UTC',
        ?bool $useColors = null
    ) {
        $this->timezone = $timezone;
        $this->useColors = $useColors ?? $this->supportsColors();
    }

    /**
     * Log info message (cyan)
     *
     * @param string $message
     * @return void
     */
    public function info(string $message): void
    {
        $this->log($message, self::COLOR_CYAN, self::ICON_INFO);
    }

    /**
     * Log success message (green)
     *
     * @param string $message
     * @return void
     */
    public function success(string $message): void
    {
        $this->log($message, self::COLOR_GREEN, self::ICON_SUCCESS);
    }

    /**
     * Log warning message (yellow)
     *
     * @param string $message
     * @return void
     */
    public function warning(string $message): void
    {
        $this->log($message, self::COLOR_YELLOW, self::ICON_WARNING);
    }

    /**
     * Log error message (red)
     *
     * @param string $message
     * @return void
     */
    public function error(string $message): void
    {
        $this->log($message, self::COLOR_RED, self::ICON_ERROR);
    }

    /**
     * Log debug message (gray) - only when not "No jobs available"
     *
     * @param string $message
     * @return void
     */
    public function debug(string $message): void
    {
        // Don't log "No jobs available" spam
        if (str_contains($message, 'No jobs available')) {
            return;
        }

        $this->log($message, self::COLOR_GRAY, 'DEBUG');
    }

    /**
     * Log comment/muted message (gray)
     *
     * @param string $message
     * @return void
     */
    public function comment(string $message): void
    {
        $this->log($message, self::COLOR_GRAY, '');
    }

    /**
     * Core logging method
     *
     * @param string $message
     * @param string $color ANSI color code
     * @param string $icon Log level icon
     * @return void
     */
    private function log(string $message, string $color, string $icon): void
    {
        $timestamp = $this->formatTimestamp();

        if ($this->useColors) {
            // Colored output: [timestamp] icon message
            echo self::COLOR_GRAY . '[' . $timestamp . ']' . self::COLOR_RESET . ' '
                . $color . ($icon ? $icon . ' ' : '') . $message . self::COLOR_RESET . PHP_EOL;
        } else {
            // Plain output without colors
            echo '[' . $timestamp . '] ' . ($icon ? $icon . ' ' : '') . $message . PHP_EOL;
        }

        flush();
    }

    /**
     * Format timestamp with timezone
     *
     * @return string Formatted timestamp (Y-m-d H:i:s)
     */
    private function formatTimestamp(): string
    {
        $datetime = new \DateTime('now', new \DateTimeZone($this->timezone));
        return $datetime->format('Y-m-d H:i:s');
    }

    /**
     * Check if terminal supports colors
     *
     * @return bool
     */
    private function supportsColors(): bool
    {
        // Disable colors on Windows CMD (unless ConEmu/cmder)
        if (DIRECTORY_SEPARATOR === '\\' && !getenv('ANSICON') && !getenv('ConEmuANSI')) {
            return false;
        }

        // Check if output is a TTY
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }

        // Check TERM environment variable
        $term = getenv('TERM');
        return $term && $term !== 'dumb';
    }

    /**
     * Log a separator line
     *
     * @param int $length Line length
     * @return void
     */
    public function separator(int $length = 80): void
    {
        echo self::COLOR_GRAY . str_repeat('─', $length) . self::COLOR_RESET . PHP_EOL;
        flush();
    }

    /**
     * Log a blank line
     *
     * @return void
     */
    public function newLine(): void
    {
        echo PHP_EOL;
        flush();
    }
}
