<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Console\Scheduling\Scheduler;

/**
 * Schedule Test Command
 *
 * Test a scheduled task without waiting for its schedule.
 * Useful for development and debugging.
 *
 * Usage:
 *   php console schedule:test <task-id>     # Test by task ID
 *   php console schedule:test --all       # Test all tasks
 *   php console schedule:test --due        # Test all due tasks
 */
/**
 * Class ScheduleTestCommand
 *
 * Test scheduled command execution.
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
final class ScheduleTestCommand extends Command
{
    protected string $signature = 'schedule:test {task-id?} {--all} {--due}';
    protected string $description = 'Test a scheduled task without waiting for schedule';

    public function __construct(
        private readonly Scheduler $scheduler
    ) {}

    public function handle(): int
    {
        $taskId = $this->argument('task-id');
        $testAll = $this->hasOption('all');
        $testDue = $this->hasOption('due');

        if ($testAll) {
            return $this->testAllTasks();
        }

        if ($testDue) {
            return $this->testDueTasks();
        }

        if ($taskId === null) {
            $this->error('Please provide a task ID or use --all/--due option');
            $this->writeln('');
            $this->writeln('Available tasks:');
            $this->listTasks();
            return 1;
        }

        return $this->testTask($taskId);
    }

    /**
     * Test a specific task by ID.
     *
     * @param string $taskId
     * @return int
     */
    private function testTask(string $taskId): int
    {
        $tasks = $this->scheduler->getTasks();
        $task = null;

        foreach ($tasks as $t) {
            if ($t->getTaskId() === $taskId || str_starts_with($t->getTaskId(), $taskId)) {
                $task = $t;
                break;
            }
        }

        if ($task === null) {
            $this->error("Task not found: {$taskId}");
            $this->writeln('');
            $this->writeln('Available tasks:');
            $this->listTasks();
            return 1;
        }

        $this->info("Testing task: {$task->getDescription()}");
        $this->writeln("Task ID: {$task->getTaskId()}");
        $this->writeln("Expression: {$task->getExpression()}");
        $this->newLine();

        try {
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            $task->execute();

            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            $duration = $endTime - $startTime;
            $memoryUsed = $endMemory - $startMemory;

            $this->success("Task executed successfully!");
            $this->writeln("Duration: " . number_format($duration, 2) . "s");
            $this->writeln("Memory: " . $this->formatBytes($memoryUsed));

            return 0;
        } catch (\Throwable $e) {
            $this->error("Task failed: {$e->getMessage()}");
            if ($this->hasOption('verbose') || $this->hasOption('v')) {
                $this->error($e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Test all tasks.
     *
     * @return int
     */
    private function testAllTasks(): int
    {
        $tasks = $this->scheduler->getTasks();

        if (empty($tasks)) {
            $this->info('No scheduled tasks defined.');
            return 0;
        }

        $this->info("Testing all " . count($tasks) . " task(s)...");
        $this->newLine();

        $success = 0;
        $failed = 0;

        foreach ($tasks as $task) {
            $this->writeln("Testing: {$task->getDescription()}");

            try {
                $task->execute();
                $this->success("  ✓ Success");
                $success++;
            } catch (\Throwable $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
                $failed++;
            }

            $this->newLine();
        }

        $this->info("Results: {$success} successful, {$failed} failed");

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Test all due tasks.
     *
     * @return int
     */
    private function testDueTasks(): int
    {
        $dueTasks = $this->scheduler->getDueTasks();

        if (empty($dueTasks)) {
            $this->info('No tasks are due to run.');
            return 0;
        }

        $this->info("Testing " . count($dueTasks) . " due task(s)...");
        $this->newLine();

        return $this->scheduler->runDueTasks();
    }

    /**
     * List all available tasks.
     *
     * @return void
     */
    private function listTasks(): void
    {
        $tasks = $this->scheduler->getTasks();

        foreach ($tasks as $task) {
            $this->writeln("  {$task->getTaskId()} - {$task->getDescription()}");
        }
    }

    /**
     * Format bytes to human-readable format.
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
