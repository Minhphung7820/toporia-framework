<?php

declare(strict_types=1);

namespace Toporia\Framework\Console;

use Throwable;

/**
 * Class ExceptionRenderer
 *
 * Renders exceptions beautifully in the console with colored output,
 * stack trace with file highlighting, code snippets around error,
 * and clean, readable error formatting.
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
final class ExceptionRenderer
{
    private const COLOR_RESET = "\033[0m";
    private const COLOR_RED = "\033[31m";
    private const COLOR_GREEN = "\033[32m";
    private const COLOR_YELLOW = "\033[33m";
    private const COLOR_BLUE = "\033[34m";
    private const COLOR_MAGENTA = "\033[35m";
    private const COLOR_CYAN = "\033[36m";
    private const COLOR_WHITE = "\033[37m";
    private const COLOR_GRAY = "\033[90m";
    private const COLOR_BG_RED = "\033[41m";
    private const COLOR_BOLD = "\033[1m";
    private const COLOR_DIM = "\033[2m";

    private bool $decorated;
    private int $terminalWidth = 120;

    public function __construct(?bool $decorated = null)
    {
        $this->decorated = $decorated ?? $this->supportsColors();
        $this->terminalWidth = $this->getTerminalWidth();
    }

    /**
     * Render an exception to the console.
     */
    public function render(Throwable $e): void
    {
        $this->renderException($e);

        // Render previous exceptions
        $previous = $e->getPrevious();
        while ($previous) {
            fwrite(STDERR, PHP_EOL);
            $this->renderPreviousException($previous);
            $previous = $previous->getPrevious();
        }
    }

    /**
     * Render the main exception.
     */
    private function renderException(Throwable $e): void
    {
        $class = get_class($e);
        $message = $e->getMessage();
        $file = $e->getFile();
        $line = $e->getLine();

        // Error header box
        $this->renderErrorBox($class, $message);

        // Location
        fwrite(STDERR, PHP_EOL);
        $this->writeLine("  " . $this->colorize("at ", self::COLOR_GRAY) . $this->formatFilePath($file) . $this->colorize(":" . $line, self::COLOR_YELLOW));

        // Code snippet
        $this->renderCodeSnippet($file, $line);

        // Stack trace
        $this->renderStackTrace($e->getTrace());
    }

    /**
     * Render a previous (chained) exception.
     */
    private function renderPreviousException(Throwable $e): void
    {
        $class = get_class($e);
        $message = $e->getMessage();

        $this->writeLine($this->colorize("  Caused by: ", self::COLOR_GRAY) . $this->colorize($class, self::COLOR_YELLOW));
        $this->writeLine($this->colorize("  ", self::COLOR_GRAY) . $message);
    }

    /**
     * Render the error box header.
     */
    private function renderErrorBox(string $class, string $message): void
    {
        $width = min($this->terminalWidth - 4, 100);
        $border = str_repeat('─', $width);

        fwrite(STDERR, PHP_EOL);

        // Top border
        $this->writeLine($this->colorize("  ╭{$border}╮", self::COLOR_RED));

        // Exception class
        $classLine = "  " . $class;
        $paddedClass = str_pad($classLine, $width);
        $this->writeLine($this->colorize("  │", self::COLOR_RED) . $this->colorize($paddedClass, self::COLOR_RED . self::COLOR_BOLD) . $this->colorize("│", self::COLOR_RED));

        // Separator
        $this->writeLine($this->colorize("  │" . str_repeat(' ', $width) . "│", self::COLOR_RED));

        // Message (word wrap)
        $wrappedMessage = wordwrap($message, $width - 4, "\n", true);
        foreach (explode("\n", $wrappedMessage) as $line) {
            $paddedLine = str_pad("  " . $line, $width);
            $this->writeLine($this->colorize("  │", self::COLOR_RED) . $paddedLine . $this->colorize("│", self::COLOR_RED));
        }

        // Bottom border
        $this->writeLine($this->colorize("  ╰{$border}╯", self::COLOR_RED));
    }

    /**
     * Render a code snippet around the error line.
     */
    private function renderCodeSnippet(string $file, int $errorLine, int $context = 3): void
    {
        if (!file_exists($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file);
        if (!$lines) {
            return;
        }

        $start = max(0, $errorLine - $context - 1);
        $end = min(count($lines), $errorLine + $context);

        fwrite(STDERR, PHP_EOL);

        for ($i = $start; $i < $end; $i++) {
            $lineNumber = $i + 1;
            $code = rtrim($lines[$i]);
            $isErrorLine = $lineNumber === $errorLine;

            // Line number
            $lineNumStr = str_pad((string) $lineNumber, 4, ' ', STR_PAD_LEFT);

            if ($isErrorLine) {
                // Highlight error line
                $arrow = $this->colorize(" ➜ ", self::COLOR_RED . self::COLOR_BOLD);
                $lineNumColored = $this->colorize($lineNumStr, self::COLOR_RED . self::COLOR_BOLD);
                $codeColored = $this->colorize($code, self::COLOR_WHITE . self::COLOR_BOLD);
                $this->writeLine($arrow . $lineNumColored . $this->colorize(" │ ", self::COLOR_GRAY) . $codeColored);
            } else {
                // Normal line
                $lineNumColored = $this->colorize($lineNumStr, self::COLOR_GRAY);
                $codeColored = $this->colorize($code, self::COLOR_GRAY);
                $this->writeLine("   " . $lineNumColored . $this->colorize(" │ ", self::COLOR_GRAY) . $codeColored);
            }
        }
    }

    /**
     * Render the stack trace.
     */
    private function renderStackTrace(array $trace): void
    {
        if (empty($trace)) {
            return;
        }

        fwrite(STDERR, PHP_EOL);
        $this->writeLine($this->colorize("  Exception trace:", self::COLOR_CYAN . self::COLOR_BOLD));
        fwrite(STDERR, PHP_EOL);

        $count = count($trace);
        $maxFrames = min(15, $count); // Limit to 15 frames

        for ($i = 0; $i < $maxFrames; $i++) {
            $frame = $trace[$i];
            $this->renderStackFrame($i + 1, $frame);
        }

        if ($count > $maxFrames) {
            $remaining = $count - $maxFrames;
            $this->writeLine($this->colorize("  ... and {$remaining} more frames", self::COLOR_GRAY));
        }
    }

    /**
     * Render a single stack frame.
     */
    private function renderStackFrame(int $index, array $frame): void
    {
        $file = $frame['file'] ?? 'unknown';
        $line = $frame['line'] ?? 0;
        $class = $frame['class'] ?? '';
        $type = $frame['type'] ?? '';
        $function = $frame['function'] ?? '';

        // Format the function call
        $call = '';
        if ($class) {
            $call = $this->colorize($class, self::COLOR_YELLOW) . $this->colorize($type, self::COLOR_GRAY);
        }
        $call .= $this->colorize($function . '()', self::COLOR_GREEN);

        // Format file:line
        $location = $this->formatFilePath($file) . $this->colorize(":" . $line, self::COLOR_GRAY);

        // Index number
        $indexStr = $this->colorize(str_pad((string) $index, 3, ' ', STR_PAD_LEFT), self::COLOR_GRAY);

        $this->writeLine("  {$indexStr} {$location}");
        $this->writeLine("      {$call}");
        fwrite(STDERR, PHP_EOL);
    }

    /**
     * Format a file path to be more readable.
     */
    private function formatFilePath(string $path): string
    {
        // Try to shorten path relative to project root
        $basePath = defined('APP_BASE_PATH') ? constant('APP_BASE_PATH') : getcwd();

        if ($basePath && str_starts_with($path, $basePath)) {
            $relativePath = substr($path, strlen($basePath) + 1);
            return $this->colorize($relativePath, self::COLOR_CYAN);
        }

        // Shorten vendor paths
        if (str_contains($path, '/vendor/')) {
            $parts = explode('/vendor/', $path);
            return $this->colorize('vendor/' . end($parts), self::COLOR_GRAY);
        }

        return $this->colorize($path, self::COLOR_CYAN);
    }

    /**
     * Write a line to stderr.
     */
    private function writeLine(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }

    /**
     * Colorize text if decoration is enabled.
     */
    private function colorize(string $text, string $color): string
    {
        if (!$this->decorated) {
            return $text;
        }

        return $color . $text . self::COLOR_RESET;
    }

    /**
     * Check if terminal supports colors.
     */
    private function supportsColors(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('ANSICON') !== false
                || getenv('ConEmuANSI') === 'ON'
                || getenv('TERM') === 'xterm';
        }

        return function_exists('posix_isatty')
            && defined('STDERR')
            && @posix_isatty(STDERR);
    }

    /**
     * Get terminal width.
     */
    private function getTerminalWidth(): int
    {
        $width = getenv('COLUMNS');
        if ($width !== false) {
            return (int) $width;
        }

        if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
            $width = shell_exec('tput cols 2>/dev/null');
            if ($width !== null) {
                return (int) trim($width);
            }
        }

        return 120;
    }
}
