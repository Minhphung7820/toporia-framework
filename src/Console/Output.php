<?php

declare(strict_types=1);

namespace Toporia\Framework\Console;

use Toporia\Framework\Console\Contracts\OutputInterface;

/**
 * Class Output
 *
 * Handles formatted output to console with colors and styles.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Output implements OutputInterface
{
    private const COLOR_RESET = "\033[0m";
    private const COLOR_INFO = "\033[36m";      // Cyan
    private const COLOR_SUCCESS = "\033[32m";   // Green
    private const COLOR_WARNING = "\033[33m";   // Yellow
    private const COLOR_ERROR = "\033[31m";     // Red

    /**
     * ANSI color codes map for <fg=color> tags.
     */
    private const COLORS = [
        'black' => "\033[30m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'gray' => "\033[90m",
        'default' => "\033[39m",
    ];

    private bool $decorated;

    public function __construct(?bool $decorated = null)
    {
        // Auto-detect if terminal supports colors
        $this->decorated = $decorated ?? (
            DIRECTORY_SEPARATOR === '/' &&
            function_exists('posix_isatty') &&
            defined('STDOUT') &&
            @posix_isatty(STDOUT)
        );
    }

    public function write(string $message): void
    {
        echo $this->parseColorTags($message);
    }

    public function writeln(string $message): void
    {
        echo $this->parseColorTags($message) . PHP_EOL;
    }

    /**
     * Parse color tags like <fg=green>text</> and convert to ANSI codes.
     *
     * Supports: <fg=color>text</> or <fg=color>text</fg>
     *
     * @param string $message
     * @return string
     */
    private function parseColorTags(string $message): string
    {
        if (!$this->decorated) {
            // Strip tags if no decoration
            return preg_replace('/<\/?(?:fg=[a-z]+|\/?)>/', '', $message) ?? $message;
        }

        // Replace <fg=color>...</> with ANSI codes
        $pattern = '/<fg=([a-z]+)>(.*?)<\/?(?:fg)?>/s';

        return preg_replace_callback($pattern, function ($matches) {
            $color = $matches[1];
            $text = $matches[2];
            $ansiCode = self::COLORS[$color] ?? '';

            if ($ansiCode === '') {
                return $text; // Unknown color, return text as-is
            }

            return $ansiCode . $text . self::COLOR_RESET;
        }, $message) ?? $message;
    }

    public function info(string $message): void
    {
        $this->writeln($this->colorize("[INFO] {$message}", self::COLOR_INFO));
    }

    public function error(string $message): void
    {
        $formatted = $this->colorize("[ERROR] {$message}", self::COLOR_ERROR);
        fwrite(STDERR, $formatted . PHP_EOL);
    }

    public function success(string $message): void
    {
        $this->writeln($this->colorize("[SUCCESS] {$message}", self::COLOR_SUCCESS));
    }

    public function warning(string $message): void
    {
        $this->writeln($this->colorize("[WARNING] {$message}", self::COLOR_WARNING));
    }

    public function line(string $char = '-', int $length = 80): void
    {
        $this->writeln(str_repeat($char, $length));
    }

    public function newLine(int $count = 1): void
    {
        echo str_repeat(PHP_EOL, $count);
    }

    public function table(array $headers, array $rows): void
    {
        if (empty($headers) || empty($rows)) {
            return;
        }

        // Calculate column widths
        $widths = array_map('strlen', $headers);

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }

        // Create format string
        $format = '| ' . implode(' | ', array_map(fn($w) => "%-{$w}s", $widths)) . ' |';
        $separator = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';

        // Output table
        $this->writeln($separator);
        $this->writeln(sprintf($format, ...$headers));
        $this->writeln($separator);

        foreach ($rows as $row) {
            $this->writeln(sprintf($format, ...$row));
        }

        $this->writeln($separator);
    }

    /**
     * Colorize text if decoration is enabled
     *
     * @param string $text
     * @param string $color
     * @return string
     */
    private function colorize(string $text, string $color): string
    {
        if (!$this->decorated) {
            return $text;
        }

        return $color . $text . self::COLOR_RESET;
    }

    /**
     * Disable decoration (colors)
     *
     * @return void
     */
    public function disableDecoration(): void
    {
        $this->decorated = false;
    }

    /**
     * Enable decoration (colors)
     *
     * @return void
     */
    public function enableDecoration(): void
    {
        $this->decorated = true;
    }
}
