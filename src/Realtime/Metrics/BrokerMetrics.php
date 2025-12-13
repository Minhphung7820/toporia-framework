<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Metrics;

/**
 * Class BrokerMetrics
 *
 * Metrics collection and reporting for broker operations.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Metrics
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class BrokerMetrics
{
    /**
     * @var array<string, array<string, mixed>> Metrics storage
     */
    private static array $metrics = [];

    /**
     * Record publish operation.
     *
     * @param string $broker Broker name
     * @param string $channel Channel name
     * @param float $durationMs Duration in milliseconds
     * @param bool $success Success flag
     * @return void
     */
    public static function recordPublish(
        string $broker,
        string $channel,
        float $durationMs,
        bool $success
    ): void {
        self::increment($broker, 'publish_total');

        if ($success) {
            self::increment($broker, 'publish_success');
        } else {
            self::increment($broker, 'publish_failed');
        }

        self::recordLatency($broker, 'publish_latency', $durationMs);
    }

    /**
     * Record consume operation.
     *
     * @param string $broker Broker name
     * @param int $messageCount Number of messages consumed
     * @param float $durationMs Duration in milliseconds
     * @return void
     */
    public static function recordConsume(
        string $broker,
        int $messageCount,
        float $durationMs
    ): void {
        self::add($broker, 'consume_messages', $messageCount);
        self::recordLatency($broker, 'consume_latency', $durationMs);
    }

    /**
     * Record error occurrence.
     *
     * @param string $broker Broker name
     * @param string $errorType Error type
     * @return void
     */
    public static function recordError(string $broker, string $errorType): void
    {
        self::increment($broker, "error_{$errorType}");
        self::increment($broker, 'error_total');
    }

    /**
     * Record connection event.
     *
     * @param string $broker Broker name
     * @param string $event Event name (connect, disconnect, reconnect)
     * @return void
     */
    public static function recordConnectionEvent(string $broker, string $event): void
    {
        self::increment($broker, "connection_{$event}");
    }

    /**
     * Increment counter metric.
     *
     * @param string $broker Broker name
     * @param string $metric Metric name
     * @return void
     */
    private static function increment(string $broker, string $metric): void
    {
        if (!isset(self::$metrics[$broker])) {
            self::$metrics[$broker] = [];
        }

        if (!isset(self::$metrics[$broker][$metric])) {
            self::$metrics[$broker][$metric] = 0;
        }

        self::$metrics[$broker][$metric]++;
    }

    /**
     * Add value to counter metric.
     *
     * @param string $broker Broker name
     * @param string $metric Metric name
     * @param int $value Value to add
     * @return void
     */
    private static function add(string $broker, string $metric, int $value): void
    {
        if (!isset(self::$metrics[$broker])) {
            self::$metrics[$broker] = [];
        }

        if (!isset(self::$metrics[$broker][$metric])) {
            self::$metrics[$broker][$metric] = 0;
        }

        self::$metrics[$broker][$metric] += $value;
    }

    /**
     * Record latency metric.
     *
     * @param string $broker Broker name
     * @param string $metric Metric name
     * @param float $valueMs Value in milliseconds
     * @return void
     */
    private static function recordLatency(string $broker, string $metric, float $valueMs): void
    {
        if (!isset(self::$metrics[$broker])) {
            self::$metrics[$broker] = [];
        }

        $latencyKey = "{$metric}_ms";
        $countKey = "{$metric}_count";

        if (!isset(self::$metrics[$broker][$latencyKey])) {
            self::$metrics[$broker][$latencyKey] = [];
        }

        self::$metrics[$broker][$latencyKey][] = $valueMs;

        if (!isset(self::$metrics[$broker][$countKey])) {
            self::$metrics[$broker][$countKey] = 0;
        }
        self::$metrics[$broker][$countKey]++;
    }

    /**
     * Get metrics for specific broker.
     *
     * @param string $broker Broker name
     * @return array<string, mixed>
     */
    public static function getMetrics(string $broker): array
    {
        $metrics = self::$metrics[$broker] ?? [];

        // Calculate statistics
        $result = [];

        foreach ($metrics as $key => $value) {
            if (str_ends_with($key, '_ms')) {
                // Latency metric - calculate percentiles
                if (is_array($value) && !empty($value)) {
                    sort($value);
                    $count = count($value);

                    $result[$key] = [
                        'min' => min($value),
                        'max' => max($value),
                        'avg' => array_sum($value) / $count,
                        'p50' => $value[(int)($count * 0.5)] ?? 0,
                        'p95' => $value[(int)($count * 0.95)] ?? 0,
                        'p99' => $value[(int)($count * 0.99)] ?? 0,
                    ];
                }
            } else {
                // Counter metric
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get all metrics for all brokers.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAllMetrics(): array
    {
        $result = [];

        foreach (array_keys(self::$metrics) as $broker) {
            $result[$broker] = self::getMetrics($broker);
        }

        return $result;
    }

    /**
     * Reset all metrics.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$metrics = [];
    }

    /**
     * Reset metrics for specific broker.
     *
     * @param string $broker Broker name
     * @return void
     */
    public static function resetBroker(string $broker): void
    {
        unset(self::$metrics[$broker]);
    }
}
