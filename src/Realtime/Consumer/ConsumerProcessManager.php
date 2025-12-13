<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Consumer;

use Toporia\Framework\Cache\Contracts\CacheInterface;
use Toporia\Framework\Realtime\Consumer\Contracts\ConsumerContext;

/**
 * Class ConsumerProcessManager
 *
 * Manages and tracks running consumer processes across the system.
 * Provides process registration, heartbeat tracking, and status queries.
 *
 * Features:
 * - Process registration with unique ID
 * - Heartbeat monitoring for health checks
 * - Status tracking (running, stopped, failed)
 * - Multi-node support via shared cache storage
 * - Automatic cleanup of stale processes
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Consumer
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ConsumerProcessManager
{
    /**
     * Cache key prefix for process data.
     */
    private const CACHE_PREFIX = 'consumer:process:';

    /**
     * Cache key for process index.
     */
    private const INDEX_KEY = 'consumer:processes';

    /**
     * Process status constants.
     */
    public const STATUS_STARTING = 'starting';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPING = 'stopping';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';

    /**
     * Default heartbeat timeout in seconds.
     * Process is considered dead if no heartbeat within this time.
     */
    private const DEFAULT_HEARTBEAT_TIMEOUT = 60;

    /**
     * Default TTL for process data in cache (24 hours).
     */
    private const DEFAULT_CACHE_TTL = 86400;

    /**
     * @param CacheInterface $cache Cache driver for persistence
     * @param int $heartbeatTimeout Seconds before process considered dead
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $heartbeatTimeout = self::DEFAULT_HEARTBEAT_TIMEOUT
    ) {}

    /**
     * Generate a unique process ID.
     *
     * @param string $handlerName Handler name
     * @param string $driver Broker driver
     * @return string Process ID
     */
    public function generateProcessId(string $handlerName, string $driver): string
    {
        $hostname = gethostname() ?: 'unknown';
        $pid = getmypid();
        $timestamp = microtime(true);

        return sprintf(
            '%s.%s.%s.%d.%s',
            $driver,
            $handlerName,
            $hostname,
            $pid,
            substr(md5((string) $timestamp), 0, 8)
        );
    }

    /**
     * Register a new consumer process.
     *
     * @param string $processId Unique process ID
     * @param string $handlerName Handler name
     * @param string $driver Broker driver
     * @param array<string> $channels Subscribed channels
     * @param array<string, mixed> $metadata Additional metadata
     * @return void
     */
    public function register(
        string $processId,
        string $handlerName,
        string $driver,
        array $channels,
        array $metadata = []
    ): void {
        $now = microtime(true);

        $processData = [
            'id' => $processId,
            'handler' => $handlerName,
            'driver' => $driver,
            'channels' => $channels,
            'status' => self::STATUS_STARTING,
            'pid' => getmypid(),
            'hostname' => gethostname() ?: 'unknown',
            'started_at' => $now,
            'last_heartbeat' => $now,
            'message_count' => 0,
            'error_count' => 0,
            'metadata' => $metadata,
        ];

        // Store process data
        $this->cache->set(
            self::CACHE_PREFIX . $processId,
            $processData,
            self::DEFAULT_CACHE_TTL
        );

        // Add to index
        $this->addToIndex($processId);
    }

    /**
     * Local cache to reduce read operations.
     * @var array<string, array<string, mixed>>
     */
    private array $localCache = [];

    /**
     * Update process heartbeat and status.
     *
     * Optimized for high-frequency calls:
     * - Uses local cache to avoid redundant reads
     * - Only writes essential data to reduce cache operations
     *
     * @param string $processId Process ID
     * @param ConsumerContext|null $context Current context (optional)
     * @return void
     */
    public function heartbeat(string $processId, ?ConsumerContext $context = null): void
    {
        // Use local cache if available (avoids read on every heartbeat)
        $data = $this->localCache[$processId] ?? $this->getProcessData($processId);

        if ($data === null) {
            return;
        }

        $now = microtime(true);
        $data['last_heartbeat'] = $now;
        $data['status'] = self::STATUS_RUNNING;

        if ($context !== null) {
            $data['message_count'] = $context->messageCount;
            $data['error_count'] = $context->errorCount;
            $data['current_channel'] = $context->channel;
        }

        // Update local cache
        $this->localCache[$processId] = $data;

        // Write to shared cache
        $this->cache->set(
            self::CACHE_PREFIX . $processId,
            $data,
            self::DEFAULT_CACHE_TTL
        );
    }

    /**
     * Update process status.
     *
     * @param string $processId Process ID
     * @param string $status New status
     * @param array<string, mixed> $additionalData Additional data to merge
     * @return void
     */
    public function updateStatus(
        string $processId,
        string $status,
        array $additionalData = []
    ): void {
        $data = $this->getProcessData($processId);
        if ($data === null) {
            return;
        }

        $data['status'] = $status;
        $data['last_heartbeat'] = microtime(true);

        if ($status === self::STATUS_STOPPED || $status === self::STATUS_FAILED) {
            $data['stopped_at'] = microtime(true);
        }

        $data = array_merge($data, $additionalData);

        $this->cache->set(
            self::CACHE_PREFIX . $processId,
            $data,
            self::DEFAULT_CACHE_TTL
        );
    }

    /**
     * Unregister a process (mark as stopped).
     *
     * @param string $processId Process ID
     * @param string $reason Stop reason (optional)
     * @return void
     */
    public function unregister(string $processId, string $reason = ''): void
    {
        $this->updateStatus($processId, self::STATUS_STOPPED, [
            'stop_reason' => $reason,
        ]);

        // Remove from index after a delay (keep for status queries)
        // In production, you might want to schedule cleanup
    }

    /**
     * Mark process as failed.
     *
     * @param string $processId Process ID
     * @param string $error Error message
     * @param string|null $exceptionClass Exception class name
     * @return void
     */
    public function markFailed(
        string $processId,
        string $error,
        ?string $exceptionClass = null
    ): void {
        $this->updateStatus($processId, self::STATUS_FAILED, [
            'error' => $error,
            'exception_class' => $exceptionClass,
        ]);
    }

    /**
     * Get process data by ID.
     *
     * @param string $processId Process ID
     * @return array<string, mixed>|null
     */
    public function getProcessData(string $processId): ?array
    {
        $data = $this->cache->get(self::CACHE_PREFIX . $processId);
        return is_array($data) ? $data : null;
    }

    /**
     * Get all registered process IDs.
     *
     * @return array<string>
     */
    public function getProcessIds(): array
    {
        $index = $this->cache->get(self::INDEX_KEY);
        return is_array($index) ? $index : [];
    }

    /**
     * Get all running processes.
     *
     * @param bool $includeStale Include processes that may be dead
     * @return array<array<string, mixed>>
     */
    public function getRunningProcesses(bool $includeStale = false): array
    {
        $processes = [];
        $processIds = $this->getProcessIds();
        $staleIds = [];

        foreach ($processIds as $processId) {
            $data = $this->getProcessData($processId);
            if ($data === null) {
                $staleIds[] = $processId;
                continue;
            }

            // Check if process is alive
            $isAlive = $this->isProcessAlive($data);

            if (!$isAlive && !$includeStale) {
                // Mark as potentially dead
                if ($data['status'] === self::STATUS_RUNNING) {
                    $this->updateStatus($processId, self::STATUS_FAILED, [
                        'error' => 'Heartbeat timeout - process may be dead',
                    ]);
                }
                continue;
            }

            $data['is_alive'] = $isAlive;
            $data['uptime'] = microtime(true) - ($data['started_at'] ?? microtime(true));
            $processes[] = $data;
        }

        // Clean up stale entries
        foreach ($staleIds as $staleId) {
            $this->removeFromIndex($staleId);
        }

        return $processes;
    }

    /**
     * Get processes filtered by handler name.
     *
     * @param string $handlerName Handler name
     * @return array<array<string, mixed>>
     */
    public function getProcessesByHandler(string $handlerName): array
    {
        $allProcesses = $this->getRunningProcesses(true);

        return array_filter($allProcesses, function (array $process) use ($handlerName) {
            return ($process['handler'] ?? '') === $handlerName;
        });
    }

    /**
     * Get processes filtered by driver.
     *
     * @param string $driver Broker driver
     * @return array<array<string, mixed>>
     */
    public function getProcessesByDriver(string $driver): array
    {
        $allProcesses = $this->getRunningProcesses(true);

        return array_filter($allProcesses, function (array $process) use ($driver) {
            return ($process['driver'] ?? '') === $driver;
        });
    }

    /**
     * Get process statistics summary.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        $allProcesses = $this->getRunningProcesses(true);

        $stats = [
            'total' => count($allProcesses),
            'running' => 0,
            'stopped' => 0,
            'failed' => 0,
            'by_driver' => [],
            'by_handler' => [],
            'total_messages' => 0,
            'total_errors' => 0,
        ];

        foreach ($allProcesses as $process) {
            $status = $process['status'] ?? self::STATUS_STOPPED;
            $driver = $process['driver'] ?? 'unknown';
            $handler = $process['handler'] ?? 'unknown';

            // Count by status
            if ($status === self::STATUS_RUNNING && ($process['is_alive'] ?? false)) {
                $stats['running']++;
            } elseif ($status === self::STATUS_STOPPED) {
                $stats['stopped']++;
            } elseif ($status === self::STATUS_FAILED) {
                $stats['failed']++;
            }

            // Count by driver
            $stats['by_driver'][$driver] = ($stats['by_driver'][$driver] ?? 0) + 1;

            // Count by handler
            $stats['by_handler'][$handler] = ($stats['by_handler'][$handler] ?? 0) + 1;

            // Sum messages and errors
            $stats['total_messages'] += (int) ($process['message_count'] ?? 0);
            $stats['total_errors'] += (int) ($process['error_count'] ?? 0);
        }

        return $stats;
    }

    /**
     * Check if a process is alive based on heartbeat.
     *
     * @param array<string, mixed> $processData Process data
     * @return bool
     */
    public function isProcessAlive(array $processData): bool
    {
        $status = $processData['status'] ?? self::STATUS_STOPPED;

        // Stopped or failed processes are not alive
        if ($status === self::STATUS_STOPPED || $status === self::STATUS_FAILED) {
            return false;
        }

        $lastHeartbeat = $processData['last_heartbeat'] ?? 0;
        $timeSinceHeartbeat = microtime(true) - $lastHeartbeat;

        return $timeSinceHeartbeat < $this->heartbeatTimeout;
    }

    /**
     * Check if a specific OS process is still running.
     *
     * @param int $pid Process ID
     * @return bool
     */
    public function isOsProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // Check if process exists
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Fallback: check /proc on Linux
        if (is_dir('/proc/' . $pid)) {
            return true;
        }

        return false;
    }

    /**
     * Clean up dead/stale processes.
     *
     * @param bool $removeOldStopped Remove stopped processes older than TTL
     * @param int $stoppedTtl TTL for stopped processes in seconds (default 1 hour)
     * @return int Number of processes cleaned
     */
    public function cleanup(bool $removeOldStopped = true, int $stoppedTtl = 3600): int
    {
        $cleaned = 0;
        $processIds = $this->getProcessIds();
        $now = microtime(true);

        foreach ($processIds as $processId) {
            $data = $this->getProcessData($processId);

            if ($data === null) {
                $this->removeFromIndex($processId);
                $cleaned++;
                continue;
            }

            $status = $data['status'] ?? self::STATUS_STOPPED;
            $stoppedAt = $data['stopped_at'] ?? 0;

            // Remove old stopped processes
            if ($removeOldStopped && $status === self::STATUS_STOPPED) {
                if ($stoppedAt > 0 && ($now - $stoppedAt) > $stoppedTtl) {
                    $this->cache->delete(self::CACHE_PREFIX . $processId);
                    $this->removeFromIndex($processId);
                    $cleaned++;
                    continue;
                }
            }

            // Mark dead processes as failed
            if ($status === self::STATUS_RUNNING && !$this->isProcessAlive($data)) {
                $this->markFailed(
                    $processId,
                    'Heartbeat timeout - process unresponsive'
                );
            }
        }

        return $cleaned;
    }

    /**
     * Signal a process to stop gracefully.
     *
     * @param string $processId Process ID
     * @return bool
     */
    public function signalStop(string $processId): bool
    {
        $data = $this->getProcessData($processId);
        if ($data === null) {
            return false;
        }

        $pid = $data['pid'] ?? 0;

        // Update status to stopping
        $this->updateStatus($processId, self::STATUS_STOPPING);

        // Send SIGTERM to process
        if ($pid > 0 && function_exists('posix_kill')) {
            return posix_kill($pid, SIGTERM);
        }

        return true;
    }

    /**
     * Force kill a process.
     *
     * @param string $processId Process ID
     * @return bool
     */
    public function forceKill(string $processId): bool
    {
        $data = $this->getProcessData($processId);
        if ($data === null) {
            return false;
        }

        $pid = $data['pid'] ?? 0;

        // Send SIGKILL to process
        if ($pid > 0 && function_exists('posix_kill')) {
            $killed = posix_kill($pid, SIGKILL);
            if ($killed) {
                $this->updateStatus($processId, self::STATUS_STOPPED, [
                    'stop_reason' => 'Force killed',
                ]);
            }
            return $killed;
        }

        return false;
    }

    /**
     * Add process ID to index.
     *
     * @param string $processId Process ID
     * @return void
     */
    private function addToIndex(string $processId): void
    {
        $index = $this->getProcessIds();

        if (!in_array($processId, $index, true)) {
            $index[] = $processId;
            $this->cache->set(self::INDEX_KEY, $index, self::DEFAULT_CACHE_TTL);
        }
    }

    /**
     * Remove process ID from index.
     *
     * @param string $processId Process ID
     * @return void
     */
    private function removeFromIndex(string $processId): void
    {
        $index = $this->getProcessIds();
        $index = array_filter($index, fn($id) => $id !== $processId);
        $this->cache->set(self::INDEX_KEY, array_values($index), self::DEFAULT_CACHE_TTL);
    }

    /**
     * Clear all process data (use with caution).
     * This will also kill all running consumer processes.
     *
     * @param bool $killProcesses Whether to kill OS processes before clearing
     * @return int Number of processes killed
     */
    public function clearAll(bool $killProcesses = true): int
    {
        $processIds = $this->getProcessIds();
        $killed = 0;

        foreach ($processIds as $processId) {
            if ($killProcesses) {
                $data = $this->getProcessData($processId);
                if ($data !== null) {
                    $pid = $data['pid'] ?? 0;
                    $status = $data['status'] ?? '';

                    // Kill running processes
                    if ($pid > 0 && $status === self::STATUS_RUNNING) {
                        if (function_exists('posix_kill') && $this->isOsProcessRunning($pid)) {
                            // Try graceful shutdown first (SIGTERM)
                            posix_kill($pid, SIGTERM);
                            $killed++;

                            // Give process 100ms to exit gracefully
                            usleep(100000);

                            // Force kill if still running
                            if ($this->isOsProcessRunning($pid)) {
                                posix_kill($pid, SIGKILL);
                            }
                        }
                    }
                }
            }

            // Delete process data from cache
            $this->cache->delete(self::CACHE_PREFIX . $processId);
        }

        // Clear the index
        $this->cache->delete(self::INDEX_KEY);

        return $killed;
    }
}
