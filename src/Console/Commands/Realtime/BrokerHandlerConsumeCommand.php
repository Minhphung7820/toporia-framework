<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Realtime;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Realtime\Consumer\ConsumerHandlerRegistry;
use Toporia\Framework\Realtime\Consumer\ConsumerProcessManager;
use Toporia\Framework\Realtime\Consumer\Contracts\ConsumerContext;
use Toporia\Framework\Realtime\Consumer\Contracts\ConsumerHandlerInterface;
use Toporia\Framework\Realtime\Contracts\{BrokerInterface, MessageInterface, RealtimeManagerInterface};
use Toporia\Framework\Realtime\Brokers\RedisBroker;
use Toporia\Framework\Realtime\Brokers\RabbitMqBroker;
use Toporia\Framework\Realtime\Brokers\KafkaBroker;
use Toporia\Framework\Support\Accessors\Log;

/**
 * BrokerHandlerConsumeCommand
 *
 * Enterprise-grade consumer command that runs a specific handler.
 * Supports process management, monitoring, and graceful shutdown.
 *
 * Usage:
 *   php console broker:handler:consume --handler=SendOrderCreated --driver=rabbitmq
 *   php console broker:handler:consume --handler=ProcessPayment --driver=kafka
 *   php console broker:handler:consume --handler=SendNotification --driver=redis
 *
 * Features:
 * - Handler-based message processing with DI support
 * - Process registration and heartbeat monitoring
 * - Automatic retry with exponential backoff
 * - Graceful shutdown on SIGTERM/SIGINT
 * - Detailed logging and metrics
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 */
final class BrokerHandlerConsumeCommand extends Command
{
    protected string $signature = 'broker:consume
        {--handler= : Handler name (e.g., SendOrderCreated)}
        {--driver= : Broker driver (redis, rabbitmq, kafka). Uses handler preference if not specified}
        {--timeout=1000 : Poll timeout in ms}
        {--memory=128 : Memory limit in MB}
        {--max-messages=0 : Stop after N messages (0 = unlimited)}
        {--max-time=0 : Stop after N seconds (0 = unlimited)}
        {--sleep=100 : Sleep time in ms between consume cycles}
        {--once : Process a single message then exit}';

    protected string $description = 'Run a consumer handler to process broker messages';

    private bool $running = true;
    private int $messageCount = 0;
    private int $errorCount = 0;
    private float $startTime;
    private ?BrokerInterface $currentBroker = null;
    private ?ConsumerHandlerInterface $handler = null;
    private ?ConsumerContext $context = null;
    private string $processId = '';
    private int $lastHeartbeat = 0;

    /**
     * Heartbeat interval in seconds.
     */
    private const HEARTBEAT_INTERVAL = 10;

    public function __construct(
        private readonly RealtimeManagerInterface $realtime,
        private readonly ConsumerHandlerRegistry $registry,
        private readonly ConsumerProcessManager $processManager
    ) {}

