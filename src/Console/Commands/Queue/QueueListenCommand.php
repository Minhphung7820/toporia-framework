<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Queue;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class QueueListenCommand
 *
 * Listen for and process queued jobs with auto-restart on code changes.
 *
 * This command monitors file changes and automatically restarts the worker
 * when PHP files are modified, ensuring latest code is always running.
 *
 * Features:
 * - File change detection (monitors PHP files in src/)
 * - Graceful worker restart on code changes
 * - Memory leak prevention via periodic restart
 * - Configurable restart interval
 *
 * Usage:
 *   php console queue:listen                    # Use default connection
 *   php console queue:listen redis              # Specific connection
 *   php console queue:listen --queue=emails     # Specific queue
 *   php console queue:listen --restart=60       # Restart every 60s
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Queue
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class QueueListenCommand extends Command
{
    protected string $signature = 'queue:listen {connection? : The name of the queue connection to listen on} {--queue=default : The queue to listen on} {--delay=0 : The number of seconds to delay failed jobs} {--memory=128 : The memory limit in megabytes} {--timeout=60 : The number of seconds a child process can run} {--sleep=3 : Number of seconds to sleep when no job is available} {--tries=1 : Number of times to attempt a job before logging it failed} {--restart=0 : Number of seconds to wait before restarting worker (0 = never)}';

    protected string $description = 'Listen to a given queue (auto-restart on code changes)';

    private bool $shouldQuit = false;
    private int $restartCount = 0;
    private ?int $lastFileModTime = null;
    private array $monitoredDirectories = [];

    public function __construct(
        private readonly ContainerInterface $container
    ) {}

    public function handle(): int
    {
        $connection = $this->argument('connection') ?: config('queue.default', 'sync');
        $queue = $this->option('queue', 'default');
        $memory = (int) $this->option('memory', 128);
        $timeout = (int) $this->option('timeout', 60);
        $sleep = (int) $this->option('sleep', 3);
        $tries = (int) $this->option('tries', 1);
        $restart = (int) $this->option('restart', 0);

        // Setup signal handlers for graceful shutdown
        $this->setupSignalHandlers();

        // Initialize monitored directories
        $this->initializeMonitoredDirectories();

        // Display header
        $this->displayHeader($connection, $queue, $memory, $timeout, $restart);

        // Main listen loop
        while (!$this->shouldQuit) {
            $startTime = time();

            // Start worker process
            $exitCode = $this->runWorker($connection, $queue, $memory, $timeout, $sleep, $tries, $restart);

            $this->restartCount++;

            // Check why worker stopped
            if ($this->shouldQuit) {
                $this->info("Listener stopped by user.");
                break;
            }

            // Check if code changed
            if ($this->codeHasChanged()) {
                $this->warn("🔄 Code changes detected! Restarting worker... (restart #{$this->restartCount})");
                $this->updateLastModTime();
                continue;
            }

            // Check if restart time elapsed
            if ($restart > 0 && (time() - $startTime) >= $restart) {
                $this->info("⏱️  Restart interval reached. Restarting worker... (restart #{$this->restartCount})");
                continue;
            }

            // Worker crashed unexpectedly
            if ($exitCode !== 0) {
                $this->error("Worker crashed with exit code {$exitCode}. Restarting in 5 seconds...");
                sleep(5);
                continue;
            }

            // Worker stopped gracefully
            $this->info("Worker stopped gracefully.");
            break;
        }

        // Display summary
        $this->displaySummary();

        return 0;
    }

    /**
     * Run queue worker in a subprocess.
     *
     * @param string $connection Queue connection name
     * @param string $queue Queue name
     * @param int $memory Memory limit in MB
     * @param int $timeout Timeout in seconds
     * @param int $sleep Sleep duration in seconds
     * @param int $tries Max attempts
     * @param int $restart Restart interval in seconds
     * @return int Exit code
     */
    private function runWorker(
        string $connection,
        string $queue,
        int $memory,
        int $timeout,
        int $sleep,
        int $tries,
        int $restart
    ): int {
        // Build command to run queue:work
        $basePath = $this->getBasePath();
        $consolePath = $basePath . '/console';

        // Build queue:work command with all options
        $command = sprintf(
            'php %s queue:work %s --queue=%s --memory=%d --timeout=%d --sleep=%d --max-jobs=1000',
            escapeshellarg($consolePath),
            escapeshellarg($connection),
            escapeshellarg($queue),
            $memory,
            $timeout,
            $sleep
        );

        // Execute command and stream output
        $process = popen($command, 'r');

        if (!$process) {
            $this->error("Failed to start worker process.");
            return 1;
        }

        // Stream output from worker
        while (!feof($process) && !$this->shouldQuit) {
            $line = fgets($process);
            if ($line !== false) {
                echo $line; // Stream worker output
                flush();
            }

            // Check for code changes periodically
            if ($this->codeHasChanged()) {
                pclose($process);
                return 0; // Graceful restart
            }

            // Dispatch signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $exitCode = pclose($process);

        return $exitCode === false ? 1 : ($exitCode >> 8);
    }

    /**
     * Check if code files have been modified.
     *
     * @return bool True if code changed
     */
    private function codeHasChanged(): bool
    {
        $currentModTime = $this->getLastModificationTime();

        if ($this->lastFileModTime === null) {
            $this->lastFileModTime = $currentModTime;
            return false;
        }

        return $currentModTime > $this->lastFileModTime;
    }

    /**
     * Get the most recent modification time of monitored files.
     *
     * @return int Unix timestamp
     */
    private function getLastModificationTime(): int
    {
        $latestModTime = 0;

        foreach ($this->monitoredDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $modTime = $file->getMTime();
                    if ($modTime > $latestModTime) {
                        $latestModTime = $modTime;
                    }
                }
            }
        }

        return $latestModTime;
    }

    /**
     * Update stored last modification time.
     *
     * @return void
     */
    private function updateLastModTime(): void
    {
        $this->lastFileModTime = $this->getLastModificationTime();
    }

    /**
     * Initialize directories to monitor for changes.
     *
     * @return void
     */
    private function initializeMonitoredDirectories(): void
    {
        $basePath = $this->getBasePath();

        // Monitor common code directories
        $this->monitoredDirectories = [
            $basePath . '/src',
            $basePath . '/app',
            $basePath . '/domain',
            $basePath . '/infrastructure',
        ];

        // Filter out non-existent directories
        $this->monitoredDirectories = array_filter(
            $this->monitoredDirectories,
            fn($dir) => is_dir($dir)
        );

        // Initialize last modification time
        $this->updateLastModTime();
    }

    /**
     * Setup signal handlers for graceful shutdown.
     *
     * @return void
     */
    private function setupSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $shutdown = function () {
            $this->newLine();
            $this->warn("Received shutdown signal. Stopping listener...");
            $this->shouldQuit = true;
        };

        pcntl_signal(SIGTERM, $shutdown);
        pcntl_signal(SIGINT, $shutdown);
    }

    /**
     * Display header with configuration.
     *
     * @param string $connection Queue connection
     * @param string $queue Queue name
     * @param int $memory Memory limit
     * @param int $timeout Timeout
     * @param int $restart Restart interval
     * @return void
     */
    private function displayHeader(
        string $connection,
        string $queue,
        int $memory,
        int $timeout,
        int $restart
    ): void {
        $this->line('=', 80);
        $this->writeln('Queue Listener Started');
        $this->line('=', 80);
        $this->writeln("Connection:    {$connection}");
        $this->writeln("Queue:         {$queue}");
        $this->writeln("Memory Limit:  {$memory} MB");
        $this->writeln("Timeout:       {$timeout}s");
        $this->writeln("Restart:       " . ($restart > 0 ? "{$restart}s" : 'disabled'));
        $this->writeln("Monitoring:    " . count($this->monitoredDirectories) . " directories");
        $this->writeln("Time:          " . now()->toDateTimeString());
        $this->writeln("PID:           " . getmypid());
        $this->line('=', 80);
        $this->newLine();

        if (count($this->monitoredDirectories) > 0) {
            $this->info("📁 Monitoring directories for changes:");
            foreach ($this->monitoredDirectories as $dir) {
                $this->writeln("   - " . basename($dir) . "/");
            }
            $this->newLine();
        }

        $this->info("👂 Listening... Press Ctrl+C to stop.");
        $this->newLine();
    }

    /**
     * Display summary after listener stops.
     *
     * @return void
     */
    private function displaySummary(): void
    {
        $this->newLine();
        $this->line('=', 80);
        $this->writeln('Queue Listener Stopped');
        $this->writeln("Restarts:      {$this->restartCount}");
        $this->writeln("Stopped at:    " . now()->toDateTimeString());
        $this->line('=', 80);
    }

    /**
     * Get application base path.
     *
     * @return string
     */

        return getcwd() ?: dirname(__DIR__, 6);
    }
}
