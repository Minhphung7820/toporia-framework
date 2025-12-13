<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Metrics;

/**
 * Middleware Metrics
 *
 * Collects performance metrics for middleware execution.
 *
 * Metrics tracked:
 * - Execution time per middleware
 * - Success/failure rates
 * - Pipeline execution time
 * - Cache hit rates
 *
 * Integration:
 * - Can export to Prometheus, StatsD, CloudWatch
 * - Real-time monitoring dashboards
 * - Alerting on anomalies
 *
 * Performance: <0.1ms overhead per metric
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Metrics
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MiddlewareMetrics
{
    /**
     * Metrics storage.
     *
     * @var array<string, array{count: int, total_time: float, success: int, failure: int}>
     */
    private array $metrics = [];

    /**
     * Pipeline metrics.
     *
     * @var array<string, array{count: int, total_time: float, success: int, failure: int}>
     */
    private array $pipelineMetrics = [];

    /**
     * Counters.
     *
     * @var array<string, int>
     */
    private array $counters = [];

    /**
     * Record middleware execution.
     *
     * @param string $middlewareName Middleware name
     * @param float $duration Execution time in seconds
     * @param bool $success Whether execution was successful
     */
    public function recordMiddlewareExecution(string $middlewareName, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$middlewareName])) {
            $this->metrics[$middlewareName] = [
                'count' => 0,
                'total_time' => 0.0,
                'success' => 0,
                'failure' => 0,
            ];
        }

        $this->metrics[$middlewareName]['count']++;
        $this->metrics[$middlewareName]['total_time'] += $duration;

        if ($success) {
            $this->metrics[$middlewareName]['success']++;
        } else {
            $this->metrics[$middlewareName]['failure']++;
        }
    }

    /**
     * Record pipeline execution.
     *
     * @param string $channelName Channel name
     * @param float $duration Execution time in seconds
     * @param bool $success Whether execution was successful
     */
    public function recordPipelineExecution(string $channelName, float $duration, bool $success): void
    {
        if (!isset($this->pipelineMetrics[$channelName])) {
            $this->pipelineMetrics[$channelName] = [
                'count' => 0,
                'total_time' => 0.0,
                'success' => 0,
                'failure' => 0,
            ];
        }

        $this->pipelineMetrics[$channelName]['count']++;
        $this->pipelineMetrics[$channelName]['total_time'] += $duration;

        if ($success) {
            $this->pipelineMetrics[$channelName]['success']++;
        } else {
            $this->pipelineMetrics[$channelName]['failure']++;
        }
    }

    /**
     * Increment counter.
     *
     * @param string $counter Counter name
     * @param int $amount Amount to increment
     */
    public function increment(string $counter, int $amount = 1): void
    {
        if (!isset($this->counters[$counter])) {
            $this->counters[$counter] = 0;
        }

        $this->counters[$counter] += $amount;
    }

    /**
     * Get middleware statistics.
     *
     * @param string $middlewareName Middleware name
     * @return array{count: int, avg_time: float, success_rate: float}|null
     */
    public function getMiddlewareStats(string $middlewareName): ?array
    {
        if (!isset($this->metrics[$middlewareName])) {
            return null;
        }

        $metric = $this->metrics[$middlewareName];

        return [
            'count' => $metric['count'],
            'avg_time' => $metric['count'] > 0 ? $metric['total_time'] / $metric['count'] : 0.0,
            'success_rate' => $metric['count'] > 0 ? $metric['success'] / $metric['count'] : 0.0,
        ];
    }

    /**
     * Get all middleware statistics.
     *
     * @return array<string, array{count: int, avg_time: float, success_rate: float}>
     */
    public function getAllMiddlewareStats(): array
    {
        $stats = [];

        foreach (array_keys($this->metrics) as $middlewareName) {
            $stats[$middlewareName] = $this->getMiddlewareStats($middlewareName);
        }

        return $stats;
    }

    /**
     * Get pipeline statistics.
     *
     * @param string $channelName Channel name
     * @return array{count: int, avg_time: float, success_rate: float}|null
     */
    public function getPipelineStats(string $channelName): ?array
    {
        if (!isset($this->pipelineMetrics[$channelName])) {
            return null;
        }

        $metric = $this->pipelineMetrics[$channelName];

        return [
            'count' => $metric['count'],
            'avg_time' => $metric['count'] > 0 ? $metric['total_time'] / $metric['count'] : 0.0,
            'success_rate' => $metric['count'] > 0 ? $metric['success'] / $metric['count'] : 0.0,
        ];
    }

    /**
     * Get all pipeline statistics.
     *
     * @return array<string, array{count: int, avg_time: float, success_rate: float}>
     */
    public function getAllPipelineStats(): array
    {
        $stats = [];

        foreach (array_keys($this->pipelineMetrics) as $channelName) {
            $stats[$channelName] = $this->getPipelineStats($channelName);
        }

        return $stats;
    }

    /**
     * Get counter value.
     *
     * @param string $counter Counter name
     * @return int
     */
    public function getCounter(string $counter): int
    {
        return $this->counters[$counter] ?? 0;
    }

    /**
     * Get all counters.
     *
     * @return array<string, int>
     */
    public function getAllCounters(): array
    {
        return $this->counters;
    }

    /**
     * Reset all metrics.
     */
    public function reset(): void
    {
        $this->metrics = [];
        $this->pipelineMetrics = [];
        $this->counters = [];
    }

    /**
     * Export metrics in Prometheus format.
     *
     * @return string
     */
    public function exportPrometheus(): string
    {
        $output = [];

        // Middleware metrics
        $output[] = '# HELP realtime_middleware_executions_total Total middleware executions';
        $output[] = '# TYPE realtime_middleware_executions_total counter';

        foreach ($this->metrics as $name => $metric) {
            $output[] = sprintf(
                'realtime_middleware_executions_total{middleware="%s"} %d',
                $name,
                $metric['count']
            );
        }

        // Middleware success rate
        $output[] = '# HELP realtime_middleware_success_rate Middleware success rate';
        $output[] = '# TYPE realtime_middleware_success_rate gauge';

        foreach ($this->metrics as $name => $metric) {
            $successRate = $metric['count'] > 0 ? $metric['success'] / $metric['count'] : 0.0;
            $output[] = sprintf(
                'realtime_middleware_success_rate{middleware="%s"} %.2f',
                $name,
                $successRate
            );
        }

        // Pipeline metrics
        $output[] = '# HELP realtime_pipeline_executions_total Total pipeline executions';
        $output[] = '# TYPE realtime_pipeline_executions_total counter';

        foreach ($this->pipelineMetrics as $channel => $metric) {
            $output[] = sprintf(
                'realtime_pipeline_executions_total{channel="%s"} %d',
                $channel,
                $metric['count']
            );
        }

        return implode("\n", $output) . "\n";
    }
}
