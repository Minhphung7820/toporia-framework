<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Foundation\Application;

/**
 * Serve Command
 *
 * Start PHP built-in development server.
 *
 * Usage:
 *   php console serve
 *   php console serve --port=8080
 *   php console serve --port=3000
 *   php console serve --host=0.0.0.0 --port=8080
 *   php console serve --host=localhost --port=8083
 *
 * Performance:
 * - O(1) path resolution
 * - Direct process execution (no overhead)
 * - Minimal memory footprint
 *
 * Clean Architecture:
 * - Single Responsibility: Only starts development server
 * - Dependency Injection: Receives Application via constructor
 * - Open/Closed: Extend via options, don't modify command
 *
 * SOLID Principles:
 * - S: Only serves development server
 * - O: Extensible via options (--port, future: --host)
 * - L: Behaves like other commands
 * - I: Uses Command interface
 * - D: Depends on Application abstraction
 */
/**
 * Class ServeCommand
 *
 * Serve the application using PHP built-in server.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ServeCommand extends Command
{
    protected string $signature = 'serve {--port=8000} {--host=127.0.0.1}';
    protected string $description = 'Start PHP built-in development server';

    /**
     * Default port if not specified
     */
    private const DEFAULT_PORT = 8000;

    /**
     * Default host
     */
    private const DEFAULT_HOST = '127.0.0.1';

    /**
     * Minimum valid port number
     */
    private const MIN_PORT = 1024;

    /**
     * Maximum valid port number
     */
    private const MAX_PORT = 65535;

    public function __construct(
        private readonly Application $app
    ) {}

    /**
     * Execute the command.
     *
     * Starts PHP built-in server with specified port.
     *
     * @return int Exit code (0 = success, non-zero = error)
     */
    public function handle(): int
    {
        $port = $this->getPort();
        $host = $this->getHost();
        $publicPath = $this->getPublicPath();

        // Validate port
        if (!$this->isValidPort($port)) {
            $this->error("Invalid port: {$port}. Port must be between " . self::MIN_PORT . " and " . self::MAX_PORT);
            return 1;
        }

        // Check if port is already in use
        if ($this->isPortInUse($host, $port)) {
            $this->error("Port {$port} is already in use. Please choose a different port.");
            return 1;
        }

        // Display server information
        $this->displayServerInfo($host, $port, $publicPath);

        // Start server
        return $this->startServer($host, $port, $publicPath);
    }

    /**
     * Get port number from option or default.
     *
     * Performance: O(1) - Direct option access
     *
     * @return int
     */
    private function getPort(): int
    {
        $port = $this->option('port', self::DEFAULT_PORT);

        // Convert to integer
        if (is_string($port)) {
            $port = (int) $port;
        }

        return $port > 0 ? $port : self::DEFAULT_PORT;
    }

    /**
     * Get host from option or default.
     *
     * Performance: O(1) - Direct option access
     *
     * @return string
     */
    private function getHost(): string
    {
        $host = $this->option('host', self::DEFAULT_HOST);

        // Convert to string if needed
        if (!is_string($host)) {
            $host = (string) $host;
        }

        return $host ?: self::DEFAULT_HOST;
    }

    /**
     * Get public directory path.
     *
     * Performance: O(1) - Direct path concatenation
     *
     * @return string
     */
    private function getPublicPath(): string
    {
        return $this->app->path('public');
    }

    /**
     * Validate port number.
     *
     * Performance: O(1) - Simple range check
     *
     * @param int $port
     * @return bool
     */
    private function isValidPort(int $port): bool
    {
        return $port >= self::MIN_PORT && $port <= self::MAX_PORT;
    }

    /**
     * Check if port is already in use.
     *
     * Performance: O(1) - Single socket connection attempt
     *
     * @param string $host
     * @param int $port
     * @return bool
     */
    private function isPortInUse(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($socket !== false) {
            fclose($socket);
            return true;
        }

        return false;
    }

    /**
     * Display server information before starting.
     *
     * @param string $host
     * @param int $port
     * @param string $publicPath
     * @return void
     */
    private function displayServerInfo(string $host, int $port, string $publicPath): void
    {
        $this->newLine();
        $this->line('=', 60);
        $this->info('PHP Development Server');
        $this->line('=', 60);
        $this->writeln("Host:     {$host}");
        $this->writeln("Port:     {$port}");
        $this->writeln("Document Root: {$publicPath}");
        $this->writeln("URL:      http://{$host}:{$port}");
        $this->line('=', 60);
        $this->newLine();
        $this->info('Server started. Press Ctrl+C to stop.');
        $this->newLine();
    }

    /**
     * Start PHP built-in server.
     *
     * Executes PHP built-in server process.
     * This method blocks until server is stopped (Ctrl+C).
     *
     * Performance: O(1) - Direct process execution
     *
     * @param string $host
     * @param int $port
     * @param string $publicPath
     * @return int Exit code
     */
    private function startServer(string $host, int $port, string $publicPath): int
    {
        // Validate public directory exists
        if (!is_dir($publicPath)) {
            $this->error("Public directory not found: {$publicPath}");
            return 1;
        }

        // Build command
        $command = sprintf(
            'php -S %s:%d -t %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($publicPath)
        );

        // Execute command (blocks until stopped)
        // passthru() executes command and outputs directly to stdout/stderr
        passthru($command, $exitCode);

        return $exitCode;
    }
}
