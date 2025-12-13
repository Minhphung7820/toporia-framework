<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Queue\Contracts\QueueManagerInterface;
use Toporia\Framework\Queue\RabbitMQQueue;
use Toporia\Framework\Queue\Worker;

/**
 * Class QueueWorkCommand
 *
 * Process jobs from the queue with graceful shutdown support.
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
final class QueueWorkCommand extends Command
{
    protected string $signature = 'queue:work';
    protected string $description = 'Process jobs from the queue';

    private bool $shouldQuit = false;

    public function __construct(
        private readonly QueueManagerInterface $queueManager,
        private readonly ContainerInterface $container
    ) {}

    public function handle(): int
    {
        // Parse options
        $queuesOption = $this->option('queue', 'default');
        $maxJobs = (int) $this->option('max-jobs', 0); // 0 = unlimited
        $sleep = (int) $this->option('sleep', 1);
        $stopWhenEmpty = $this->hasOption('stop-when-empty');
        $memoryLimit = (int) $this->option('memory', 128); // MB
        $maxRuntime = (int) $this->option('timeout', 0); // seconds, 0 = unlimited

        // Parse multiple queues (comma-separated)
        $queues = $this->parseQueues($queuesOption);

        // Get queue instance
        try {
            $queue = $this->queueManager->driver();

            // Optimize sleep for RabbitMQ (faster polling)
            // RabbitMQ basic_get is fast, so we can reduce sleep time
            if ($queue instanceof RabbitMQQueue) {
                // RabbitMQ is fast, use shorter sleep if not explicitly set
                if (!$this->hasOption('sleep')) {
                    $sleep = 0; // No sleep for RabbitMQ - basic_get is instant
                }
            }
        } catch (\Exception $e) {
            $this->error("Failed to initialize queue: {$e->getMessage()}");
            return 1;
        }

        // Create worker with container for dependency injection and auto-restart support
        $worker = new Worker(
            queue: $queue,
            container: $this->container,
            maxJobs: $maxJobs,
            sleep: $sleep,
            timezone: null,
            memoryLimit: $memoryLimit,
            maxRuntime: $maxRuntime
        );

        // Setup graceful shutdown
        $this->setupSignalHandlers($worker);

        // Display configuration
        $this->displayHeader($queues, $maxJobs, $sleep, $stopWhenEmpty);

        // Start processing
        try {
            if ($stopWhenEmpty) {
                $this->processUntilEmpty($worker, $queues);
            } else {
                $worker->work($queues);
            }
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("Worker crashed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
            return 1;
        }

        // Display summary
        $this->displaySummary($worker);

        return 0;
    }

    /**
     * Parse queue option into array of queue names.
     *
     * Supports comma-separated queues with priority order.
     * Example: "emails,default,notifications" -> ["emails", "default", "notifications"]
     *
     * Performance: O(N) where N = number of queues
     *
     * @param string $queuesOption
     * @return array<string>
     */
    private function parseQueues(string $queuesOption): array
    {
        // Split by comma and trim whitespace
        $queues = array_map('trim', explode(',', $queuesOption));

        // Remove empty values and duplicates
        $queues = array_filter(array_unique($queues));

        // Re-index array
        return array_values($queues);
    }

    /**
     * Setup signal handlers for graceful shutdown
     *
     * @param Worker $worker
     * @return void
     */
    private function setupSignalHandlers(Worker $worker): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $shutdown = function () use ($worker) {
            $this->newLine(2);
            $this->warn("Received shutdown signal...");
            $this->info("Waiting for current job to finish...");
            $worker->stop();
            $this->shouldQuit = true;
        };

        pcntl_signal(SIGTERM, $shutdown); // Kill signal
        pcntl_signal(SIGINT, $shutdown);  // Ctrl+C
    }

    /**
     * Process jobs until all queues are empty.
     *
     * Processes queues in priority order (first queue checked first).
     *
     * @param Worker $worker
     * @param array<string> $queues Queue names in priority order
     * @return void
     */
    private function processUntilEmpty(Worker $worker, array $queues): void
    {
        $processed = 0;

        while (!$this->shouldQuit) {
            $job = null;

            // Try each queue in priority order
            foreach ($queues as $queueName) {
                $job = $worker->getQueue()->pop($queueName);

                if ($job !== null) {
                    break; // Found a job, stop checking other queues
                }
            }

            if ($job === null) {
                $this->info("All queues are empty. Stopping.");
                break;
            }

            try {
                // Use container to call handle() with dependency injection
                $this->container->call([$job, 'handle']);
                $this->success("Job processed successfully: " . get_class($job));
                $processed++;
            } catch (\Throwable $e) {
                $this->error("Job failed: {$e->getMessage()}");
                $job->failed($e);
            }
        }

        $this->info("Processed {$processed} job(s)");
    }

    /**
     * Display header with configuration.
     *
     * @param array<string> $queues Queue names in priority order
     * @param int $maxJobs
     * @param int $sleep
     * @param bool $stopWhenEmpty
     * @return void
     */
    private function displayHeader(
        array $queues,
        int $maxJobs,
        int $sleep,
        bool $stopWhenEmpty
    ): void {
        $memoryLimit = (int) $this->option('memory', 128);
        $maxRuntime = (int) $this->option('timeout', 0);

        $this->line('=', 80);
        $this->writeln('Queue Worker Started');
        $this->line('=', 80);
        $this->writeln("Queue:         " . implode(',', $queues));
        $this->writeln("Max Jobs:      " . ($maxJobs > 0 ? $maxJobs : 'unlimited'));
        $this->writeln("Sleep:         {$sleep} second(s)");
        $this->writeln("Memory Limit:  {$memoryLimit} MB");
        $this->writeln("Runtime Limit: " . ($maxRuntime > 0 ? "{$maxRuntime} seconds" : 'unlimited'));
        $this->writeln("Stop when empty: " . ($stopWhenEmpty ? 'yes' : 'no'));
        $this->writeln("Time:          " . now()->toDateTimeString());
        $this->writeln("PID:           " . getmypid());
        $this->line('=', 80);
        $this->newLine();

        // Show priority order if multiple queues
        if (count($queues) > 1) {
            $this->info("Queue priority order (first = highest):");
            foreach ($queues as $index => $queue) {
                $this->writeln("  " . ($index + 1) . ". {$queue}");
            }
            $this->newLine();
        }
    }

    /**
     * Display summary after worker stops
     *
     * @param Worker $worker
     * @return void
     */
    private function displaySummary(Worker $worker): void
    {
        $this->newLine();
        $this->line('=', 80);
        $this->writeln('Worker Stopped');
        $this->writeln("Processed: {$worker->getProcessedCount()} job(s)");
        $this->line('=', 80);
    }
}