    public function handle(): int
    {
        $this->startTime = microtime(true);

        // Get handler name
        $handlerName = $this->option('handler');
        if (empty($handlerName)) {
            $this->error('Handler name is required. Use --handler=HandlerName');
            $this->showAvailableHandlers();
            return 1;
        }

        // Get handler
        try {
            $this->handler = $this->registry->get($handlerName);
        } catch (\Throwable $e) {
            $this->error("Handler [{$handlerName}] not found: {$e->getMessage()}");
            $this->showAvailableHandlers();
            return 1;
        }

        // Determine driver
        $driver = $this->option('driver') ?: $this->handler->getDriver();
        if (empty($driver)) {
            $driver = config('realtime.default', 'redis');
        }

        $channels = $this->handler->getChannels();
        $timeout = (int) $this->option('timeout', 1000);
        $memoryLimit = (int) $this->option('memory', 128);
        $maxMessages = (int) $this->option('max-messages', 0);
        $maxTime = (int) $this->option('max-time', 0);
        $sleepMs = (int) $this->option('sleep', 100);
        $once = $this->option('once');

        // Display header
        $this->displayHeader($handlerName, $driver, $channels);

        // Setup signal handlers
        $this->setupSignalHandlers();

        try {
            // Get broker
            $broker = $this->realtime->broker($driver);
            if ($broker === null) {
                $this->error("Broker [{$driver}] is not configured or available.");
                return 1;
            }

            $this->currentBroker = $broker;

            // Generate process ID and register
            $this->processId = $this->processManager->generateProcessId($handlerName, $driver);

            $this->processManager->register(
                $this->processId,
                $handlerName,
                $driver,
                $channels,
                [
                    'memory_limit' => $memoryLimit,
                    'max_messages' => $maxMessages,
                    'max_time' => $maxTime,
                ]
            );

            // Create initial context
            $this->context = new ConsumerContext(
                driver: $driver,
                handlerName: $handlerName,
                channel: implode(', ', $channels),
                processId: getmypid(),
                startedAt: $this->startTime
            );

            // Call handler's onStart
            $this->handler->onStart($this->context);

            $this->success("Connected to {$driver} broker!");
            $this->info("Process ID: {$this->processId}");
            $this->info("Waiting for messages... (Press Ctrl+C to stop)");
            $this->newLine();

            // Update status to running
            $this->processManager->updateStatus($this->processId, ConsumerProcessManager::STATUS_RUNNING);

            // Start consuming based on broker type
            $this->consume($broker, $driver, $channels, $timeout, $sleepMs, [
                'memory_limit' => $memoryLimit * 1024 * 1024, // Convert to bytes
                'max_messages' => $maxMessages,
                'max_time' => $maxTime,
                'once' => $once,
            ]);
        } catch (\Throwable $e) {
            $this->error("Error: {$e->getMessage()}");
            Log::error("[Consumer:{$handlerName}] Fatal error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($this->processId) {
                $this->processManager->markFailed($this->processId, $e->getMessage(), $e::class);
            }

            return 1;
        } finally {
            // Call handler's onStop
            if ($this->handler && $this->context) {
                $this->context = $this->context
                    ->withMessageCount($this->messageCount)
                    ->withErrorCount($this->errorCount);
                $this->handler->onStop($this->context);
            }

            // Unregister process
            if ($this->processId) {
                $this->processManager->unregister($this->processId, 'Normal shutdown');
            }

            $this->displaySummary();
        }

        return 0;
    }

    /**
     * Signal dispatch interval counter.
     */
    private int $loopCounter = 0;

