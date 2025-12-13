<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Queue;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Queue\Contracts\QueueManagerInterface;
use Toporia\Framework\Notification\Contracts\NotificationManagerInterface;

/**
 * Class QueueMonitorCommand
 *
 * Monitor queue sizes and alert when thresholds are exceeded.
 *
 * Features:
 * - Real-time queue size monitoring
 * - Configurable thresholds per queue
 * - Alert notifications when threshold exceeded
 * - Support for multiple queues
 * - Continuous or one-time monitoring
 *
 * Usage:
 *   php console queue:monitor                           # Monitor default queue
 *   php console queue:monitor default,emails            # Monitor multiple queues
 *   php console queue:monitor --max=1000                # Set threshold
 *   php console queue:monitor --interval=60             # Continuous monitoring
 *   php console queue:monitor --alert                   # Enable alerts
 *
 * Performance:
 * - O(Q) per check where Q = number of queues
 * - Minimal database queries using size() method
 * - Configurable check intervals to reduce overhead
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
final class QueueMonitorCommand extends Command
{
    protected string $signature = 'queue:monitor {queues?* : Comma-separated list of queues to monitor} {--max=1000 : The maximum number of jobs allowed on the queue} {--interval=0 : Check interval in seconds (0 = one-time check)} {--alert : Send alerts when threshold exceeded}';

    protected string $description = 'Monitor the size of the specified queues';

    private bool $shouldQuit = false;
    private array $alertedQueues = []; // Track which queues have been alerted

    public function __construct(
        private readonly QueueManagerInterface $queueManager,
        private readonly ?NotificationManagerInterface $notificationManager = null
    ) {}

    public function handle(): int
    {
        // Parse queues argument
        $queuesArg = $this->argument('queues');
        $queuesInput = !empty($queuesArg) ? implode(',', $queuesArg) : 'default';
        $queues = array_map('trim', explode(',', $queuesInput));
        $queues = array_filter(array_unique($queues)); // Remove duplicates and empty values

        // Get options
        $maxJobs = (int) $this->option('max', 1000);
        $interval = (int) $this->option('interval', 0);
        $enableAlerts = $this->hasOption('alert');

        // Validate
        if (empty($queues)) {
            $this->error('No queues specified for monitoring.');
            return 1;
        }

        if ($maxJobs <= 0) {
            $this->error('Max threshold must be greater than 0.');
            return 1;
        }

        // Setup signal handlers for graceful shutdown
        if ($interval > 0) {
            $this->setupSignalHandlers();
        }

        // Display header
        $this->displayHeader($queues, $maxJobs, $interval, $enableAlerts);

        // Monitor loop
        $checkCount = 0;
        do {
            $checkCount++;

            // Check all queues
            $results = $this->checkQueues($queues, $maxJobs, $enableAlerts);

            // Display results
            $this->displayResults($results, $checkCount);

            // If continuous monitoring, sleep before next check
            if ($interval > 0 && !$this->shouldQuit) {
                $this->sleep($interval);
            }
        } while ($interval > 0 && !$this->shouldQuit);

        // Display summary
        $this->displaySummary($checkCount);

        return 0;
    }

    /**
     * Check all queues and return results.
     *
     * @param array<string> $queues Queue names
     * @param int $maxJobs Maximum allowed jobs
     * @param bool $enableAlerts Whether to send alerts
     * @return array<array{queue: string, size: int, status: string, threshold_percent: float}>
     */
    private function checkQueues(array $queues, int $maxJobs, bool $enableAlerts): array
    {
        $results = [];

        foreach ($queues as $queue) {
            try {
                $size = $this->queueManager->size($queue);
                $thresholdPercent = ($size / $maxJobs) * 100;

                // Determine status
                if ($size >= $maxJobs) {
                    $status = 'critical';
                } elseif ($thresholdPercent >= 80) {
                    $status = 'warning';
                } elseif ($thresholdPercent >= 50) {
                    $status = 'info';
                } else {
                    $status = 'ok';
                }

                $results[] = [
                    'queue' => $queue,
                    'size' => $size,
                    'status' => $status,
                    'threshold_percent' => $thresholdPercent,
                ];

                // Send alert if threshold exceeded and alerts enabled
                if ($enableAlerts && $status === 'critical') {
                    $this->sendAlert($queue, $size, $maxJobs);
                }

                // Clear alert tracking if queue is back to normal
                if ($status === 'ok' && isset($this->alertedQueues[$queue])) {
                    unset($this->alertedQueues[$queue]);
                }
            } catch (\Throwable $e) {
                $results[] = [
                    'queue' => $queue,
                    'size' => -1,
                    'status' => 'error',
                    'threshold_percent' => 0,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Display check results.
     *
     * @param array<array> $results Check results
     * @param int $checkCount Number of checks performed
     * @return void
     */
    private function displayResults(array $results, int $checkCount): void
    {
        $this->writeln("[Check #{$checkCount}] " . now()->format('Y-m-d H:i:s'));
        $this->newLine();

        // Prepare table data
        $headers = ['Queue', 'Size', 'Status', 'Threshold', 'Health'];
        $rows = [];

        foreach ($results as $result) {
            if (isset($result['error'])) {
                $rows[] = [
                    $result['queue'],
                    'N/A',
                    '❌ ERROR',
                    'N/A',
                    $result['error'],
                ];
                continue;
            }

            // Format status with emoji
            $statusEmoji = match ($result['status']) {
                'critical' => '🔴 CRITICAL',
                'warning' => '🟡 WARNING',
                'info' => '🔵 INFO',
                'ok' => '🟢 OK',
                default => '⚪ UNKNOWN',
            };

            $thresholdDisplay = number_format($result['threshold_percent'], 1) . '%';
            $healthBar = $this->getHealthBar($result['threshold_percent']);

            $rows[] = [
                $result['queue'],
                number_format($result['size']),
                $statusEmoji,
                $thresholdDisplay,
                $healthBar,
            ];
        }

        // Display table
        $this->table($headers, $rows);
        $this->newLine();
    }

    /**
     * Get visual health bar.
     *
     * @param float $percent Percentage (0-100)
     * @return string Visual bar
     */
    private function getHealthBar(float $percent): string
    {
        $barLength = 20;
        $filled = (int) round(($percent / 100) * $barLength);
        $empty = $barLength - $filled;

        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }

    /**
     * Send alert notification.
     *
     * @param string $queue Queue name
     * @param int $size Current size
     * @param int $maxJobs Max threshold
     * @return void
     */
    private function sendAlert(string $queue, int $size, int $maxJobs): void
    {
        // Avoid duplicate alerts for same queue
        if (isset($this->alertedQueues[$queue])) {
            return;
        }

        $this->alertedQueues[$queue] = true;

        $message = "Queue '{$queue}' has exceeded threshold: {$size} jobs (max: {$maxJobs})";

        // Log warning
        $this->warn("🚨 ALERT: {$message}");

        // Send notification if notification manager is available
        if ($this->notificationManager !== null) {
            try {
                // Create simple notification
                // Note: This would require implementing notification channels
                // For now, just log it
                $this->info("   Notification would be sent to configured channels.");
            } catch (\Throwable $e) {
                $this->error("   Failed to send notification: {$e->getMessage()}");
            }
        }
    }

    /**
     * Sleep with signal interruption support.
     *
     * @param int $seconds Sleep duration
     * @return void
     */
    private function sleep(int $seconds): void
    {
        $remaining = $seconds;

        while ($remaining > 0 && !$this->shouldQuit) {
            $chunk = min(1, $remaining);
            sleep((int) $chunk);
            $remaining -= $chunk;

            // Dispatch pending signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
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
            $this->warn("Received shutdown signal. Stopping monitor...");
            $this->shouldQuit = true;
        };

        pcntl_signal(SIGTERM, $shutdown);
        pcntl_signal(SIGINT, $shutdown);
    }

    /**
     * Display header.
     *
     * @param array<string> $queues Queue names
     * @param int $maxJobs Max threshold
     * @param int $interval Check interval
     * @param bool $enableAlerts Alert enabled
     * @return void
     */
    private function displayHeader(
        array $queues,
        int $maxJobs,
        int $interval,
        bool $enableAlerts
    ): void {
        $this->line('=', 80);
        $this->writeln('Queue Monitor');
        $this->line('=', 80);
        $this->writeln("Queues:        " . implode(', ', $queues));
        $this->writeln("Threshold:     {$maxJobs} jobs");
        $this->writeln("Mode:          " . ($interval > 0 ? "Continuous (every {$interval}s)" : 'One-time'));
        $this->writeln("Alerts:        " . ($enableAlerts ? 'Enabled' : 'Disabled'));
        $this->writeln("Started:       " . now()->toDateTimeString());
        $this->line('=', 80);
        $this->newLine();
    }

    /**
     * Display summary.
     *
     * @param int $checkCount Number of checks performed
     * @return void
     */
    private function displaySummary(int $checkCount): void
    {
        $this->newLine();
        $this->line('=', 80);
        $this->writeln('Queue Monitor Stopped');
        $this->writeln("Total Checks:  {$checkCount}");
        $this->writeln("Stopped at:    " . now()->toDateTimeString());
        $this->line('=', 80);
    }
}
