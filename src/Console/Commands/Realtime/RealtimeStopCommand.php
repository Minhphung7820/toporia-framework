<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Realtime;

use Toporia\Framework\Console\Command;

/**
 * Class RealtimeStopCommand
 *
 * Stop the realtime server by killing all related processes.
 *
 * Usage:
 * - php console realtime:stop              # Stop all realtime servers
 * - php console realtime:stop --port=6001  # Stop server on specific port
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
final class RealtimeStopCommand extends Command
{
    protected string $signature = 'realtime:stop';
    protected string $description = 'Stop the realtime server';

    /**
     * {@inheritdoc}
     */
    public function handle(): int
    {
        $port = $this->option('port');

        $this->newLine();
        $this->info("╔════════════════════════════════════════════════════════════╗");
        $this->info("║                Stop Realtime Server                         ║");
        $this->info("╚════════════════════════════════════════════════════════════╝");
        $this->newLine();

        $killed = 0;

        // Method 1: Kill by port if specified
        if ($port) {
            $this->writeln("Stopping server on port <fg=cyan>{$port}</>...");
            $killed += $this->killByPort((int) $port);
        }

        // Method 2: Kill by process name
        $killed += $this->killByProcessName('realtime:serve');

        // Method 3: Kill default port 6001 if no specific port given
        if (!$port) {
            $killed += $this->killByPort(6001);
        }

        if ($killed > 0) {
            $this->success("Stopped {$killed} process(es)");
        } else {
            $this->warn('No realtime server processes found');
        }

        return 0;
    }

    /**
     * Kill processes by port number.
     *
     * @param int $port
     * @return int Number of killed processes
     */
    private function killByPort(int $port): int
    {
        $killed = 0;

        // Get PIDs listening on port
        $output = [];
        exec("lsof -i :{$port} 2>/dev/null | awk 'NR>1 {print \$2}' | sort -u", $output);

        foreach ($output as $pid) {
            $pid = trim($pid);
            if ($pid && is_numeric($pid)) {
                $this->writeln("  <fg=yellow>→</> Sending SIGTERM to PID <fg=cyan>{$pid}</> on port {$port}");
                posix_kill((int) $pid, SIGTERM);
                $killed++;
            }
        }

        // Wait a moment then force kill if still running
        if ($killed > 0) {
            usleep(500000); // 500ms

            exec("lsof -i :{$port} 2>/dev/null | awk 'NR>1 {print \$2}' | sort -u", $stillRunning);
            foreach ($stillRunning as $pid) {
                $pid = trim($pid);
                if ($pid && is_numeric($pid)) {
                    $this->writeln("  <fg=red>✗</> Force killing PID <fg=cyan>{$pid}</>...");
                    posix_kill((int) $pid, SIGKILL);
                }
            }
        }

        return $killed;
    }

    /**
     * Kill processes by name pattern.
     *
     * @param string $pattern
     * @return int Number of killed processes
     */
    private function killByProcessName(string $pattern): int
    {
        $killed = 0;

        // Get PIDs matching pattern
        $output = [];
        exec("pgrep -f '{$pattern}' 2>/dev/null", $output);

        foreach ($output as $pid) {
            $pid = trim($pid);
            // Skip our own process
            if ($pid && is_numeric($pid) && (int) $pid !== getmypid()) {
                $this->writeln("  <fg=yellow>→</> Sending SIGTERM to PID <fg=cyan>{$pid}</> (pattern: {$pattern})");
                posix_kill((int) $pid, SIGTERM);
                $killed++;
            }
        }

        return $killed;
    }
}
