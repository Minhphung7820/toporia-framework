<?php

declare(strict_types=1);

namespace Toporia\Framework\Audit\Drivers;

use DateTimeImmutable;
use Toporia\Framework\Audit\Contracts\AuditDriverInterface;
use Toporia\Framework\Audit\Contracts\AuditEntry;

/**
 * Class FileDriver
 *
 * File-based storage driver for audit logs.
 * Useful for development, debugging, or when database is not available.
 *
 * File format: JSON Lines (one JSON object per line)
 * File naming: audit-YYYY-MM-DD.log (daily rotation)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class FileDriver implements AuditDriverInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = []
    ) {}

    /**
     * Store a single audit entry.
     *
     * @param AuditEntry $entry
     * @return void
     */
    public function store(AuditEntry $entry): void
    {
        $this->appendToFile($entry);
    }

    /**
     * Store multiple audit entries.
     *
     * @param array<AuditEntry> $entries
     * @return void
     */
    public function storeBatch(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $content = implode("\n", $lines) . "\n";
        $filePath = $this->getFilePath();

        $this->ensureDirectory(dirname($filePath));

        file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get audit history for a model.
     *
     * @param string $modelType
     * @param int|string $modelId
     * @param int $limit
     * @return array<AuditEntry>
     */
    public function getHistory(string $modelType, int|string $modelId, int $limit = 50): array
    {
        $entries = [];
        $files = $this->getLogFiles();

        // Read files in reverse order (newest first)
        foreach (array_reverse($files) as $file) {
            $fileEntries = $this->readEntriesFromFile($file, function ($data) use ($modelType, $modelId) {
                return $data['model_type'] === $modelType
                    && (string) $data['model_id'] === (string) $modelId;
            });

            $entries = array_merge($entries, $fileEntries);

            if (count($entries) >= $limit) {
                break;
            }
        }

        // Sort by timestamp descending and limit
        usort($entries, fn($a, $b) => $b->timestamp <=> $a->timestamp);

        return array_slice($entries, 0, $limit);
    }

    /**
     * Get audit entries by user.
     *
     * @param int|string $userId
     * @param int $limit
     * @return array<AuditEntry>
     */
    public function getByUser(int|string $userId, int $limit = 50): array
    {
        $entries = [];
        $files = $this->getLogFiles();

        foreach (array_reverse($files) as $file) {
            $fileEntries = $this->readEntriesFromFile($file, function ($data) use ($userId) {
                return (string) ($data['user_id'] ?? '') === (string) $userId;
            });

            $entries = array_merge($entries, $fileEntries);

            if (count($entries) >= $limit) {
                break;
            }
        }

        usort($entries, fn($a, $b) => $b->timestamp <=> $a->timestamp);

        return array_slice($entries, 0, $limit);
    }

    /**
     * Append entry to file.
     *
     * @param AuditEntry $entry
     * @return void
     */
    protected function appendToFile(AuditEntry $entry): void
    {
        $filePath = $this->getFilePath();
        $this->ensureDirectory(dirname($filePath));

        $line = json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Read entries from file with optional filter.
     *
     * @param string $filePath
     * @param callable|null $filter
     * @return array<AuditEntry>
     */
    protected function readEntriesFromFile(string $filePath, ?callable $filter = null): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $entries = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);
                if (!is_array($data)) {
                    continue;
                }

                if ($filter !== null && !$filter($data)) {
                    continue;
                }

                $entries[] = AuditEntry::fromArray($data);
            }
        } finally {
            fclose($handle);
        }

        return $entries;
    }

    /**
     * Get all log files.
     *
     * @return array<string>
     */
    protected function getLogFiles(): array
    {
        $directory = $this->getDirectory();

        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '/audit-*.log');

        return $files !== false ? $files : [];
    }

    /**
     * Get current log file path.
     *
     * @return string
     */
    protected function getFilePath(): string
    {
        $directory = $this->getDirectory();
        $filename = 'audit-' . date('Y-m-d') . '.log';

        return $directory . '/' . $filename;
    }

    /**
     * Get log directory.
     *
     * @return string
     */
    protected function getDirectory(): string
    {
        $path = $this->config['path'] ?? null;

        if ($path !== null) {
            return $path;
        }

        // Default: storage/logs/audit
        $basePath = function_exists('storage_path')
            ? storage_path('logs/audit')
            : getcwd() . '/storage/logs/audit';

        return $basePath;
    }

    /**
     * Ensure directory exists.
     *
     * @param string $directory
     * @return void
     */
    protected function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
