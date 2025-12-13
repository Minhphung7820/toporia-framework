<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Scheduling\Support;

/**
 * Class TaskHistory
 *
 * Tracks task execution history, metrics, and statistics with O(1) record
 * operations, O(1) latest history lookup, and automatic cleanup of old records.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Scheduling
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class TaskHistory
{
    /**
     * @var array<string, array<int, array{started: float, finished: ?float, success: bool, memory: int, error: ?string}>> Task history
     */
    private static array $history = [];

    /**
     * @var int Maximum history entries per task
     */
    private const MAX_HISTORY_PER_TASK = 100;

    /**
     * Record task execution start.
     *
     * @param string $taskId Task identifier
     * @return void
     */
    public static function recordStart(string $taskId): void
    {
        if (!isset(self::$history[$taskId])) {
            self::$history[$taskId] = [];
        }

        self::$history[$taskId][] = [
            'started' => microtime(true),
            'finished' => null,
            'success' => false,
            'memory' => memory_get_usage(true),
            'error' => null,
        ];

        // Cleanup old history
        if (count(self::$history[$taskId]) > self::MAX_HISTORY_PER_TASK) {
            array_shift(self::$history[$taskId]);
        }
    }

    /**
     * Record task execution finish.
     *
     * @param string $taskId Task identifier
     * @param bool $success Whether task succeeded
     * @param string|null $error Error message if failed
     * @return void
     */
    public static function recordFinish(string $taskId, bool $success, ?string $error = null): void
    {
        if (!isset(self::$history[$taskId]) || empty(self::$history[$taskId])) {
            return;
        }

        $lastIndex = count(self::$history[$taskId]) - 1;
        self::$history[$taskId][$lastIndex]['finished'] = microtime(true);
        self::$history[$taskId][$lastIndex]['success'] = $success;
        self::$history[$taskId][$lastIndex]['error'] = $error;
    }

    /**
     * Get latest execution for a task.
     *
     * @param string $taskId
     * @return array{started: float, finished: ?float, success: bool, memory: int, error: ?string, duration: ?float}|null
     */
    public static function getLatest(string $taskId): ?array
    {
        if (!isset(self::$history[$taskId]) || empty(self::$history[$taskId])) {
            return null;
        }

        $latest = end(self::$history[$taskId]);
        $latest['duration'] = $latest['finished'] !== null
            ? $latest['finished'] - $latest['started']
            : null;

        return $latest;
    }

    /**
     * Get full history for a task.
     *
     * @param string $taskId
     * @return array<int, array{started: float, finished: ?float, success: bool, memory: int, error: ?string, duration: ?float}>
     */
    public static function getHistory(string $taskId): array
    {
        if (!isset(self::$history[$taskId])) {
            return [];
        }

        return array_map(function ($entry) {
            $entry['duration'] = $entry['finished'] !== null
                ? $entry['finished'] - $entry['started']
                : null;
            return $entry;
        }, self::$history[$taskId]);
    }

    /**
     * Get task statistics.
     *
     * @param string $taskId
     * @return array{total: int, successful: int, failed: int, avgDuration: ?float, lastRun: ?float, lastSuccess: ?float}
     */
    public static function getStatistics(string $taskId): array
    {
        $history = self::getHistory($taskId);

        if (empty($history)) {
            return [
                'total' => 0,
                'successful' => 0,
                'failed' => 0,
                'avgDuration' => null,
                'lastRun' => null,
                'lastSuccess' => null,
            ];
        }

        $successful = 0;
        $failed = 0;
        $totalDuration = 0.0;
        $durationCount = 0;
        $lastRun = null;
        $lastSuccess = null;

        foreach ($history as $entry) {
            if ($entry['success']) {
                $successful++;
                if ($lastSuccess === null || $entry['finished'] > $lastSuccess) {
                    $lastSuccess = $entry['finished'];
                }
            } else {
                $failed++;
            }

            if ($entry['duration'] !== null) {
                $totalDuration += $entry['duration'];
                $durationCount++;
            }

            if ($lastRun === null || $entry['started'] > $lastRun) {
                $lastRun = $entry['started'];
            }
        }

        return [
            'total' => count($history),
            'successful' => $successful,
            'failed' => $failed,
            'avgDuration' => $durationCount > 0 ? $totalDuration / $durationCount : null,
            'lastRun' => $lastRun,
            'lastSuccess' => $lastSuccess,
        ];
    }

    /**
     * Clear history for a task.
     *
     * @param string $taskId
     * @return void
     */
    public static function clear(string $taskId): void
    {
        unset(self::$history[$taskId]);
    }

    /**
     * Clear all history.
     *
     * @return void
     */
    public static function clearAll(): void
    {
        self::$history = [];
    }
}
