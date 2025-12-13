<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Realtime;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Realtime\Consumer\ConsumerProcessManager;

/**
 * BrokerConsumerStatusCommand
 *
 * Show detailed status of a specific consumer process.
 *
 * Usage:
 *   php console broker:consumer:status process-id-here
 *   php console broker:consumer:status --stop process-id-here
 *   php console broker:consumer:status --kill process-id-here
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 */
final class BrokerConsumerStatusCommand extends Command
{
    protected string $signature = 'broker:consumer:status
        {process_id? : Process ID to show status for}
        {--stop : Send SIGTERM to gracefully stop the process}
        {--kill : Send SIGKILL to force kill the process}
        {--cleanup : Clean up stale processes}
        {--clear-all : Clear ALL process data (use with caution)}';

    protected string $description = 'Show detailed status of a consumer process';

    public function __construct(
        private readonly ConsumerProcessManager $processManager
    ) {}

    public function handle(): int
    {
        // Clear all mode
        if ($this->option('clear-all')) {
            return $this->runClearAll();
        }

        // Cleanup mode
        if ($this->option('cleanup')) {
            return $this->runCleanup();
        }

        $processId = $this->argument('process_id');

        if (empty($processId)) {
            $this->error("Process ID is required.");
            $this->newLine();
            $this->showRecentProcesses();
            return 1;
        }

        // Get process data
        $process = $this->processManager->getProcessData($processId);

        if ($process === null) {
            $this->error("Process [{$processId}] not found.");
            return 1;
        }

        // Handle stop/kill actions
        if ($this->option('stop')) {
            return $this->stopProcess($processId);
        }

        if ($this->option('kill')) {
            return $this->killProcess($processId);
        }

        // Show detailed status
        $this->showDetailedStatus($process);

        return 0;
    }

