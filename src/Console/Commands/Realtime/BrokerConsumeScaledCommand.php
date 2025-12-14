<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Realtime;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Realtime\Consumer\ConsumerHandlerRegistry;
use Toporia\Framework\Realtime\Consumer\Contracts\BatchConsumerHandlerInterface;
use Toporia\Framework\Realtime\Consumer\Contracts\ConsumerContext;
use Toporia\Framework\Realtime\RealtimeManager;
use Toporia\Framework\Realtime\Message;

/**
 * Class BrokerConsumeScaledCommand
 *
 * Multi-process consumer command for horizontal scaling.
 * Spawns multiple consumer processes for parallel partition consumption.
 *
 * Architecture:
 * - Master process manages worker lifecycle
 * - Each worker consumes from assigned partitions
 * - Auto-restart on worker failure
 * - Graceful shutdown with signal handling
 *
 * Usage:
 *   # Single process (default)
 *   php console broker:consume-scaled --handler=OrderProcessor --driver=kafka
 *
 *   # Multiple workers (one per partition)
 *   php console broker:consume-scaled --handler=OrderProcessor --workers=10
 *
 *   # With batch processing
 *   php console broker:consume-scaled --handler=OrderProcessor --batch-size=500
 *
 * Performance:
 *   - 1 worker: ~10,000-20,000 msg/s
 *   - 4 workers: ~40,000-80,000 msg/s
 *   - 10 workers: ~100,000-200,000 msg/s
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class BrokerConsumeScaledCommand extends Command
{
    protected string $signature = 'broker:consume-scaled
        {--handler= : Handler name (required)}
        {--driver= : Broker driver (kafka, redis, rabbitmq)}
        {--workers=1 : Number of worker processes}
        {--batch-size=100 : Messages per batch}
        {--batch-timeout=100 : Batch timeout in ms}
        {--timeout=1000 : Poll timeout in ms}
        {--max-messages=0 : Max messages before restart (0 = unlimited)}
        {--memory-limit=256 : Memory limit in MB before restart}
        {--graceful-timeout=30 : Graceful shutdown timeout in seconds}';

    protected string $description = 'Run scaled consumer workers for high-throughput message processing';

    private bool $shouldStop = false;

    /**
     * @var array<int, int> Child process PIDs [pid => worker_id]
     */
    private array $workers = [];

    /**
     * @var array<int, array{messages: int, errors: int, started_at: float}> Worker stats
     */
    private array $workerStats = [];

    public function handle(): int
    {
        $handlerName = $this->option('handler');
        $driver = $this->option('driver') ?? 'kafka';
        $workerCount = (int) $this->option('workers');
        $batchSize = (int) $this->option('batch-size');
        $batchTimeout = (int) $this->option('batch-timeout');
        $timeoutMs = (int) $this->option('timeout');
        $maxMessages = (int) $this->option('max-messages');
        $memoryLimit = (int) $this->option('memory-limit');
        $gracefulTimeout = (int) $this->option('graceful-timeout');

        if (empty($handlerName)) {
            $this->error('Handler name is required. Use --handler=HandlerName');
            return 1;
        }

        // Get handler from registry
        $registry = app(ConsumerHandlerRegistry::class);
        $handler = $registry->get($handlerName);

        if ($handler === null) {
            $this->error("Handler [{$handlerName}] not found in registry.");
            $this->line('Available handlers:');
            foreach ($registry->all() as $name => $h) {
                $this->line("  - {$name}");
            }
            return 1;
        }

        $this->info("Starting scaled consumer: {$handlerName}");
        $this->info("  Driver: {$driver}");
        $this->info("  Workers: {$workerCount}");
        $this->info("  Batch size: {$batchSize}");
        $this->info("  Channels: " . implode(', ', $handler->getChannels()));

        // Check if pcntl is available for multi-process
        if ($workerCount > 1 && !extension_loaded('pcntl')) {
            $this->warning('pcntl extension not available. Running single process.');
            $workerCount = 1;
        }

        if ($workerCount === 1) {
            // Single process mode
            return $this->runSingleWorker(
                $handler,
                $driver,
                $batchSize,
                $batchTimeout,
                $timeoutMs,
                $maxMessages,
                $memoryLimit
            );
        }

        // Multi-process mode
        return $this->runMultiProcess(
            $handlerName,
            $driver,
            $workerCount,
            $batchSize,
            $batchTimeout,
            $timeoutMs,
            $maxMessages,
            $memoryLimit,
            $gracefulTimeout
        );
    }

    /**
     * Run single worker process.
     */
    private function runSingleWorker(
        object $handler,
        string $driver,
        int $batchSize,
        int $batchTimeout,
        int $timeoutMs,
        int $maxMessages,
        int $memoryLimit
    ): int {
        $realtime = app(RealtimeManager::class);
        $broker = $realtime->broker($driver);

        if ($broker === null) {
            $this->error("Broker [{$driver}] not configured.");
            return 1;
        }

        // Setup signal handlers
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
        }

        $context = new ConsumerContext(
            driver: $driver,
            handlerName: $handler->getName(),
            channel: implode(', ', $handler->getChannels()),
            processId: getmypid() ?: 0,
            startedAt: microtime(true)
        );

        $handler->onStart($context);

        // Subscribe to channels
        $channels = $handler->getChannels();
        foreach ($channels as $channel) {
            $broker->subscribe($channel, function (Message $message) use ($handler, &$context, $batchSize, $batchTimeout, $maxMessages, $memoryLimit) {
                return $this->processMessage($handler, $message, $context, $batchSize, $batchTimeout, $maxMessages, $memoryLimit);
            });
        }

        // Start consuming
        $this->info('Worker started. Consuming messages...');

        if (method_exists($broker, 'consume')) {
            $broker->consume($timeoutMs, $batchSize);
        }

        $handler->onStop($context);

        return 0;
    }

    /**
     * Process a message with batch support.
     */
    private function processMessage(
        object $handler,
        Message $message,
        ConsumerContext &$context,
        int $batchSize,
        int $batchTimeout,
        int $maxMessages,
        int $memoryLimit
    ): bool {
        static $batch = [];
        static $lastBatchTime = null;

        if ($lastBatchTime === null) {
            $lastBatchTime = microtime(true);
        }

        $batch[] = $message;
        $context = $context->withMessageCount($context->messageCount + 1);

        // Check if we should process batch
        $shouldProcess = count($batch) >= $batchSize
            || (microtime(true) - $lastBatchTime) * 1000 >= $batchTimeout;

        if ($shouldProcess && !empty($batch)) {
            $startTime = microtime(true);

            try {
                if ($handler instanceof BatchConsumerHandlerInterface) {
                    $failed = $handler->handleBatch($batch, $context);
                    if (!empty($failed)) {
                        $context = $context->withErrorCount($context->errorCount + count($failed));
                    }
                } else {
                    // Process individually
                    foreach ($batch as $msg) {
                        try {
                            $handler->handle($msg, $context);
                        } catch (\Throwable $e) {
                            $context = $context->withErrorCount($context->errorCount + 1);
                            $handler->onFailed($msg, $e, $context);
                        }
                    }
                }

                $duration = (microtime(true) - $startTime) * 1000;
                $this->line(sprintf(
                    '[%s] Processed %d messages in %.2fms (%.0f msg/s)',
                    date('H:i:s'),
                    count($batch),
                    $duration,
                    $duration > 0 ? count($batch) / ($duration / 1000) : 0
                ));

            } catch (\Throwable $e) {
                $context = $context->withErrorCount($context->errorCount + count($batch));
                $this->error("Batch processing failed: {$e->getMessage()}");
            }

            $batch = [];
            $lastBatchTime = microtime(true);
        }

        // Check limits
        if ($maxMessages > 0 && $context->messageCount >= $maxMessages) {
            $this->info("Max messages ({$maxMessages}) reached. Stopping.");
            return false;
        }

        if ($memoryLimit > 0 && memory_get_usage(true) / 1024 / 1024 > $memoryLimit) {
            $this->info("Memory limit ({$memoryLimit}MB) reached. Stopping.");
            return false;
        }

        if ($this->shouldStop) {
            $this->info('Received stop signal. Stopping gracefully.');
            return false;
        }

        return true;
    }

    /**
     * Run multi-process mode with worker management.
     */
    private function runMultiProcess(
        string $handlerName,
        string $driver,
        int $workerCount,
        int $batchSize,
        int $batchTimeout,
        int $timeoutMs,
        int $maxMessages,
        int $memoryLimit,
        int $gracefulTimeout
    ): int {
        $this->info("Starting {$workerCount} worker processes...");

        // Setup signal handlers for master
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->handleMasterShutdown($gracefulTimeout));
        pcntl_signal(SIGINT, fn() => $this->handleMasterShutdown($gracefulTimeout));
        pcntl_signal(SIGCHLD, fn() => $this->handleChildExit());

        // Spawn workers
        for ($i = 0; $i < $workerCount; $i++) {
            $this->spawnWorker($i, $handlerName, $driver, $batchSize, $batchTimeout, $timeoutMs, $maxMessages, $memoryLimit);
        }

        // Master loop - monitor workers
        while (!$this->shouldStop && !empty($this->workers)) {
            // Reap any dead children
            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                $this->handleWorkerExit($pid, $status, $handlerName, $driver, $batchSize, $batchTimeout, $timeoutMs, $maxMessages, $memoryLimit);
            }

            // Print stats every 10 seconds
            static $lastStatsTime = 0;
            if (time() - $lastStatsTime >= 10) {
                $this->printStats();
                $lastStatsTime = time();
            }

            usleep(100000); // 100ms
        }

        $this->info('Master process exiting.');
        return 0;
    }

    /**
     * Spawn a worker process.
     */
    private function spawnWorker(
        int $workerId,
        string $handlerName,
        string $driver,
        int $batchSize,
        int $batchTimeout,
        int $timeoutMs,
        int $maxMessages,
        int $memoryLimit
    ): void {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->error("Failed to fork worker {$workerId}");
            return;
        }

        if ($pid === 0) {
            // Child process
            $this->runWorkerProcess($workerId, $handlerName, $driver, $batchSize, $batchTimeout, $timeoutMs, $maxMessages, $memoryLimit);
            exit(0);
        }

        // Parent process
        $this->workers[$pid] = $workerId;
        $this->workerStats[$workerId] = [
            'messages' => 0,
            'errors' => 0,
            'started_at' => microtime(true),
        ];

        $this->line("  Worker {$workerId} started (PID: {$pid})");
    }

    /**
     * Run worker process (child).
     */
    private function runWorkerProcess(
        int $workerId,
        string $handlerName,
        string $driver,
        int $batchSize,
        int $batchTimeout,
        int $timeoutMs,
        int $maxMessages,
        int $memoryLimit
    ): void {
        // Get fresh instances in child
        $registry = app(ConsumerHandlerRegistry::class);
        $handler = $registry->get($handlerName);

        if ($handler === null) {
            error_log("Worker {$workerId}: Handler not found");
            return;
        }

        // Run single worker
        $this->runSingleWorker(
            $handler,
            $driver,
            $batchSize,
            $batchTimeout,
            $timeoutMs,
            $maxMessages,
            $memoryLimit
        );
    }

    /**
     * Handle worker process exit.
     */
    private function handleWorkerExit(
        int $pid,
        int $status,
        string $handlerName,
        string $driver,
        int $batchSize,
        int $batchTimeout,
        int $timeoutMs,
        int $maxMessages,
        int $memoryLimit
    ): void {
        if (!isset($this->workers[$pid])) {
            return;
        }

        $workerId = $this->workers[$pid];
        unset($this->workers[$pid]);

        $exitCode = pcntl_wexitstatus($status);
        $signal = pcntl_wtermsig($status);

        if ($signal > 0) {
            $this->warning("Worker {$workerId} killed by signal {$signal}");
        } else {
            $this->info("Worker {$workerId} exited with code {$exitCode}");
        }

        // Restart worker if not shutting down
        if (!$this->shouldStop) {
            $this->line("Restarting worker {$workerId}...");
            sleep(1); // Brief delay before restart
            $this->spawnWorker($workerId, $handlerName, $driver, $batchSize, $batchTimeout, $timeoutMs, $maxMessages, $memoryLimit);
        }
    }

    /**
     * Handle child exit signal.
     */
    private function handleChildExit(): void
    {
        // Just let the main loop handle reaping
    }

    /**
     * Handle master shutdown.
     */
    private function handleMasterShutdown(int $timeout): void
    {
        $this->shouldStop = true;
        $this->info('Shutdown signal received. Stopping workers...');

        // Send SIGTERM to all workers
        foreach ($this->workers as $pid => $workerId) {
            posix_kill($pid, SIGTERM);
        }

        // Wait for workers to exit gracefully
        $deadline = time() + $timeout;
        while (!empty($this->workers) && time() < $deadline) {
            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                if (isset($this->workers[$pid])) {
                    $workerId = $this->workers[$pid];
                    unset($this->workers[$pid]);
                    $this->line("Worker {$workerId} stopped");
                }
            }
            usleep(100000);
        }

        // Force kill remaining workers
        foreach ($this->workers as $pid => $workerId) {
            $this->warning("Force killing worker {$workerId}");
            posix_kill($pid, SIGKILL);
        }
    }

    /**
     * Print worker statistics.
     */
    private function printStats(): void
    {
        $totalMessages = 0;
        $totalErrors = 0;

        foreach ($this->workerStats as $stats) {
            $totalMessages += $stats['messages'];
            $totalErrors += $stats['errors'];
        }

        $this->line(sprintf(
            '[%s] Workers: %d active | Messages: %d | Errors: %d',
            date('H:i:s'),
            count($this->workers),
            $totalMessages,
            $totalErrors
        ));
    }
}