    /**
     * Main consume loop.
     *
     * Optimized for high-throughput production:
     * - Adaptive sleep: only sleeps when no messages (avoids latency)
     * - Batched signal dispatch: reduces syscall overhead
     * - Efficient heartbeat: only when interval elapsed
     * - Batch size tuning based on throughput
     */
    private function consume(
        BrokerInterface $broker,
        string $driver,
        array $channels,
        int $timeout,
        int $sleepMs,
        array $limits
    ): void {
        // Subscribe to channels once
        foreach ($channels as $channel) {
            $this->subscribeChannel($broker, $driver, $channel);
        }

        // Track messages for adaptive behavior
        $lastMessageCount = 0;
        $batchSize = 100; // Start with default

        // Main loop - optimized for minimal overhead
        while ($this->running) {
            $this->loopCounter++;

            // Check limits every 10 iterations (reduce overhead)
            if ($this->loopCounter % 10 === 0) {
                if ($this->shouldStop($limits)) {
                    $this->info("Limit reached. Stopping...");
                    break;
                }
            }

            // Send heartbeat (internal check for interval)
            $this->sendHeartbeat();

            // Consume messages with adaptive batch size
            $broker->consume($timeout, $batchSize);

            // Calculate if we got messages this cycle
            $messagesThisCycle = $this->messageCount - $lastMessageCount;
            $lastMessageCount = $this->messageCount;

            // Adaptive sleep: only sleep if no messages received (idle state)
            // This prevents unnecessary latency when messages are flowing
            if ($messagesThisCycle === 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            // Adaptive batch size: increase if processing many messages
            if ($messagesThisCycle >= $batchSize) {
                $batchSize = min(500, $batchSize + 50); // Scale up, max 500
            } elseif ($messagesThisCycle === 0 && $batchSize > 100) {
                $batchSize = max(100, $batchSize - 25); // Scale down, min 100
            }

            // Process signals every 10 iterations (reduces syscall overhead)
            if ($this->loopCounter % 10 === 0 && function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Subscribe to a channel based on driver.
     */
    private function subscribeChannel(BrokerInterface $broker, string $driver, string $channel): void
    {
        $callback = function (MessageInterface $message) use ($channel) {
            $this->handleMessage($message, $channel);
        };

        match ($driver) {
            'redis' => $this->subscribeRedis($broker, $channel, $callback),
            'rabbitmq' => $this->subscribeRabbitMq($broker, $channel, $callback),
            'kafka' => $this->subscribeKafka($broker, $channel, $callback),
            default => $broker->subscribe($channel, $callback),
        };
    }

    private function subscribeRedis(BrokerInterface $broker, string $channel, callable $callback): void
    {
        if ($broker instanceof RedisBroker) {
            if (str_contains($channel, '*')) {
                $broker->psubscribe($channel, fn(MessageInterface $msg, string $ch) => $callback($msg));
            } else {
                $broker->subscribe($channel, $callback);
            }
        }
    }

    private function subscribeRabbitMq(BrokerInterface $broker, string $channel, callable $callback): void
    {
        if ($broker instanceof RabbitMqBroker) {
            // Convert wildcards for RabbitMQ
            $routingKey = match (true) {
                $channel === '*' => '#',
                str_contains($channel, '*') => str_replace('*', '#', $channel),
                default => $channel,
            };

            $broker->subscribeWithRoutingKey($routingKey, $callback);
        }
    }

    private function subscribeKafka(BrokerInterface $broker, string $channel, callable $callback): void
    {
        if ($broker instanceof KafkaBroker) {
            $broker->subscribe($channel, $callback);
        }
    }

    /**
     * Handle incoming message with retry logic.
     */
    private function handleMessage(MessageInterface $message, string $channel): void
    {
        $this->messageCount++;

        // Update context
        $this->context = $this->context
            ->withMessageCount($this->messageCount)
            ->withErrorCount($this->errorCount);

        // Check if handler wants to process this message
        if (!$this->handler->shouldHandle($message)) {
            Log::debug("[Consumer:{$this->handler->getName()}] Message skipped", [
                'channel' => $channel,
                'event' => $message->getEvent(),
            ]);
            return;
        }

        $maxRetries = $this->handler->getMaxRetries();
        $attempt = 0;
        $success = false;

        while ($attempt <= $maxRetries && !$success) {
            $attempt++;
            $contextWithAttempt = $this->context->withAttempt($attempt);

            try {
                $this->handler->handle($message, $contextWithAttempt);
                $success = true;

                $this->logMessageSuccess($channel, $message, $attempt);
            } catch (\Throwable $e) {
                $this->errorCount++;
                $this->context = $this->context->withErrorCount($this->errorCount);

                Log::warning("[Consumer:{$this->handler->getName()}] Attempt {$attempt} failed", [
                    'channel' => $channel,
                    'event' => $message->getEvent(),
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                ]);

                if ($attempt <= $maxRetries) {
                    $delay = $this->handler->getRetryDelay($attempt);
                    usleep($delay * 1000); // Convert ms to microseconds
                } else {
                    // All retries exhausted
                    $this->handler->onFailed($message, $e, $contextWithAttempt);
                    $this->logMessageFailed($channel, $message, $e, $attempt);
                }
            }
        }
    }

    private function logMessageSuccess(string $channel, MessageInterface $message, int $attempt): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');

        Log::info("[Consumer:{$this->handler->getName()}] Message processed", [
            'channel' => $channel,
            'event' => $message->getEvent(),
            'id' => $message->getId(),
            'attempt' => $attempt,
        ]);

        $attemptInfo = $attempt > 1 ? " (attempt {$attempt})" : "";
        $this->writeln("[{$timestamp}] <fg=green>✓</> Message #{$this->messageCount} processed{$attemptInfo}");
    }

    private function logMessageFailed(string $channel, MessageInterface $message, \Throwable $e, int $attempts): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');

        $this->writeln("[{$timestamp}] <fg=red>✗</> Message #{$this->messageCount} failed after {$attempts} attempts: {$e->getMessage()}");
    }

    /**
     * Check if consumer should stop.
     */
    private function shouldStop(array $limits): bool
    {
        // Check memory
        if ($limits['memory_limit'] > 0) {
            $memoryUsage = memory_get_usage(true);
            if ($memoryUsage >= $limits['memory_limit']) {
                $this->warn("Memory limit reached: " . round($memoryUsage / 1024 / 1024, 2) . " MB");
                return true;
            }
        }

        // Check max messages
        if ($limits['max_messages'] > 0 && $this->messageCount >= $limits['max_messages']) {
            $this->info("Max messages reached: {$this->messageCount}");
            return true;
        }

        // Check max time
        if ($limits['max_time'] > 0) {
            $duration = microtime(true) - $this->startTime;
            if ($duration >= $limits['max_time']) {
                $this->info("Max time reached: " . round($duration, 2) . "s");
                return true;
            }
        }

        // Check once flag
        if (!empty($limits['once']) && $this->messageCount >= 1) {
            return true;
        }

        return false;
    }

    /**
     * Send heartbeat to process manager.
     */
    private function sendHeartbeat(): void
    {
        $now = time();
        if ($now - $this->lastHeartbeat >= self::HEARTBEAT_INTERVAL) {
            $this->processManager->heartbeat($this->processId, $this->context);
            $this->lastHeartbeat = $now;
        }
    }

    private function displayHeader(string $handler, string $driver, array $channels): void
    {
        $channelStr = implode(', ', $channels);

        $this->newLine();
        $this->info("╔════════════════════════════════════════════════════════════╗");
        $this->info("║               Broker Consumer Handler                        ║");
        $this->info("╠════════════════════════════════════════════════════════════╣");
        $this->info("║ Handler: " . str_pad($handler, 51) . " ║");
        $this->info("║ Driver:  " . str_pad($driver, 51) . " ║");
        $this->info("║ Channels:" . str_pad($channelStr, 51) . " ║");
        $this->info("║ Started: " . str_pad(now()->format('Y-m-d H:i:s'), 51) . " ║");
        $this->info("╚════════════════════════════════════════════════════════════╝");
        $this->newLine();
    }

    private function displaySummary(): void
    {
        $duration = microtime(true) - $this->startTime;
        $throughput = $duration > 0 ? round($this->messageCount / $duration, 2) : 0;
        $errorRate = $this->messageCount > 0 ? round(($this->errorCount / $this->messageCount) * 100, 2) : 0;

        $this->newLine();
        $this->info("╔════════════════════════════════════════════════════════════╗");
        $this->info("║                     Consumer Summary                         ║");
        $this->info("╠════════════════════════════════════════════════════════════╣");
        $this->info("║ Messages processed: " . str_pad((string) $this->messageCount, 40) . " ║");
        $this->info("║ Errors:             " . str_pad((string) $this->errorCount, 40) . " ║");
        $this->info("║ Error rate:         " . str_pad($errorRate . "%", 40) . " ║");
        $this->info("║ Duration:           " . str_pad(number_format($duration, 2) . "s", 40) . " ║");
        $this->info("║ Throughput:         " . str_pad($throughput . " msg/s", 40) . " ║");
        $this->info("║ Memory peak:        " . str_pad(round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB", 40) . " ║");
        $this->info("╚════════════════════════════════════════════════════════════╝");
    }

    private function showAvailableHandlers(): void
    {
        $handlers = $this->registry->getNames();

        if (empty($handlers)) {
            $this->warn("No handlers registered.");
            $this->writeln("Register handlers in config/consumers.php or via ConsumerHandlerRegistry.");
            return;
        }

        // Remove duplicates
        $handlers = array_unique($handlers);

        $this->newLine();
        $this->info("Available handlers:");
        foreach ($handlers as $name) {
            $this->writeln("  - {$name}");
        }
    }

    private function setupSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->warn('PCNTL extension not available. Use Ctrl+C to stop.');
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGTERM, function () {
            $this->shutdown('SIGTERM');
        });

        pcntl_signal(SIGINT, function () {
            $this->shutdown('SIGINT');
        });
    }

    private function shutdown(string $signal): void
    {
        $this->info("\nReceived {$signal}. Shutting down gracefully...");
        $this->running = false;

        // Update status to stopping
        if ($this->processId) {
            $this->processManager->updateStatus(
                $this->processId,
                ConsumerProcessManager::STATUS_STOPPING
            );
        }

        // Stop broker consuming
        if ($this->currentBroker !== null) {
            try {
                $this->currentBroker->stopConsuming();
            } catch (\Throwable) {
                // Ignore errors during shutdown
            }
        }
    }
}
