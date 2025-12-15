<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing\Console;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Routing\Contracts\RouterInterface;
use Toporia\Framework\Routing\RoutePerformanceMonitor;

/**
 * Class RouteStatsCommand
 *
 * Display route performance statistics.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Routing\Console
 * @since       2025-01-15
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RouteStatsCommand extends Command
{
    protected string $signature = 'route:stats {--top=10 : Number of routes to show}';
    protected string $description = 'Display route performance statistics';

    /**
     * @param RouterInterface $router
     */
    public function __construct(
        private RouterInterface $router
    ) {
        parent::__construct();
    }

    /**
     * Execute the command.
     *
     * @return int
     */
    public function handle(): int
    {
        $monitor = $this->router->getPerformanceMonitor();

        if ($monitor === null || !$monitor->isEnabled()) {
            $this->error('Performance monitoring is not enabled.');
            $this->line('');
            $this->line('Enable it in bootstrap/app.php:');
            $this->line('  $monitor = new RoutePerformanceMonitor();');
            $this->line('  $monitor->enable();');
            $this->line('  $router->setPerformanceMonitor($monitor);');
            return 1;
        }

        $summary = $monitor->getSummary();

        if ($summary['total_requests'] === 0) {
            $this->info('No route executions recorded yet.');
            $this->line('Make some requests to see statistics.');
            return 0;
        }

        // Display summary
        $this->line('');
        $this->line('<fg=cyan>━━━ Route Performance Summary ━━━</>');
        $this->line('');
        $this->line(sprintf('  Total Requests:  <fg=yellow>%d</>', $summary['total_requests']));
        $this->line(sprintf('  Avg Time:        <fg=yellow>%.2f ms</>', $summary['avg_time']));
        $this->line(sprintf('  Avg Memory:      <fg=yellow>%s</>', $this->formatBytes($summary['avg_memory'])));
        $this->line(sprintf('  Slowest Route:   <fg=red>%s</>', $summary['slowest'] ?? 'N/A'));
        $this->line(sprintf('  Fastest Route:   <fg=green>%s</>', $summary['fastest'] ?? 'N/A'));
        $this->line('');

        $top = (int) $this->option('top');

        // Display slowest routes
        $slowest = $monitor->getSlowestRoutes($top);
        if (!empty($slowest)) {
            $this->line('<fg=cyan>━━━ Slowest Routes (Top ' . $top . ') ━━━</>');
            $this->line('');

            $rows = [];
            foreach ($slowest as $route => $stats) {
                $rows[] = [
                    $route,
                    sprintf('%.2f ms', $stats['avg_time']),
                    sprintf('%.2f ms', $stats['max_time']),
                    sprintf('%.2f ms', $stats['min_time']),
                    $stats['count'],
                ];
            }

            $this->table(
                ['Route', 'Avg Time', 'Max Time', 'Min Time', 'Requests'],
                $rows
            );
        }

        // Display memory-intensive routes
        $memoryIntensive = $monitor->getMemoryIntensiveRoutes($top);
        if (!empty($memoryIntensive)) {
            $this->line('');
            $this->line('<fg=cyan>━━━ Memory-Intensive Routes (Top ' . $top . ') ━━━</>');
            $this->line('');

            $rows = [];
            foreach ($memoryIntensive as $route => $stats) {
                $rows[] = [
                    $route,
                    $this->formatBytes($stats['avg_memory']),
                    $stats['count'],
                ];
            }

            $this->table(
                ['Route', 'Avg Memory', 'Requests'],
                $rows
            );
        }

        $this->line('');
        $this->info('Performance statistics updated in real-time.');
        $this->line('Run <fg=yellow>route:stats --top=20</> to see more routes.');
        $this->line('');

        return 0;
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
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return sprintf('%.2f %s', $bytes, $units[$pow]);
    }
}
