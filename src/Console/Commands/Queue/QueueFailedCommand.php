<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Queue;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Queue\Contracts\QueueManagerInterface;
use Toporia\Framework\Queue\RedisQueue;

/**
 * Class QueueFailedCommand
 *
 * List all failed queue jobs with detailed information.
 *
 * Features:
 * - Display all failed jobs in table format
 * - Show job ID, queue, class, failed time, and exception
 * - Support for both Database and Redis queue drivers
 * - Filtering by queue name
 * - Pagination support for large result sets
 * - Detailed view for specific job
 *
 * Usage:
 *   php console queue:failed                       # List all failed jobs
 *   php console queue:failed --queue=emails        # Filter by queue
 *   php console queue:failed --limit=50            # Limit results
 *   php console queue:failed --id=123              # Show detailed info for specific job
 *
 * Performance:
 * - O(N) where N = number of failed jobs
 * - Supports pagination to handle large datasets
 * - Efficient queries with proper indexing
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
final class QueueFailedCommand extends Command
{
    protected string $signature = 'queue:failed {--queue= : Filter by queue name} {--limit=50 : Maximum number of jobs to display} {--id= : Show detailed info for specific job ID}';

    protected string $description = 'List all of the failed queue jobs';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly QueueManagerInterface $queueManager
    ) {}

    public function handle(): int
    {
        $queueFilter = $this->option('queue');
        $limit = (int) $this->option('limit', 50);
        $jobId = $this->option('id');

        try {
            // Get current queue driver
            $driver = $this->queueManager->driver();

            // If specific job ID requested, show detailed view
            if ($jobId !== null) {
                return $this->showJobDetails($jobId, $driver);
            }

            // List failed jobs based on driver type
            if ($driver instanceof RedisQueue) {
                $failedJobs = $this->getRedisFailedJobs($queueFilter, $limit);
            } else {
                // Default: Database
                if (!$this->tableExists('failed_jobs')) {
                    $this->error('Failed jobs table does not exist.');
                    $this->info('Run migrations to create the failed_jobs table.');
                    $this->info("Or run: php console queue:failed-table");
                    return 1;
                }

                $failedJobs = $this->getDatabaseFailedJobs($queueFilter, $limit);
            }

            // Display failed jobs
            if (empty($failedJobs)) {
                $this->success('✓ No failed jobs found!');
                return 0;
            }

            $this->displayFailedJobs($failedJobs);

            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed to retrieve failed jobs: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Get failed jobs from database.
     *
     * @param string|null $queueFilter Queue name filter
     * @param int $limit Maximum results
     * @return array<array>
     */
    private function getDatabaseFailedJobs(?string $queueFilter, int $limit): array
    {
        $connection = $this->db->connection();
        $query = $connection->table('failed_jobs');

        if ($queueFilter !== null) {
            $query->where('queue', $queueFilter);
        }

        $results = $query->orderBy('failed_at', 'desc')
            ->limit($limit)
            ->get();

        // Transform database results
        return array_map(function ($row) {
            // Extract job class from payload
            $jobClass = $this->extractJobClass($row['payload']);

            return [
                'id' => $row['id'],
                'queue' => $row['queue'] ?? 'default',
                'class' => $jobClass,
                'failed_at' => $row['failed_at'],
                'exception' => $row['exception'] ?? 'N/A',
            ];
        }, $results);
    }

    /**
     * Get failed jobs from Redis.
     *
     * @param string|null $queueFilter Queue name filter
     * @param int $limit Maximum results
     * @return array<array>
     */
    private function getRedisFailedJobs(?string $queueFilter, int $limit): array
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

        // Get failed job IDs (sorted by failed timestamp, newest first)
        $failedIds = $redis->zRevRange($failedKey, 0, $limit - 1);

        if (empty($failedIds)) {
            return [];
        }

        $failedJobs = [];

        // Retrieve each failed job data
        foreach ($failedIds as $failedId) {
            $failedJobKey = "failed_jobs:{$failedId}";
            $jobData = $redis->hGetAll($failedJobKey);

            if (empty($jobData)) {
                continue;
            }

            // Apply queue filter
            if ($queueFilter !== null && ($jobData['queue'] ?? '') !== $queueFilter) {
                continue;
            }

            // Extract job class
            $jobClass = $this->extractJobClass($jobData['payload'] ?? '');

            $failedJobs[] = [
                'id' => $failedId,
                'queue' => $jobData['queue'] ?? 'default',
                'class' => $jobClass,
                'failed_at' => $jobData['failed_at'] ?? time(),
                'exception' => $jobData['exception'] ?? 'N/A',
            ];
        }

        return $failedJobs;
    }

    /**
     * Display failed jobs in table format.
     *
     * @param array<array> $failedJobs Failed jobs data
     * @return void
     */
    private function displayFailedJobs(array $failedJobs): void
    {
        $this->line('=', 80);
        $this->writeln('Failed Queue Jobs');
        $this->line('=', 80);
        $this->newLine();

        // Prepare table data
        $headers = ['ID', 'Queue', 'Job Class', 'Failed At', 'Exception'];
        $rows = [];

        foreach ($failedJobs as $job) {
            $failedAt = is_numeric($job['failed_at'])
                ? date('Y-m-d H:i:s', $job['failed_at'])
                : $job['failed_at'];

            // Truncate long values
            $exception = $this->truncate($job['exception'], 40);

            $rows[] = [
                $this->truncate((string) $job['id'], 8),
                $job['queue'],
                $this->truncate($job['class'], 30),
                $failedAt,
                $exception,
            ];
        }

        $this->table($headers, $rows);

        $this->newLine();
        $this->info("Total: " . count($failedJobs) . " failed job(s)");
        $this->newLine();

        // Show helpful commands
        $this->writeln('Helpful commands:');
        $this->writeln('  php console queue:failed --id=<id>     # View detailed info');
        $this->writeln('  php console queue:retry <id>           # Retry specific job');
        $this->writeln('  php console queue:retry all            # Retry all failed jobs');
        $this->writeln('  php console queue:flush                # Delete all failed jobs');
        $this->newLine();
    }

    /**
     * Show detailed information for a specific job.
     *
     * @param string $jobId Job ID
     * @param mixed $driver Queue driver
     * @return int Exit code
     */
    private function showJobDetails(string $jobId, mixed $driver): int
    {
        if ($driver instanceof RedisQueue) {
            $jobData = $this->getRedisJobDetails($jobId);
        } else {
            if (!$this->tableExists('failed_jobs')) {
                $this->error('Failed jobs table does not exist.');
                return 1;
            }
            $jobData = $this->getDatabaseJobDetails($jobId);
        }

        if ($jobData === null) {
            $this->error("Failed job '{$jobId}' not found.");
            return 1;
        }

        // Display detailed information
        $this->line('=', 80);
        $this->writeln("Failed Job Details: {$jobId}");
        $this->line('=', 80);
        $this->newLine();

        $failedAt = is_numeric($jobData['failed_at'])
            ? date('Y-m-d H:i:s', $jobData['failed_at'])
            : $jobData['failed_at'];

        $this->writeln("ID:         {$jobData['id']}");
        $this->writeln("Queue:      {$jobData['queue']}");
        $this->writeln("Job Class:  {$jobData['class']}");
        $this->writeln("Failed At:  {$failedAt}");
        $this->newLine();

        $this->writeln("Exception:");
        $this->line('-', 80);
        $this->writeln($jobData['exception']);
        $this->line('-', 80);
        $this->newLine();

        if (!empty($jobData['payload'])) {
            $this->writeln("Payload Preview:");
            $this->line('-', 80);
            $this->writeln($this->truncate($jobData['payload'], 500));
            $this->line('-', 80);
            $this->newLine();
        }

        return 0;
    }

    /**
     * Get detailed job data from database.
     *
     * @param string $jobId Job ID
     * @return array|null
     */
    private function getDatabaseJobDetails(string $jobId): ?array
    {
        $connection = $this->db->connection();
        $row = $connection->table('failed_jobs')
            ->where('id', $jobId)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => $row['id'],
            'queue' => $row['queue'] ?? 'default',
            'class' => $this->extractJobClass($row['payload']),
            'failed_at' => $row['failed_at'],
            'exception' => $row['exception'] ?? 'N/A',
            'payload' => $row['payload'] ?? '',
        ];
    }

    /**
     * Get detailed job data from Redis.
     *
     * @param string $jobId Job ID
     * @return array|null
     */
    private function getRedisJobDetails(string $jobId): ?array
    {
        /** @var RedisQueue $driver */
        $driver = $this->queueManager->driver();
        $redis = $this->getRedisConnection($driver);

        $failedJobKey = "failed_jobs:{$jobId}";
        $jobData = $redis->hGetAll($failedJobKey);

        if (empty($jobData)) {
            return null;
        }

        return [
            'id' => $jobId,
            'queue' => $jobData['queue'] ?? 'default',
            'class' => $this->extractJobClass($jobData['payload'] ?? ''),
            'failed_at' => $jobData['failed_at'] ?? time(),
            'exception' => $jobData['exception'] ?? 'N/A',
            'payload' => $jobData['payload'] ?? '',
        ];
    }

    /**
     * Extract job class name from serialized payload.
     *
     * @param string $payload Serialized payload
     * @return string Job class name
     */
    private function extractJobClass(string $payload): string
    {
        // Try to unserialize and get class name
        try {
            $job = unserialize($payload);
            if (is_object($job)) {
                return get_class($job);
            }
        } catch (\Throwable $e) {
            // Fallback: parse serialized string for class name
            if (preg_match('/O:\d+:"([^"]+)"/', $payload, $matches)) {
                return $matches[1];
            }
        }

        return 'Unknown';
    }

    /**
     * Truncate string to specified length.
     *
     * @param string $str String to truncate
     * @param int $maxLength Maximum length
     * @return string Truncated string
     */
    private function truncate(string $str, int $maxLength): string
    {
        if (strlen($str) <= $maxLength) {
            return $str;
        }

        return substr($str, 0, $maxLength - 3) . '...';
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
