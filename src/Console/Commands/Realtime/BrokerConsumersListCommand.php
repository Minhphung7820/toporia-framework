<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Realtime;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Realtime\Consumer\ConsumerHandlerRegistry;
use Toporia\Framework\Realtime\Consumer\ConsumerProcessManager;

/**
 * BrokerConsumersListCommand
 *
 * List all running consumer processes with their status.
 *
 * Usage:
 *   php console broker:consumers                      # List all consumers
 *   php console broker:consumers --driver=rabbitmq   # Filter by driver
 *   php console broker:consumers --handler=SendOrder # Filter by handler
 *   php console broker:consumers --all               # Include stopped/failed
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 */
final class BrokerConsumersListCommand extends Command
{
    protected string $signature = 'broker:consumers
        {--driver= : Filter by driver (redis, rabbitmq, kafka)}
        {--handler= : Filter by handler name}
        {--all : Include stopped and failed processes}
        {--stats : Show statistics only}';

    protected string $description = 'List all running consumer processes';

    public function __construct(
        private readonly ConsumerProcessManager $processManager,
        private readonly ConsumerHandlerRegistry $registry
    ) {}

    public function handle(): int
    {
        $driver = $this->option('driver');
        $handler = $this->option('handler');
        $includeAll = (bool) $this->option('all');
        $statsOnly = (bool) $this->option('stats');

        // Show statistics only
        if ($statsOnly) {
            $this->showStatistics();
            return 0;
        }

        // Get processes
        $processes = $this->processManager->getRunningProcesses($includeAll);

        // Apply filters
        if ($driver) {
            $processes = array_filter($processes, fn($p) => ($p['driver'] ?? '') === $driver);
        }
        if ($handler) {
            $processes = array_filter($processes, fn($p) => ($p['handler'] ?? '') === $handler);
        }

        $processes = array_values($processes);

        // Display header
        $this->displayHeader();

        if (empty($processes)) {
            $this->warn("No consumer processes found.");
            $this->newLine();
            $this->info("Start a consumer with:");
            $this->writeln("  php console broker:consume --handler=HandlerName --driver=rabbitmq");
            return 0;
        }

        // Display processes table
        $this->displayProcessesTable($processes);

        // Show statistics summary
        $this->newLine();
        $this->showStatistics();

        return 0;
    }

    private function displayHeader(): void
    {
        $this->newLine();
        $this->info("╔════════════════════════════════════════════════════════════════════════════╗");
        $this->info("║                         Consumer Processes                                  ║");
        $this->info("╚════════════════════════════════════════════════════════════════════════════╝");
        $this->newLine();
    }

    private function displayProcessesTable(array $processes): void
    {
        // Table header
        $this->writeln(sprintf(
            "%-20s %-12s %-10s %-8s %-8s %-10s %-12s",
            "Handler",
            "Driver",
            "Status",
            "PID",
            "Msgs",
            "Errors",
            "Uptime"
        ));
        $this->writeln(str_repeat("-", 85));

        foreach ($processes as $process) {
            $handler = $process['handler'] ?? 'Unknown';
            $driver = $process['driver'] ?? 'Unknown';
            $statusRaw = $process['status'] ?? 'unknown';
            $isAlive = $process['is_alive'] ?? false;
            $pid = $process['pid'] ?? 0;
            $msgs = $process['message_count'] ?? 0;
            $errors = $process['error_count'] ?? 0;
            $uptime = $this->formatUptime($process['uptime'] ?? 0);

            // Format status with fixed width BEFORE adding color
            $statusText = $this->getStatusText($statusRaw, $isAlive);
            $statusColored = $this->formatStatus($statusRaw, $isAlive);

            // Build row with manual padding to avoid sprintf messing up color tags
            $row = sprintf("%-20s %-12s ", $this->truncate($handler, 20), $driver);
            $row .= $statusColored . str_repeat(' ', max(0, 10 - strlen($statusText)));
            $row .= sprintf("%-8d %-8d %-10d %-12s", $pid, $msgs, $errors, $uptime);

            $this->writeln($row);
        }
    }

    private function getStatusText(string $status, bool $isAlive): string
    {
        return match ($status) {
            ConsumerProcessManager::STATUS_RUNNING => $isAlive ? 'running' : 'dead?',
            ConsumerProcessManager::STATUS_STARTING => 'starting',
            ConsumerProcessManager::STATUS_STOPPING => 'stopping',
            ConsumerProcessManager::STATUS_STOPPED => 'stopped',
            ConsumerProcessManager::STATUS_FAILED => 'failed',
            default => $status,
        };
    }

    private function formatStatus(string $status, bool $isAlive): string
    {
        return match ($status) {
            ConsumerProcessManager::STATUS_RUNNING => $isAlive ? '<fg=green>running</>' : '<fg=yellow>dead?</>',
            ConsumerProcessManager::STATUS_STARTING => '<fg=cyan>starting</>',
            ConsumerProcessManager::STATUS_STOPPING => '<fg=yellow>stopping</>',
            ConsumerProcessManager::STATUS_STOPPED => '<fg=gray>stopped</>',
            ConsumerProcessManager::STATUS_FAILED => '<fg=red>failed</>',
            default => $status,
        };
    }

    private function formatUptime(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds) . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        } elseif ($seconds < 86400) {
            return round($seconds / 3600, 1) . 'h';
        } else {
            return round($seconds / 86400, 1) . 'd';
        }
    }

    private function truncate(string $str, int $maxLen): string
    {
        if (strlen($str) <= $maxLen) {
            return $str;
        }
        return substr($str, 0, $maxLen - 3) . '...';
    }

    private function showStatistics(): void
    {
        $stats = $this->processManager->getStatistics();

        $this->info("Statistics:");
        $this->writeln("  Total processes:   {$stats['total']}");
        $this->writeln("  Running:           <fg=green>{$stats['running']}</>");
        $this->writeln("  Stopped:           <fg=gray>{$stats['stopped']}</>");
        $this->writeln("  Failed:            <fg=red>{$stats['failed']}</>");
        $this->writeln("  Total messages:    {$stats['total_messages']}");
        $this->writeln("  Total errors:      {$stats['total_errors']}");

        if (!empty($stats['by_driver'])) {
            $this->newLine();
            $this->info("By Driver:");
            foreach ($stats['by_driver'] as $driver => $count) {
                $this->writeln("  {$driver}: {$count}");
            }
        }

        if (!empty($stats['by_handler'])) {
            $this->newLine();
            $this->info("By Handler:");
            foreach ($stats['by_handler'] as $handler => $count) {
                $this->writeln("  {$handler}: {$count}");
            }
        }
    }
}
