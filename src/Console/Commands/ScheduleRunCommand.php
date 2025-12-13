<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Console\Scheduling\Scheduler;

/**
 * Schedule Run Command
 *
 * Run all scheduled tasks that are due to run (single execution).
 * This command is designed to be called by cron every minute.
 *
 * Production Setup (Cron):
 *   * * * * * cd /path/to/project && php console schedule:run >> storage/logs/schedule.log 2>&1
 *
 * For continuous mode (development), use: php console schedule:work
 *
 * Usage:
 *   php console schedule:run           # Run once (for cron)
 *   php console schedule:run --verbose # Verbose output
 */
/**
 * Class ScheduleRunCommand
 *
 * Run scheduled commands.
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
final class ScheduleRunCommand extends Command
{
    protected string $signature = 'schedule:run';
    protected string $description = 'Run all scheduled tasks that are due';

    public function __construct(
        private readonly Scheduler $scheduler
    ) {}

    public function handle(): int
    {
        $verbose = $this->hasOption('verbose') || $this->hasOption('v');

        // Display header
        $this->displayHeader($verbose);

        try {
            $tasksRun = $this->scheduler->runDueTasks();

            $this->newLine();
            if ($tasksRun === 0) {
                $this->info("No scheduled tasks are due to run.");
            } else {
                $this->success("[" . now()->format('Y-m-d H:i:s') . "] Executed {$tasksRun} task(s)");
            }
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("Scheduler error: {$e->getMessage()}");

            if ($verbose) {
                $this->error($e->getTraceAsString());
            }

            return 1;
        }

        // Display footer
        if ($verbose) {
            $this->displayFooter();
        }

        return 0;
    }

    /**
     * Display header
     *
     * @param bool $verbose
     * @return void
     */
    private function displayHeader(bool $verbose): void
    {
        if (!$verbose) {
            return;
        }

        $this->line('=', 80);
        $this->writeln('Schedule Runner');
        $this->line('=', 80);
        $this->writeln('Time: ' . now()->toDateTimeString());
        $this->writeln('Timezone: ' . date_default_timezone_get());
        $this->line('=', 80);
    }

    /**
     * Display footer
     *
     * @return void
     */
    private function displayFooter(): void
    {
        $this->newLine();
        $this->line('=', 80);
        $this->writeln('Completed at: ' . now()->toDateTimeString());
        $this->line('=', 80);
    }
}
