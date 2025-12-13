<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Console\Scheduling\Scheduler;

/**
 * Class ScheduleWorkCommand
 *
 * Run the scheduler continuously in the foreground (development mode).
 * Similar to queue:work, this keeps the scheduler running and checks for due tasks every minute.
 *
 * Production: Use cron with schedule:run (runs once per minute)
 * Development: Use schedule:work (runs continuously)
 *
 * Usage:
 *   php console schedule:work                # Run continuously
 *   php console schedule:work --verbose      # Verbose output
 *   php console schedule:work --sleep=30     # Check every 30 seconds
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
final class ScheduleWorkCommand extends Command
{
    protected string $signature = 'schedule:work';
    protected string $description = 'Run the scheduler continuously (development mode)';

    private bool $shouldQuit = false;

    public function __construct(
        private readonly Scheduler $scheduler
    ) {}

    public function handle(): int
    {
        $verbose = $this->hasOption('verbose') || $this->hasOption('v');
        $sleep = (int) $this->option('sleep', 60); // Default: check every 60 seconds

        // Setup signal handlers for graceful shutdown
        $this->setupSignalHandlers();

        // Display header
        $this->displayHeader($verbose, $sleep);

        return $this->runContinuously($verbose, $sleep);
    }

    /**
     * Run continuously (development mode)
     *
     * @param bool $verbose
     * @param int $sleep Sleep duration in seconds
     * @return int
     */
    private function runContinuously(bool $verbose, int $sleep): int
    {
        $this->info("Scheduler worker started. Press Ctrl+C to stop.\n");

        $runCount = 0;
        $totalTasks = 0;

        while (!$this->shouldQuit) {
            try {
                $currentTime = now();
                $currentMinute = $currentTime->format('Y-m-d H:i:s');

                if ($verbose) {
                    $this->writeln("[{$currentMinute}] Checking for due tasks...");
                }

                $tasksRun = $this->scheduler->runDueTasks();

                if ($tasksRun > 0) {
                    $this->success("[{$currentMinute}] Executed {$tasksRun} task(s)");
                    $totalTasks += $tasksRun;
                    $runCount++;
                } elseif ($verbose) {
                    $this->writeln("[{$currentMinute}] No tasks due");
                }

                // Sleep before next check
                $this->sleepWithInterruption($sleep);

            } catch (\Throwable $e) {
                $this->error("Scheduler error: {$e->getMessage()}");

                if ($verbose) {
                    $this->error($e->getTraceAsString());
                }

                // Sleep before retrying
                sleep(5);
            }
        }

        // Display summary
        $this->displaySummary($runCount, $totalTasks);

        return 0;
    }

    /**
     * Setup signal handlers for graceful shutdown
     *
     * @return void
     */
    private function setupSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $shutdown = function() {
            $this->newLine();
            $this->warn("Received shutdown signal. Stopping scheduler worker...");
            $this->shouldQuit = true;
        };

        pcntl_signal(SIGTERM, $shutdown);
        pcntl_signal(SIGINT, $shutdown);
    }

    /**
     * Sleep with ability to be interrupted
     *
     * @param int $seconds
     * @return void
     */
    private function sleepWithInterruption(int $seconds): void
    {
        $iterations = $seconds;

        for ($i = 0; $i < $iterations && !$this->shouldQuit; $i++) {
            sleep(1);
        }
    }

    /**
     * Display header
     *
     * @param bool $verbose
     * @param int $sleep
     * @return void
     */
    private function displayHeader(bool $verbose, int $sleep): void
    {
        if (!$verbose) {
            return;
        }

        $this->line('=', 80);
        $this->writeln('Schedule Worker');
        $this->line('=', 80);
        $this->writeln('Started:   ' . now()->toDateTimeString());
        $this->writeln('Timezone:  ' . date_default_timezone_get());
        $this->writeln('Sleep:     ' . $sleep . ' second(s)');
        $this->writeln('PID:       ' . getmypid());
        $this->line('=', 80);
        $this->newLine();
    }

    /**
     * Display summary
     *
     * @param int $runCount
     * @param int $totalTasks
     * @return void
     */
    private function displaySummary(int $runCount, int $totalTasks): void
    {
        $this->newLine();
        $this->line('=', 80);
        $this->writeln('Scheduler Worker Stopped');
        $this->writeln("Stopped at:    " . now()->toDateTimeString());
        $this->writeln("Cycles run:    {$runCount}");
        $this->writeln("Tasks executed: {$totalTasks}");
        $this->line('=', 80);
    }
}
