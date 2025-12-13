<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Console\Scheduling\Scheduler;

/**
 * Schedule List Command
 *
 * Display all scheduled tasks with their cron expressions.
 *
 * Usage:
 *   php console schedule:list
 */
/**
 * Class ScheduleListCommand
 *
 * List all scheduled commands.
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
final class ScheduleListCommand extends Command
{
    protected string $signature = 'schedule:list';
    protected string $description = 'List all scheduled tasks';

    public function __construct(
        private readonly Scheduler $scheduler
    ) {}

    public function handle(): int
    {
        $tasks = $this->scheduler->getTasks();

        if (empty($tasks)) {
            $this->info('No scheduled tasks defined.');
            return 0;
        }

        $this->info('Scheduled Tasks:');
        $this->newLine();

        // Prepare table data
        $headers = ['ID', 'Expression', 'Description', 'Next Run', 'Priority', 'Status'];
        $rows = [];
        $timezone = new \DateTimeZone(config('app.timezone', 'Asia/Ho_Chi_Minh'));
        $now = new \DateTime('now', $timezone);

        foreach ($tasks as $task) {
            $nextRun = $task->getNextRunTime($now);
            $isDue = $task->isDue($now);

            // Convert nextRun to app timezone for display
            $nextRunDisplay = clone $nextRun;
            $nextRunDisplay->setTimezone($timezone);

            $rows[] = [
                substr($task->getTaskId(), 0, 8),
                $task->getExpression(),
                $task->getDescription() ?: '(no description)',
                $nextRunDisplay->format('Y-m-d H:i:s'),
                (string)$task->getPriority(),
                $isDue ? 'Due' : 'Pending',
            ];
        }

        $this->table($headers, $rows);

        $this->newLine();
        $this->info("Total: " . count($tasks) . " task(s)");
        $this->info("Current time: " . $now->format('Y-m-d H:i:s'));

        // Show detailed information if verbose
        if ($this->hasOption('verbose') || $this->hasOption('v')) {
            $this->displayDetailedInfo($tasks);
        }

        return 0;
    }

    /**
     * Display detailed information about tasks.
     *
     * @param array $tasks
     * @return void
     */
    private function displayDetailedInfo(array $tasks): void
    {
        $this->newLine();
        $this->writeln('Detailed Information:');
        $this->line('-', 80);

        foreach ($tasks as $task) {
            $this->writeln("Task: {$task->getDescription()}");
            $this->writeln("  ID: {$task->getTaskId()}");
            $this->writeln("  Expression: {$task->getExpression()}");

            $nextRun = $task->getNextRunTime();
            $this->writeln("  Next Run: {$nextRun->format('Y-m-d H:i:s')}");

            if ($task->getPriority() !== 0) {
                $this->writeln("  Priority: {$task->getPriority()}");
            }

            if (!empty($task->getDependencies())) {
                $this->writeln("  Dependencies: " . implode(', ', $task->getDependencies()));
            }

            if ($task->getTimeout() !== null) {
                $this->writeln("  Timeout: {$task->getTimeout()}s");
            }

            if ($task->getMaxRetries() > 0) {
                $this->writeln("  Max Retries: {$task->getMaxRetries()}");
            }

            // Show statistics
            $stats = \Toporia\Framework\Console\Scheduling\Support\TaskHistory::getStatistics($task->getTaskId());
            if ($stats['total'] > 0) {
                $this->writeln("  Statistics:");
                $this->writeln("    Total Runs: {$stats['total']}");
                $this->writeln("    Successful: {$stats['successful']}");
                $this->writeln("    Failed: {$stats['failed']}");
                if ($stats['avgDuration'] !== null) {
                    $this->writeln("    Avg Duration: " . number_format($stats['avgDuration'], 2) . "s");
                }
            }

            $this->newLine();
        }
    }
}