    private function showDetailedStatus(array $process): void
    {
        $this->newLine();
        $this->info("╔════════════════════════════════════════════════════════════════════════════╗");
        $this->info("║                        Consumer Process Details                             ║");
        $this->info("╚════════════════════════════════════════════════════════════════════════════╝");
        $this->newLine();

        // Basic Info
        $this->info("Basic Information:");
        $this->writeln("  Process ID:    {$process['id']}");
        $this->writeln("  Handler:       {$process['handler']}");
        $this->writeln("  Driver:        {$process['driver']}");
        $this->writeln("  Status:        " . $this->formatStatus($process['status'] ?? 'unknown'));
        $this->writeln("  OS PID:        {$process['pid']}");
        $this->writeln("  Hostname:      {$process['hostname']}");

        // Channels
        $this->newLine();
        $this->info("Subscribed Channels:");
        $channels = $process['channels'] ?? [];
        foreach ($channels as $channel) {
            $this->writeln("  - {$channel}");
        }

        // Timing
        $this->newLine();
        $this->info("Timing:");
        $startedAt = $process['started_at'] ?? 0;
        $lastHeartbeat = $process['last_heartbeat'] ?? 0;
        $stoppedAt = $process['stopped_at'] ?? null;

        $this->writeln("  Started At:      " . $this->formatTimestamp($startedAt));
        $this->writeln("  Last Heartbeat:  " . $this->formatTimestamp($lastHeartbeat) . " (" . $this->formatAgo($lastHeartbeat) . ")");

        if ($stoppedAt) {
            $this->writeln("  Stopped At:      " . $this->formatTimestamp($stoppedAt));
        }

        $uptime = microtime(true) - $startedAt;
        $this->writeln("  Uptime:          " . $this->formatDuration($uptime));

        // Metrics
        $this->newLine();
        $this->info("Metrics:");
        $messageCount = $process['message_count'] ?? 0;
        $errorCount = $process['error_count'] ?? 0;
        $errorRate = $messageCount > 0 ? round(($errorCount / $messageCount) * 100, 2) : 0;
        $throughput = $uptime > 0 ? round($messageCount / $uptime, 2) : 0;

        $this->writeln("  Messages:        {$messageCount}");
        $this->writeln("  Errors:          {$errorCount}");
        $this->writeln("  Error Rate:      {$errorRate}%");
        $this->writeln("  Throughput:      {$throughput} msg/s");

        // Current state
        if (isset($process['current_channel'])) {
            $this->writeln("  Current Channel: {$process['current_channel']}");
        }

        // Error info (if failed)
        if (($process['status'] ?? '') === ConsumerProcessManager::STATUS_FAILED) {
            $this->newLine();
            $this->error("Error Information:");
            $this->writeln("  Error:           " . ($process['error'] ?? 'Unknown'));
            if (isset($process['exception_class'])) {
                $this->writeln("  Exception:       {$process['exception_class']}");
            }
        }

        // Metadata
        if (!empty($process['metadata'])) {
            $this->newLine();
            $this->info("Metadata:");
            foreach ($process['metadata'] as $key => $value) {
                $displayValue = is_array($value) ? json_encode($value) : $value;
                $this->writeln("  {$key}: {$displayValue}");
            }
        }

        // Health check
        $this->newLine();
        $this->info("Health Check:");
        $isAlive = $this->processManager->isProcessAlive($process);
        $osRunning = $this->processManager->isOsProcessRunning($process['pid'] ?? 0);

        $this->writeln("  Heartbeat OK:    " . ($isAlive ? '<fg=green>Yes</>' : '<fg=red>No</>'));
        $this->writeln("  OS Process:      " . ($osRunning ? '<fg=green>Running</>' : '<fg=yellow>Not found</>'));
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            ConsumerProcessManager::STATUS_RUNNING => '<fg=green>Running</>',
            ConsumerProcessManager::STATUS_STARTING => '<fg=cyan>Starting</>',
            ConsumerProcessManager::STATUS_STOPPING => '<fg=yellow>Stopping</>',
            ConsumerProcessManager::STATUS_STOPPED => '<fg=gray>Stopped</>',
            ConsumerProcessManager::STATUS_FAILED => '<fg=red>Failed</>',
            default => $status,
        };
    }

    private function formatTimestamp(float $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'N/A';
        }
        return date('Y-m-d H:i:s', (int) $timestamp);
    }

    private function formatAgo(float $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'N/A';
        }

        $seconds = microtime(true) - $timestamp;

        if ($seconds < 60) {
            return round($seconds) . 's ago';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'm ago';
        } else {
            return round($seconds / 3600, 1) . 'h ago';
        }
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds) . ' seconds';
        } elseif ($seconds < 3600) {
            $m = floor($seconds / 60);
            $s = $seconds % 60;
            return "{$m}m " . round($s) . "s";
        } elseif ($seconds < 86400) {
            $h = floor($seconds / 3600);
            $m = floor(($seconds % 3600) / 60);
            return "{$h}h {$m}m";
        } else {
            $d = floor($seconds / 86400);
            $h = floor(($seconds % 86400) / 3600);
            return "{$d}d {$h}h";
        }
    }

    private function stopProcess(string $processId): int
    {
        $this->info("Sending SIGTERM to process [{$processId}]...");

        if ($this->processManager->signalStop($processId)) {
            $this->success("Stop signal sent successfully.");
            $this->writeln("Process should stop gracefully within a few seconds.");
            return 0;
        }

        $this->error("Failed to send stop signal.");
        return 1;
    }

    private function killProcess(string $processId): int
    {
        $this->warn("Sending SIGKILL to process [{$processId}]...");

        if ($this->processManager->forceKill($processId)) {
            $this->success("Process killed successfully.");
            return 0;
        }

        $this->error("Failed to kill process.");
        return 1;
    }

    private function runCleanup(): int
    {
        $this->info("Cleaning up stale processes...");

        $cleaned = $this->processManager->cleanup(true, 3600);

        $this->success("Cleaned up {$cleaned} stale process(es).");
        return 0;
    }

    private function runClearAll(): int
    {
        $this->warn("This will KILL all running consumer processes and clear ALL process data.");

        if (!$this->confirm("Are you sure you want to continue?")) {
            $this->info("Operation cancelled.");
            return 0;
        }

        $this->info("Killing consumer processes...");
        $killed = $this->processManager->clearAll(killProcesses: true);

        if ($killed > 0) {
            $this->success("Killed <fg=cyan>{$killed}</> consumer process(es).");
        }
        $this->success("All consumer process data has been cleared.");
        return 0;
    }

    private function showRecentProcesses(): void
    {
        $processes = $this->processManager->getRunningProcesses(true);

        if (empty($processes)) {
            $this->writeln("No processes found.");
            return;
        }

        $this->info("Recent processes:");
        $this->newLine();

        // Show at most 10 recent processes
        $recent = array_slice($processes, 0, 10);

        foreach ($recent as $process) {
            $id = $process['id'] ?? 'unknown';
            $handler = $process['handler'] ?? 'unknown';
            $status = $process['status'] ?? 'unknown';

            $statusColor = match ($status) {
                ConsumerProcessManager::STATUS_RUNNING => 'green',
                ConsumerProcessManager::STATUS_FAILED => 'red',
                default => 'gray',
            };

            $this->writeln("  <fg={$statusColor}>[{$status}]</> {$handler}");
            $this->writeln("    ID: {$id}");
        }

        $this->newLine();
        $this->writeln("Use: php console broker:consumer:status <process_id>");
    }
}
