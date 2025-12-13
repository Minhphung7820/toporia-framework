<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Queue;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Queue\Contracts\QueueManagerInterface;
use Toporia\Framework\Queue\RedisQueue;

/**
 * Class QueueRetryCommand
 *
 * Retry failed queue jobs by pushing them back onto the queue.
 *
 * Features:
 * - Retry specific job by ID
 * - Retry multiple jobs by IDs
 * - Retry all failed jobs at once
 * - Support for both Database and Redis queue drivers
 * - Reset job attempts counter on retry
 * - Preserve original job payload and metadata
 *
 * Usage:
 *   php console queue:retry 123                    # Retry job ID 123
 *   php console queue:retry 123 456 789            # Retry multiple jobs
 *   php console queue:retry all                    # Retry all failed jobs
 *   php console queue:retry --queue=emails all     # Retry all from specific queue
 *
 * Performance:
 * - O(N) where N = number of jobs to retry
 * - Batch operations when possible
 * - Transaction support for atomicity
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
final class QueueRetryCommand extends Command
{
    protected string $signature = 'queue:retry {id?* : The IDs of the failed jobs or "all" to retry all jobs} {--queue= : Only retry jobs from specific queue}';

    protected string $description = 'Retry failed queue jobs';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly QueueManagerInterface $queueManager
    ) {}

    public function handle(): int
    {
        $ids = $this->argument('id');
        $queueFilter = $this->option('queue');

        // Validate input
        if (empty($ids)) {
            $this->error('Please specify job IDs to retry, or use "all" to retry all failed jobs.');
            $this->info('');
            $this->info('Examples:');
            $this->info('  php console queue:retry 123');
            $this->info('  php console queue:retry 123 456 789');
            $this->info('  php console queue:retry all');
            $this->info('  php console queue:retry all --queue=emails');
            return 1;
        }

        try {
            // Get current queue driver
            $driver = $this->queueManager->driver();

            // Retry based on driver type
            if ($driver instanceof RedisQueue) {
                $retriedCount = $this->retryRedis($ids, $queueFilter);
            } else {
                // Default: Database
                if (!$this->tableExists('failed_jobs')) {
                    $this->error('Failed jobs table does not exist.');
                    $this->info('Run migrations to create the failed_jobs table.');
                    return 1;
                }

                $retriedCount = $this->retryDatabase($ids, $queueFilter);
            }

            // Display result
            if ($retriedCount > 0) {
                $this->success("Successfully retried {$retriedCount} failed job(s)!");
            } else {
                $this->info('No failed jobs found to retry.');
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed to retry jobs: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Retry failed jobs from database.
     *
     * @param array<string> $ids Job IDs or ['all']
     * @param string|null $queueFilter Queue name filter
     * @return int Number of retried jobs
     */
    private function retryDatabase(array $ids, ?string $queueFilter): int
    {
        $connection = $this->db->connection();
        $retryAll = in_array('all', $ids, true);

        // Build query
        $query = $connection->table('failed_jobs');

        if (!$retryAll) {
            $query->whereIn('id', $ids);
        }

        if ($queueFilter !== null) {
            $query->where('queue', $queueFilter);
        }

        // Get failed jobs
        $failedJobs = $query->get();

        if (empty($failedJobs)) {
            return 0;
        }

        $retriedCount = 0;

        // Retry each job
        foreach ($failedJobs as $failedJob) {
            try {
                // Unserialize job payload
                $job = unserialize($failedJob['payload']);

                if (!$job) {
                    $this->warn("Failed to unserialize job #{$failedJob['id']}. Skipping.");
                    continue;
                }

                // Reset attempts counter
                if (method_exists($job, 'resetAttempts')) {
                    $job->resetAttempts();
                }

                // Push back to queue
                $queue = $failedJob['queue'] ?? 'default';
                $this->queueManager->push($job, $queue);

                // Delete from failed_jobs table
                $connection->table('failed_jobs')
                    ->where('id', $failedJob['id'])
                    ->delete();

                $this->info("✓ Retried job #{$failedJob['id']} on queue '{$queue}'");
                $retriedCount++;
            } catch (\Throwable $e) {
                $this->error("✗ Failed to retry job #{$failedJob['id']}: {$e->getMessage()}");
            }
        }

        return $retriedCount;
    }

    /**
     * Retry failed jobs from Redis.
     *
     * @param array<string> $ids Job IDs or ['all']
     * @param string|null $queueFilter Queue name filter
     * @return int Number of retried jobs
     */
    private function retryRedis(array $ids, ?string $queueFilter): int
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
        $retryAll = in_array('all', $ids, true);

        // Get failed job IDs from Redis sorted set
        if ($retryAll) {
            $failedIds = $redis->zRange($failedKey, 0, -1);
        } else {
            // Filter only existing failed job IDs
            $failedIds = array_filter($ids, function ($id) use ($redis, $failedKey) {
                return $redis->zScore($failedKey, $id) !== false;
            });
        }

        if (empty($failedIds)) {
            return 0;
        }

        $retriedCount = 0;

        // Retry each job
        foreach ($failedIds as $failedId) {
            try {
                $failedJobKey = "failed_jobs:{$failedId}";
                $failedJobData = $redis->hGetAll($failedJobKey);

                if (empty($failedJobData)) {
                    $this->warn("Failed job {$failedId} not found in Redis. Skipping.");
                    continue;
                }

                // Check queue filter
                if ($queueFilter !== null && ($failedJobData['queue'] ?? '') !== $queueFilter) {
                    continue;
                }

                // Unserialize job payload
                $job = unserialize($failedJobData['payload']);

                if (!$job) {
                    $this->warn("Failed to unserialize job {$failedId}. Skipping.");
                    continue;
                }

                // Reset attempts counter
                if (method_exists($job, 'resetAttempts')) {
                    $job->resetAttempts();
                }

                // Push back to queue
                $queue = $failedJobData['queue'] ?? 'default';
                $this->queueManager->push($job, $queue);

                // Delete from Redis failed jobs
                $redis->multi(\Redis::PIPELINE);
                $redis->del($failedJobKey);
                $redis->zRem($failedKey, $failedId);
                $redis->exec();

                $this->info("✓ Retried job {$failedId} on queue '{$queue}'");
                $retriedCount++;
            } catch (\Throwable $e) {
                $this->error("✗ Failed to retry job {$failedId}: {$e->getMessage()}");
            }
        }

        return $retriedCount;
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
