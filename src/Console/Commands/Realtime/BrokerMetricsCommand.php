<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Realtime;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Realtime\Brokers\KafkaBroker;
use Toporia\Framework\Realtime\Metrics\KafkaMetricsCollector;
use Toporia\Framework\Realtime\RealtimeManager;

/**
 * Class BrokerMetricsCommand
 *
 * Display broker metrics for monitoring and debugging.
 * Supports Prometheus and JSON output formats.
 *
 * Usage:
 *   php console broker:metrics                    # Show all metrics
 *   php console broker:metrics --format=prometheus  # Prometheus format
 *   php console broker:metrics --format=json       # JSON format
 *   php console broker:metrics --watch            # Live monitoring
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class BrokerMetricsCommand extends Command
{
    protected string $signature = 'broker:metrics
        {--driver=kafka : Broker driver (kafka, redis, rabbitmq)}
        {--format=table : Output format (table, json, prometheus)}
        {--watch : Watch metrics in real-time}
        {--interval=5 : Watch interval in seconds}';

    protected string $description = 'Display broker metrics for monitoring';

    public function handle(): int
    {
        $driver = $this->option('driver') ?? 'kafka';
        $format = $this->option('format') ?? 'table';
        $watch = (bool) $this->option('watch');
        $interval = (int) ($this->option('interval') ?? 5);

        if ($watch) {
            return $this->watchMetrics($driver, $format, $interval);
        }

        return $this->showMetrics($driver, $format);
    }

    /**
     * Show metrics once.
     */
    private function showMetrics(string $driver, string $format): int
    {
        $metrics = $this->collectMetrics($driver);

        if ($metrics === null) {
            $this->error("No metrics available for driver: {$driver}");
            return 1;
        }

        switch ($format) {
            case 'prometheus':
                $this->line($this->formatPrometheus($metrics, $driver));
                break;

            case 'json':
                $this->line(json_encode($metrics, JSON_PRETTY_PRINT));
                break;

            default:
                $this->displayTable($metrics, $driver);
        }

        return 0;
    }

    /**
     * Watch metrics in real-time.
     */
    private function watchMetrics(string $driver, string $format, int $interval): int
    {
        $this->info("Watching {$driver} metrics (Ctrl+C to stop)...\n");

        // Signal handling
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            $stop = false;
            pcntl_signal(SIGINT, function () use (&$stop) {
                $stop = true;
            });
        } else {
            $stop = false;
        }

        while (!$stop) {
            // Clear screen (ANSI escape code)
            $this->line("\033[2J\033[H");

            $this->info("=== Broker Metrics: {$driver} ===");
            $this->line("Time: " . date('Y-m-d H:i:s') . "\n");

            $this->showMetrics($driver, $format);

            sleep($interval);
        }

        $this->info('Stopped.');
        return 0;
    }

    /**
     * Collect metrics from broker.
     */
    private function collectMetrics(string $driver): ?array
    {
        // Try to get from Kafka metrics collector singleton
        if (str_contains($driver, 'kafka')) {
            $collector = KafkaMetricsCollector::getInstance();
            return $collector->getAll();
        }

        // Try to get from broker directly
        try {
            $realtime = app(RealtimeManager::class);
            $broker = $realtime->broker($driver);

            if ($broker === null) {
                return null;
            }

            // Kafka broker has built-in metrics
            if ($broker instanceof KafkaBroker) {
                return json_decode($broker->getJsonMetrics(), true);
            }

            // Check if broker has healthCheck method
            if (method_exists($broker, 'healthCheck')) {
                $health = $broker->healthCheck();
                return [
                    'status' => $health->status,
                    'message' => $health->message,
                    'latency_ms' => $health->latencyMs,
                    'details' => $health->details,
                ];
            }

            return [
                'connected' => $broker->isConnected(),
                'name' => $broker->getName(),
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Display metrics in table format.
     */
    private function displayTable(array $metrics, string $driver): void
    {
        $this->info("Broker: {$driver}");
        $this->line(str_repeat('-', 50));

        // Uptime
        if (isset($metrics['uptime_seconds'])) {
            $this->line(sprintf("Uptime: %.2f seconds", $metrics['uptime_seconds']));
        }

        // Producer metrics
        if (!empty($metrics['producer'])) {
            $this->newLine();
            $this->info('Producer Metrics:');
            foreach ($metrics['producer'] as $topic => $data) {
                $this->line(sprintf(
                    "  %s: %d messages, %.2fms avg, %d failures, %.2f/s",
                    $topic,
                    $data['messages'] ?? 0,
                    $data['avg_latency_ms'] ?? 0,
                    $data['failures'] ?? 0,
                    $data['throughput_per_sec'] ?? 0
                ));
            }
        }

        // Consumer metrics
        if (!empty($metrics['consumer'])) {
            $this->newLine();
            $this->info('Consumer Metrics:');
            foreach ($metrics['consumer'] as $topic => $data) {
                $this->line(sprintf(
                    "  %s: %d messages, %.2fms avg, %d failures, %.2f/s",
                    $topic,
                    $data['messages'] ?? 0,
                    $data['avg_processing_ms'] ?? 0,
                    $data['failures'] ?? 0,
                    $data['throughput_per_sec'] ?? 0
                ));
            }
        }

        // Connection metrics
        if (!empty($metrics['connections'])) {
            $this->newLine();
            $this->info('Connection Metrics:');
            $conn = $metrics['connections'];
            $this->line(sprintf(
                "  Connects: %d, Disconnects: %d, Reconnects: %d",
                $conn['connects'] ?? 0,
                $conn['disconnects'] ?? 0,
                $conn['reconnects'] ?? 0
            ));
        }

        // Error counts
        if (!empty($metrics['errors'])) {
            $this->newLine();
            $this->info('Errors:');
            foreach ($metrics['errors'] as $type => $count) {
                $this->line(sprintf("  %s: %d", $type, $count));
            }
        }

        // Memory metrics
        if (!empty($metrics['memory'])) {
            $this->newLine();
            $this->info('Memory:');
            $mem = $metrics['memory'];
            $this->line(sprintf(
                "  Queue: %d, Buffer: %d bytes, Pending: %d",
                $mem['queue_size'] ?? 0,
                $mem['buffer_bytes'] ?? 0,
                $mem['pending_messages'] ?? 0
            ));
        }

        $this->newLine();
    }

    /**
     * Format metrics for Prometheus.
     */
    private function formatPrometheus(array $metrics, string $driver): string
    {
        // If using Kafka HP broker, it has built-in Prometheus export
        if (str_contains($driver, 'kafka')) {
            try {
                $realtime = app(RealtimeManager::class);
                $broker = $realtime->broker($driver);

                if ($broker instanceof KafkaBroker) {
                    return $broker->getPrometheusMetrics();
                }
            } catch (\Throwable) {
                // Fall through to manual formatting
            }

            $collector = KafkaMetricsCollector::getInstance();
            return $collector->toPrometheus();
        }

        // Manual Prometheus format for other brokers
        $lines = [];
        $prefix = "toporia_{$driver}";

        if (isset($metrics['uptime_seconds'])) {
            $lines[] = "# HELP {$prefix}_uptime_seconds Broker uptime in seconds";
            $lines[] = "# TYPE {$prefix}_uptime_seconds gauge";
            $lines[] = "{$prefix}_uptime_seconds {$metrics['uptime_seconds']}";
        }

        return implode("\n", $lines) . "\n";
    }
}
