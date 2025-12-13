<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Realtime;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Realtime\RealtimeManager;
use Toporia\Framework\Realtime\Contracts\HealthCheckableInterface;
use Toporia\Framework\Realtime\Contracts\HealthCheckResult;

/**
 * Check health status of realtime brokers.
 */
/**
 * Class RealtimeHealthCommand
 *
 * Check realtime server health status.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Realtime
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RealtimeHealthCommand extends Command
{
    protected string $signature = 'realtime:health {--broker= : Specific broker to check (redis, rabbitmq, kafka)} {--json : Output as JSON}';

    protected string $description = 'Check health status of realtime brokers';

    public function handle(): int
    {
        $specificBroker = $this->option('broker');
        $outputJson = $this->option('json');

        $brokers = $specificBroker
            ? [$specificBroker]
            : ['redis', 'rabbitmq', 'kafka'];

        $results = [];
        $hasUnhealthy = false;

        foreach ($brokers as $brokerName) {
            $result = $this->checkBroker($brokerName);
            $results[$brokerName] = $result;

            if ($result && $result->isUnhealthy()) {
                $hasUnhealthy = true;
            }
        }

        if ($outputJson) {
            $this->outputJson($results);
        } else {
            $this->outputTable($results);
        }

        return $hasUnhealthy ? 1 : 0;
    }

    /**
     * Check a specific broker's health.
     */
    private function checkBroker(string $brokerName): ?HealthCheckResult
    {
        try {
            $realtimeConfig = config('realtime', []);
            $realtimeConfig['default_broker'] = $brokerName;

            $manager = new RealtimeManager($realtimeConfig);
            $broker = $manager->broker($brokerName);

            if ($broker === null) {
                return HealthCheckResult::unhealthy(
                    "Broker '{$brokerName}' is not configured",
                    ['broker' => $brokerName]
                );
            }

            if ($broker instanceof HealthCheckableInterface) {
                return $broker->healthCheck();
            }

            // Broker doesn't implement health check, just check if connected
            return $broker->isConnected()
                ? HealthCheckResult::healthy('Connected (no detailed health check available)')
                : HealthCheckResult::unhealthy('Not connected');

        } catch (\Throwable $e) {
            return HealthCheckResult::unhealthy(
                "Failed to initialize broker: {$e->getMessage()}",
                ['exception' => $e::class]
            );
        }
    }

    /**
     * Output results as JSON.
     *
     * @param array<string, HealthCheckResult|null> $results
     */
    private function outputJson(array $results): void
    {
        $output = [];
        foreach ($results as $broker => $result) {
            $output[$broker] = $result?->toArray() ?? ['status' => 'unknown', 'message' => 'No result'];
        }

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Output results as table.
     *
     * @param array<string, HealthCheckResult|null> $results
     */
    private function outputTable(array $results): void
    {
        $this->info('Realtime Broker Health Check');
        $this->info(str_repeat('=', 50));
        $this->newLine();

        foreach ($results as $broker => $result) {
            if ($result === null) {
                $this->line("  {$broker}: <fg=yellow>UNKNOWN</>");
                continue;
            }

            $statusColor = match ($result->status) {
                HealthCheckResult::STATUS_HEALTHY => 'green',
                HealthCheckResult::STATUS_DEGRADED => 'yellow',
                HealthCheckResult::STATUS_UNHEALTHY => 'red',
                default => 'white',
            };

            $statusIcon = match ($result->status) {
                HealthCheckResult::STATUS_HEALTHY => '✓',
                HealthCheckResult::STATUS_DEGRADED => '⚠',
                HealthCheckResult::STATUS_UNHEALTHY => '✗',
                default => '?',
            };

            $this->line("  <fg={$statusColor}>{$statusIcon}</> <options=bold>{$broker}</>");
            $this->line("     Status: <fg={$statusColor}>{$result->status}</>");
            $this->line("     Message: {$result->message}");

            if ($result->latencyMs > 0) {
                $this->line("     Latency: " . number_format($result->latencyMs, 2) . "ms");
            }

            if (!empty($result->details)) {
                $this->line("     Details:");
                foreach ($result->details as $key => $value) {
                    $displayValue = is_array($value) ? json_encode($value) : (string) $value;
                    $this->line("       - {$key}: {$displayValue}");
                }
            }

            $this->newLine();
        }

        // Summary
        $healthy = 0;
        $degraded = 0;
        $unhealthy = 0;

        foreach ($results as $result) {
            if ($result === null) continue;
            match ($result->status) {
                HealthCheckResult::STATUS_HEALTHY => $healthy++,
                HealthCheckResult::STATUS_DEGRADED => $degraded++,
                HealthCheckResult::STATUS_UNHEALTHY => $unhealthy++,
                default => null,
            };
        }

        $this->info(str_repeat('-', 50));
        $this->line("Summary: <fg=green>{$healthy} healthy</>, <fg=yellow>{$degraded} degraded</>, <fg=red>{$unhealthy} unhealthy</>");
    }
}
