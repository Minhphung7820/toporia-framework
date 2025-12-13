<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Queue;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Queue\Contracts\QueueManagerInterface;
use Toporia\Framework\Queue\RedisQueue;

/**
 * Class QueueFlushCommand
 *
 * Flush all failed queue jobs.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Queue
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class QueueFlushCommand extends Command
{
    protected string $signature = 'queue:flush {--hours= : The number of hours to retain failed job data}';

    protected string $description = 'Flush all of the failed queue jobs';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly QueueManagerInterface $queueManager
    ) {}

    public function handle(): int
    {
        try {
            // Get current queue driver
            $driver = $this->queueManager->driver();

            // Get hours option if provided
            $hoursOption = $this->option('hours');
            $hours = $hoursOption !== null ? (int) $hoursOption : null;

            // Flush based on driver type
            if ($driver instanceof RedisQueue) {
                $deletedCount = $this->flushRedis($hours);
            } else {
                // Default: Database (works for DatabaseQueue and RabbitMQQueue fallback)
                if (!$this->tableExists('failed_jobs')) {
                    $this->error('Failed jobs table does not exist.');
                    $this->info('Run migrations to create the failed_jobs table.');
                    return 1;
                }

                $deletedCount = $hours !== null
                    ? $this->flushDatabaseOlderThan($hours)
                    : $this->flushDatabaseAll();
            }

            // Display result
            if ($deletedCount > 0) {
                if ($hours !== null) {
                    $this->success("Deleted {$deletedCount} failed job(s) older than {$hours} hour(s).");
                } else {
                    $this->success("All failed jobs cleared! ({$deletedCount} job(s) deleted)");
                }
            } else {
                if ($hours !== null) {
                    $this->info("No failed jobs found older than {$hours} hour(s).");
                } else {
                    $this->info('No failed jobs to flush.');
                }
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed to flush jobs: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Flush all failed jobs from database.
     *
     * @return int Number of deleted jobs
     */
    private function flushDatabaseAll(): int
    {
        $connection = $this->db->connection();

        // Get count before deletion
        $count = $connection->table('failed_jobs')->count();

        // Delete all failed jobs
        $connection->table('failed_jobs')->delete();

        return $count;
    }

    /**
     * Flush failed jobs older than specified hours from database.
     *
     * @param int $hours Number of hours
     * @return int Number of deleted jobs
     */
    private function flushDatabaseOlderThan(int $hours): int
    {
        $connection = $this->db->connection();
        $cutoffTime = now()->getTimestamp() - ($hours * 3600);

        // Get count before deletion
        $count = $connection->table('failed_jobs')
            ->where('failed_at', '<', $cutoffTime)
            ->count();

        // Delete old failed jobs
        $connection->table('failed_jobs')
            ->where('failed_at', '<', $cutoffTime)
            ->delete();

        return $count;
    }

    /**
     * Flush failed jobs from Redis.
     *
     * @param int|null $hours Number of hours (null = flush all)
     * @return int Number of deleted jobs
     */
    private function flushRedis(?int $hours): int
    {
        /** @var RedisQueue $driver */
        $driver = $this->queueManager->driver();
        $redis = $this->getRedisConnection($driver);

        // Get Redis prefix
        $reflection = new \ReflectionClass($driver);
        $prefixProperty = $reflection->getProperty('prefix');
        $prefixProperty->setAccessible(true);
        $prefix = $prefixProperty->getValue($driver);

        $failedKey = "{$prefix}:failed";

        // Get all failed job IDs
        if ($hours !== null) {
            // Get jobs older than specified hours
            $cutoffTime = now()->getTimestamp() - ($hours * 3600);
            $failedIds = $redis->zRangeByScore($failedKey, (string)0, (string)$cutoffTime);
        } else {
            // Get all failed jobs
            $failedIds = $redis->zRange($failedKey, 0, -1);
        }

        if (empty($failedIds)) {
            return 0;
        }

        $count = count($failedIds);

        // Delete failed jobs
        $redis->multi(\Redis::PIPELINE);
        foreach ($failedIds as $failedId) {
            $failedJobKey = "failed_jobs:{$failedId}";
            $redis->del($failedJobKey);
            $redis->zRem($failedKey, $failedId);
        }
        $redis->exec();

        return $count;
    }

    /**
     * Get Redis connection from RedisQueue driver.
     *
     * @param RedisQueue $driver
     * @return \Redis
     */
    private function getRedisConnection(RedisQueue $driver): \Redis
    {
        $reflection = new \ReflectionClass($driver);
        $redisProperty = $reflection->getProperty('redis');
        $redisProperty->setAccessible(true);
        return $redisProperty->getValue($driver);
    }

    /**
     * Check if table exists in database.
     *
     * @param string $table Table name
     * @return bool
     */
    private function tableExists(string $table): bool
    {
        try {
            $connection = $this->db->connection();
            $pdo = $connection->getPdo();

            // Get driver name to use appropriate query
            $driver = $connection->getConfig()['driver'] ?? 'mysql';

            if ($driver === 'mysql') {
                $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$table]);
                return $stmt->fetch() !== false;
            } elseif ($driver === 'pgsql') {
                $stmt = $pdo->prepare("SELECT to_regclass(?) IS NOT NULL");
                $stmt->execute([$table]);
                return $stmt->fetchColumn() === true;
            } elseif ($driver === 'sqlite') {
                $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
                $stmt->execute([$table]);
                return $stmt->fetch() !== false;
            }

            // Fallback: try to query the table
            $connection->table($table)->limit(1)->get();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
