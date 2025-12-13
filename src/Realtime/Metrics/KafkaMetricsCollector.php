<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Metrics;

/**
 * Class KafkaMetricsCollector
 *
 * Comprehensive metrics collector for Kafka broker monitoring.
 * Provides real-time statistics for performance monitoring and alerting.
 *
 * Metrics categories:
 * - Producer: throughput, latency, batch size, delivery rate
 * - Consumer: lag, throughput, processing time, rebalances
 * - Connection: health, reconnects, errors
 * - Memory: queue size, buffer usage
 *
 * Export formats:
 * - Prometheus (text format)
 * - JSON (for API/dashboard)
 * - StatsD (for DataDog, Graphite)
 *
 * Usage:
 *   $collector = KafkaMetricsCollector::getInstance();
 *   $collector->recordPublish('events.stream', 1.5, true);
 *   $collector->recordConsume('events.stream', 100, 50.0);
 *   echo $collector->toPrometheus();
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class KafkaMetricsCollector
{
    private static ?self $instance = null;

    /**
     * @var array<string, array{count: int, total_time: float, failures: int, last_time: float}>
     */
    private array $producerMetrics = [];

    /**
     * @var array<string, array{count: int, total_time: float, failures: int, last_time: float}>
     */
    private array $consumerMetrics = [];

    /**
     * @var array<string, int> Error counts by type
     */
    private array $errorCounts = [];

    /**
     * @var array<string, float> Latency histogram buckets
     */
    private array $latencyBuckets = [];

    /**
     * @var array{connects: int, disconnects: int, reconnects: int, last_connect: float}
     */
    private array $connectionMetrics;

    /**
     * @var array{queue_size: int, buffer_bytes: int, pending_messages: int}
     */
    private array $memoryMetrics;

    /**
     * @var float Start time for uptime calculation
     */
    private float $startTime;

    /**
     * @var string Metric prefix for namespacing
     */
    private string $prefix;

    /**
     * @var array<string, string> Labels for all metrics
     */
    private array $labels;

    private function __construct(string $prefix = 'toporia_kafka', array $labels = [])
    {
        $this->prefix = $prefix;
        $this->labels = $labels;
        $this->startTime = microtime(true);
        $this->connectionMetrics = [
            'connects' => 0,
            'disconnects' => 0,
            'reconnects' => 0,
            'last_connect' => 0,
        ];
        $this->memoryMetrics = [
            'queue_size' => 0,
            'buffer_bytes' => 0,
            'pending_messages' => 0,
        ];
        $this->initLatencyBuckets();
    }

    /**
     * Get singleton instance.
     */
    public static function getInstance(string $prefix = 'toporia_kafka', array $labels = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($prefix, $labels);
        }

        return self::$instance;
    }

    /**
     * Reset instance (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Initialize latency histogram buckets (in milliseconds).
     */
    private function initLatencyBuckets(): void
    {
        // Buckets: 1ms, 5ms, 10ms, 25ms, 50ms, 100ms, 250ms, 500ms, 1s, 2.5s, 5s, 10s
        $this->latencyBuckets = [
            '0.001' => 0, '0.005' => 0, '0.01' => 0, '0.025' => 0,
            '0.05' => 0, '0.1' => 0, '0.25' => 0, '0.5' => 0,
            '1' => 0, '2.5' => 0, '5' => 0, '10' => 0,
            '+Inf' => 0,
        ];
    }

    /**
     * Record a publish operation.
     *
     * @param string $topic Topic name
     * @param float $latencyMs Latency in milliseconds
     * @param bool $success Whether publish succeeded
     */
    public function recordPublish(string $topic, float $latencyMs, bool $success): void
    {
        if (!isset($this->producerMetrics[$topic])) {
            $this->producerMetrics[$topic] = [
                'count' => 0,
                'total_time' => 0,
                'failures' => 0,
                'last_time' => 0,
            ];
        }

        $this->producerMetrics[$topic]['count']++;
        $this->producerMetrics[$topic]['total_time'] += $latencyMs;
        $this->producerMetrics[$topic]['last_time'] = microtime(true);

        if (!$success) {
            $this->producerMetrics[$topic]['failures']++;
        }

        $this->recordLatency($latencyMs);
    }

    /**
     * Record a batch publish operation.
     *
     * @param string $topic Topic name
     * @param int $batchSize Number of messages in batch
     * @param float $latencyMs Total latency in milliseconds
     * @param int $failures Number of failed messages
     */
    public function recordBatchPublish(string $topic, int $batchSize, float $latencyMs, int $failures = 0): void
    {
        if (!isset($this->producerMetrics[$topic])) {
            $this->producerMetrics[$topic] = [
                'count' => 0,
                'total_time' => 0,
                'failures' => 0,
                'last_time' => 0,
            ];
        }

        $this->producerMetrics[$topic]['count'] += $batchSize;
        $this->producerMetrics[$topic]['total_time'] += $latencyMs;
        $this->producerMetrics[$topic]['failures'] += $failures;
        $this->producerMetrics[$topic]['last_time'] = microtime(true);

        // Record average latency per message
        if ($batchSize > 0) {
            $this->recordLatency($latencyMs / $batchSize);
        }
    }

    /**
     * Record consume operation.
     *
     * @param string $topic Topic name
     * @param int $messageCount Messages consumed
     * @param float $processingTimeMs Processing time in milliseconds
     * @param int $failures Failed messages
     */
    public function recordConsume(string $topic, int $messageCount, float $processingTimeMs, int $failures = 0): void
    {
        if (!isset($this->consumerMetrics[$topic])) {
            $this->consumerMetrics[$topic] = [
                'count' => 0,
                'total_time' => 0,
                'failures' => 0,
                'last_time' => 0,
            ];
        }

        $this->consumerMetrics[$topic]['count'] += $messageCount;
        $this->consumerMetrics[$topic]['total_time'] += $processingTimeMs;
        $this->consumerMetrics[$topic]['failures'] += $failures;
        $this->consumerMetrics[$topic]['last_time'] = microtime(true);
    }

    /**
     * Record consumer lag.
     *
     * @param string $topic Topic name
     * @param int $partition Partition number
     * @param int $lag Consumer lag (messages behind)
     */
    public function recordLag(string $topic, int $partition, int $lag): void
    {
        $key = "{$topic}:{$partition}:lag";
        $this->memoryMetrics[$key] = $lag;
    }

    /**
     * Record connection event.
     *
     * @param string $event Event type (connect, disconnect, reconnect)
     */
    public function recordConnection(string $event): void
    {
        switch ($event) {
            case 'connect':
                $this->connectionMetrics['connects']++;
                $this->connectionMetrics['last_connect'] = microtime(true);
                break;
            case 'disconnect':
                $this->connectionMetrics['disconnects']++;
                break;
            case 'reconnect':
                $this->connectionMetrics['reconnects']++;
                $this->connectionMetrics['last_connect'] = microtime(true);
                break;
        }
    }

    /**
     * Record error.
     *
     * @param string $errorType Error type/code
     */
    public function recordError(string $errorType): void
    {
        if (!isset($this->errorCounts[$errorType])) {
            $this->errorCounts[$errorType] = 0;
        }
        $this->errorCounts[$errorType]++;
    }

    /**
     * Record latency in histogram buckets.
     *
     * @param float $latencyMs Latency in milliseconds
     */
    private function recordLatency(float $latencyMs): void
    {
        $latencySec = $latencyMs / 1000;

        foreach ($this->latencyBuckets as $bucket => $count) {
            if ($bucket === '+Inf' || $latencySec <= (float) $bucket) {
                $this->latencyBuckets[$bucket]++;
            }
        }
    }

    /**
     * Update memory metrics.
     *
     * @param int $queueSize Current queue size
     * @param int $bufferBytes Buffer usage in bytes
     * @param int $pendingMessages Pending message count
     */
    public function updateMemoryMetrics(int $queueSize, int $bufferBytes, int $pendingMessages): void
    {
        $this->memoryMetrics['queue_size'] = $queueSize;
        $this->memoryMetrics['buffer_bytes'] = $bufferBytes;
        $this->memoryMetrics['pending_messages'] = $pendingMessages;
    }

    /**
     * Get all metrics as array.
     *
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        $uptime = microtime(true) - $this->startTime;

        return [
            'uptime_seconds' => round($uptime, 2),
            'producer' => $this->getProducerSummary(),
            'consumer' => $this->getConsumerSummary(),
            'connections' => $this->connectionMetrics,
            'errors' => $this->errorCounts,
            'memory' => $this->memoryMetrics,
            'latency_histogram' => $this->latencyBuckets,
        ];
    }

    /**
     * Get producer summary.
     *
     * @return array<string, array{messages: int, avg_latency_ms: float, failures: int, throughput_per_sec: float}>
     */
    public function getProducerSummary(): array
    {
        $summary = [];
        $now = microtime(true);

        foreach ($this->producerMetrics as $topic => $metrics) {
            $elapsed = $now - $this->startTime;
            $avgLatency = $metrics['count'] > 0 ? $metrics['total_time'] / $metrics['count'] : 0;

            $summary[$topic] = [
                'messages' => $metrics['count'],
                'avg_latency_ms' => round($avgLatency, 2),
                'failures' => $metrics['failures'],
                'throughput_per_sec' => $elapsed > 0 ? round($metrics['count'] / $elapsed, 2) : 0,
            ];
        }

        return $summary;
    }

    /**
     * Get consumer summary.
     *
     * @return array<string, array{messages: int, avg_processing_ms: float, failures: int, throughput_per_sec: float}>
     */
    public function getConsumerSummary(): array
    {
        $summary = [];
        $now = microtime(true);

        foreach ($this->consumerMetrics as $topic => $metrics) {
            $elapsed = $now - $this->startTime;
            $avgTime = $metrics['count'] > 0 ? $metrics['total_time'] / $metrics['count'] : 0;

            $summary[$topic] = [
                'messages' => $metrics['count'],
                'avg_processing_ms' => round($avgTime, 2),
                'failures' => $metrics['failures'],
                'throughput_per_sec' => $elapsed > 0 ? round($metrics['count'] / $elapsed, 2) : 0,
            ];
        }

        return $summary;
    }

    /**
     * Export metrics in Prometheus text format.
     *
     * @return string
     */
    public function toPrometheus(): string
    {
        $lines = [];
        $labels = $this->formatLabels($this->labels);

        // Uptime
        $uptime = microtime(true) - $this->startTime;
        $lines[] = "# HELP {$this->prefix}_uptime_seconds Broker uptime in seconds";
        $lines[] = "# TYPE {$this->prefix}_uptime_seconds gauge";
        $lines[] = "{$this->prefix}_uptime_seconds{$labels} {$uptime}";

        // Producer metrics
        $lines[] = "# HELP {$this->prefix}_producer_messages_total Total messages produced";
        $lines[] = "# TYPE {$this->prefix}_producer_messages_total counter";
        foreach ($this->producerMetrics as $topic => $metrics) {
            $topicLabel = $this->formatLabels(array_merge($this->labels, ['topic' => $topic]));
            $lines[] = "{$this->prefix}_producer_messages_total{$topicLabel} {$metrics['count']}";
        }

        $lines[] = "# HELP {$this->prefix}_producer_failures_total Total failed publishes";
        $lines[] = "# TYPE {$this->prefix}_producer_failures_total counter";
        foreach ($this->producerMetrics as $topic => $metrics) {
            $topicLabel = $this->formatLabels(array_merge($this->labels, ['topic' => $topic]));
            $lines[] = "{$this->prefix}_producer_failures_total{$topicLabel} {$metrics['failures']}";
        }

        // Consumer metrics
        $lines[] = "# HELP {$this->prefix}_consumer_messages_total Total messages consumed";
        $lines[] = "# TYPE {$this->prefix}_consumer_messages_total counter";
        foreach ($this->consumerMetrics as $topic => $metrics) {
            $topicLabel = $this->formatLabels(array_merge($this->labels, ['topic' => $topic]));
            $lines[] = "{$this->prefix}_consumer_messages_total{$topicLabel} {$metrics['count']}";
        }

        // Connection metrics
        $lines[] = "# HELP {$this->prefix}_connections_total Total connection events";
        $lines[] = "# TYPE {$this->prefix}_connections_total counter";
        $lines[] = "{$this->prefix}_connections_total{$this->formatLabels(array_merge($this->labels, ['type' => 'connect']))} {$this->connectionMetrics['connects']}";
        $lines[] = "{$this->prefix}_connections_total{$this->formatLabels(array_merge($this->labels, ['type' => 'disconnect']))} {$this->connectionMetrics['disconnects']}";
        $lines[] = "{$this->prefix}_connections_total{$this->formatLabels(array_merge($this->labels, ['type' => 'reconnect']))} {$this->connectionMetrics['reconnects']}";

        // Error counts
        $lines[] = "# HELP {$this->prefix}_errors_total Total errors by type";
        $lines[] = "# TYPE {$this->prefix}_errors_total counter";
        foreach ($this->errorCounts as $type => $count) {
            $errorLabel = $this->formatLabels(array_merge($this->labels, ['error_type' => $type]));
            $lines[] = "{$this->prefix}_errors_total{$errorLabel} {$count}";
        }

        // Memory metrics
        $lines[] = "# HELP {$this->prefix}_queue_size Current queue size";
        $lines[] = "# TYPE {$this->prefix}_queue_size gauge";
        $lines[] = "{$this->prefix}_queue_size{$labels} {$this->memoryMetrics['queue_size']}";

        $lines[] = "# HELP {$this->prefix}_pending_messages Pending messages awaiting delivery";
        $lines[] = "# TYPE {$this->prefix}_pending_messages gauge";
        $lines[] = "{$this->prefix}_pending_messages{$labels} {$this->memoryMetrics['pending_messages']}";

        // Latency histogram
        $lines[] = "# HELP {$this->prefix}_publish_latency_seconds Publish latency histogram";
        $lines[] = "# TYPE {$this->prefix}_publish_latency_seconds histogram";
        $cumulative = 0;
        foreach ($this->latencyBuckets as $bucket => $count) {
            $cumulative += $count;
            $bucketLabel = $this->formatLabels(array_merge($this->labels, ['le' => $bucket]));
            $lines[] = "{$this->prefix}_publish_latency_seconds_bucket{$bucketLabel} {$cumulative}";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Export metrics as JSON.
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->getAll(), JSON_PRETTY_PRINT) ?: '{}';
    }

    /**
     * Format labels for Prometheus.
     *
     * @param array<string, string> $labels
     * @return string
     */
    private function formatLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }

        $parts = [];
        foreach ($labels as $key => $value) {
            $escapedValue = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $value);
            $parts[] = "{$key}=\"{$escapedValue}\"";
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Get total messages produced.
     *
     * @return int
     */
    public function getTotalProduced(): int
    {
        return array_sum(array_column($this->producerMetrics, 'count'));
    }

    /**
     * Get total messages consumed.
     *
     * @return int
     */
    public function getTotalConsumed(): int
    {
        return array_sum(array_column($this->consumerMetrics, 'count'));
    }

    /**
     * Get total errors.
     *
     * @return int
     */
    public function getTotalErrors(): int
    {
        return array_sum($this->errorCounts);
    }
}
