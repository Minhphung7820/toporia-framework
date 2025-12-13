<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Realtime;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Realtime\Brokers\Kafka\BatchProducer;
use Toporia\Framework\Realtime\Brokers\Kafka\SharedMemoryQueue;
use Toporia\Framework\Realtime\Metrics\KafkaMetricsCollector;

/**
 * Class KafkaFlushWorkerCommand
 *
 * Background worker that flushes messages from SharedMemoryQueue to Kafka.
 * Runs as a daemon, processing messages enqueued by HTTP processes.
 *
 * Architecture:
 * - HTTP Request → SharedMemoryQueue (APCu) → Response (fast)
 * - This Worker → SharedMemoryQueue → Kafka (async batch)
 *
 * Performance:
 * - Single worker: 50K-100K msg/s
 * - Multiple workers: 200K-500K msg/s (with partitioning)
 *
 * Usage:
 *   php console kafka:flush-worker                    # Single worker
 *   php console kafka:flush-worker --workers=4        # 4 parallel workers
 *   php console kafka:flush-worker --batch-size=5000  # Custom batch size
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class KafkaFlushWorkerCommand extends Command
{
    protected string $signature = 'kafka:flush-worker
        {--workers=1 : Number of worker processes}
        {--batch-size=1000 : Messages per batch}
        {--poll-interval=10 : Poll interval in milliseconds}
        {--queue=kafka_queue : Queue name in APCu}
        {--brokers=localhost:9092 : Kafka broker list}';

    protected string $description = 'Background worker to flush SharedMemoryQueue to Kafka';

    private bool $running = true;

    /**
     * @var array<int, int> Child PIDs
     */
    private array $workers = [];

    public function handle(): int
    {
        $numWorkers = (int) ($this->option('workers') ?? 1);
        $batchSize = (int) ($this->option('batch-size') ?? 1000);
        $pollInterval = (int) ($this->option('poll-interval') ?? 10);
        $queueName = (string) ($this->option('queue') ?? 'kafka_queue');
        $brokers = (string) ($this->option('brokers') ?? 'localhost:9092');

        // Check APCu
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            $this->error('APCu extension required and must be enabled');
            $this->line('Install: pecl install apcu');
            $this->line('Enable: apc.enable_cli=1 in php.ini');
            return 1;
        }

        // Setup signal handlers
        $this->setupSignalHandlers();

        $this->info("Kafka Flush Worker starting...");
        $this->line("Workers: {$numWorkers}");
        $this->line("Batch size: {$batchSize}");
        $this->line("Poll interval: {$pollInterval}ms");
        $this->line("Queue: {$queueName}");
        $this->line("Brokers: {$brokers}");
        $this->newLine();

        if ($numWorkers > 1 && extension_loaded('pcntl')) {
            return $this->runMultiProcess($numWorkers, $batchSize, $pollInterval, $queueName, $brokers);
        }

        return $this->runSingleProcess($batchSize, $pollInterval, $queueName, $brokers);
    }

    /**
     * Run single worker process.
     */
    private function runSingleProcess(int $batchSize, int $pollInterval, string $queueName, string $brokers): int
    {
        $queue = new SharedMemoryQueue($queueName);
        $producer = new BatchProducer($brokers, [], $batchSize * 10);
        $metrics = KafkaMetricsCollector::getInstance();
        $producer->setMetrics($metrics);

        $this->info("Worker started (PID: " . getmypid() . ")");

        $totalProcessed = 0;
        $lastStats = microtime(true);

        while ($this->running) {
            // Dequeue batch from shared memory
            $messages = $queue->dequeueBatch($batchSize);

            if (empty($messages)) {
                // No messages, sleep
                usleep($pollInterval * 1000);
                continue;
            }

            // Produce to Kafka
            $processed = 0;
            foreach ($messages as $msg) {
                $success = $producer->produce(
                    $msg['topic'],
                    $msg['payload'],
                    $msg['key'],
                    $msg['partition']
                );

                if ($success) {
                    $processed++;
                }
            }

            $totalProcessed += $processed;

            // Poll for delivery callbacks
            $producer->poll(0);

            // Periodic stats
            $now = microtime(true);
            if ($now - $lastStats >= 5.0) {
                $elapsed = $now - $lastStats;
                $rate = $totalProcessed / $elapsed;
                $queueStats = $queue->getStats();
                $producerStats = $producer->getStats();

                $this->line(sprintf(
                    "[%s] Processed: %d | Rate: %.0f/s | Queue: %d | Pending: %d",
                    date('H:i:s'),
                    $totalProcessed,
                    $rate,
                    $queueStats['size'],
                    $producerStats['pending']
                ));

                $totalProcessed = 0;
                $lastStats = $now;
            }

            // Process signals
            if (extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }
        }

        $this->info("Shutting down...");

        // Final flush
        $producer->flush(10000);
        $producer->shutdown();

        $this->info("Worker stopped.");
        return 0;
    }

    /**
     * Run multiple worker processes.
     */
    private function runMultiProcess(int $numWorkers, int $batchSize, int $pollInterval, string $queueName, string $brokers): int
    {
        $this->info("Starting {$numWorkers} workers...");

        // Fork workers
        for ($i = 0; $i < $numWorkers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->error("Failed to fork worker {$i}");
                continue;
            }

            if ($pid === 0) {
                // Child process
                $this->runSingleProcess($batchSize, $pollInterval, $queueName, $brokers);
                exit(0);
            }

            // Parent process
            $this->workers[] = $pid;
            $this->line("Started worker {$i} (PID: {$pid})");
        }

        // Parent: monitor workers
        $this->info("All workers started. Monitoring...");

        while ($this->running && !empty($this->workers)) {
            // Check for terminated workers
            foreach ($this->workers as $key => $pid) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);

                if ($result > 0) {
                    $exitCode = pcntl_wexitstatus($status);
                    $this->line("Worker {$pid} exited with code {$exitCode}");
                    unset($this->workers[$key]);

                    // Restart worker if still running
                    if ($this->running) {
                        $this->restartWorker($key, $batchSize, $pollInterval, $queueName, $brokers);
                    }
                }
            }

            pcntl_signal_dispatch();
            sleep(1);
        }

        // Shutdown all workers
        $this->info("Stopping all workers...");
        foreach ($this->workers as $pid) {
            posix_kill($pid, SIGTERM);
        }

        // Wait for workers to exit
        foreach ($this->workers as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $this->info("All workers stopped.");
        return 0;
    }

    /**
     * Restart a worker.
     */
    private function restartWorker(int $index, int $batchSize, int $pollInterval, string $queueName, string $brokers): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->error("Failed to restart worker {$index}");
            return;
        }

        if ($pid === 0) {
            // Child process
            $this->runSingleProcess($batchSize, $pollInterval, $queueName, $brokers);
            exit(0);
        }

        // Parent process
        $this->workers[$index] = $pid;
        $this->line("Restarted worker {$index} (PID: {$pid})");
    }

    /**
     * Setup signal handlers.
     */
    private function setupSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal) {
            $this->running = false;
            $this->line("\nReceived signal {$signal}, shutting down...");

            // Forward to children
            foreach ($this->workers as $pid) {
                posix_kill($pid, $signal);
            }
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);
    }
}
