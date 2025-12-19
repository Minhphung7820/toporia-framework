<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;

use Toporia\Framework\Database\Contracts\ConnectionInterface;
use Toporia\Framework\Database\Query\QueryBuilder;


/**
 * Trait HasBatchOperations
 *
 * Trait providing reusable functionality for HasBatchOperations in the
 * Concerns layer of the Toporia Framework.
 *
 * v2.0 Optimizations:
 * - Pre-computed placeholder template for large batches
 * - Cached prepared statements for repeated inserts of same column structure
 * - Optimized SQL string building using str_repeat + rtrim pattern
 * - Reduced array operations in hot path
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasBatchOperations
{
    /**
     * Cached prepared statements for batch inserts.
     * Key: table_columnCount_batchSize
     *
     * @var array<string, \PDOStatement>
     */
    private static array $insertStatementCache = [];

    /**
     * Insert multiple records in a single query.
     *
     * Performance: O(1) - Single INSERT query regardless of record count
     * Memory: O(n) - All records in memory at once
     *
     * For large datasets, use insertChunked() instead.
     *
     * @param array<array<string, mixed>> $records Array of attribute arrays
     * @param bool $ignoreErrors Whether to use INSERT IGNORE (skip duplicates)
     * @return int Number of inserted rows
     *
     * @example
     * ```php
     * UserModel::insertBatch([
     *     ['name' => 'John', 'email' => 'john@example.com'],
     *     ['name' => 'Jane', 'email' => 'jane@example.com'],
     * ]);
     * ```
     */
    public static function insertBatch(array $records, bool $ignoreErrors = false): int
    {
        return static::insert($records, $ignoreErrors);
    }

    /**
     * Insert multiple records in a single query (optimized).
     *
     * Key optimizations:
     * - Pre-computed single row placeholder template
     * - Uses str_repeat for bulk placeholder generation
     * - Flat bindings array built in single pass
     * - Cached prepared statements for same batch sizes
     *
     * @param array<array<string, mixed>> $records Array of attribute arrays
     * @param bool $ignoreErrors Whether to use INSERT IGNORE (skip duplicates)
     * @return int Number of inserted rows
     */
    public static function insert(array $records, bool $ignoreErrors = false): int
    {
        if (empty($records)) {
            return 0;
        }

        $table = static::getTableName();
        $columns = array_keys($records[0]);
        $columnCount = count($columns);
        $recordCount = count($records);

        // Build columns clause once
        $columnsClause = '`' . implode('`, `', $columns) . '`';

        // Build single row placeholder template: (?, ?, ?, ...)
        $rowPlaceholder = '(' . rtrim(str_repeat('?,', $columnCount), ',') . ')';

        // Build all placeholders using str_repeat (much faster than loop + implode)
        $valuesClause = rtrim(str_repeat($rowPlaceholder . ',', $recordCount), ',');

        // Build flat bindings array in single pass
        $bindings = [];
        $bindings = array_merge(...array_map(
            fn($record) => array_map(
                fn($col) => $record[$col] ?? null,
                $columns
            ),
            $records
        ));

        // Build SQL
        $ignore = $ignoreErrors ? 'IGNORE ' : '';
        $sql = "INSERT {$ignore}INTO `{$table}` ({$columnsClause}) VALUES {$valuesClause}";

        // Execute with prepared statement
        $connection = static::getConnection();
        $stmt = $connection->getPdo()->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    /**
     * Ultra-fast insert for very large datasets (1M+ rows).
     *
     * Uses prepared statement caching and optimized binding.
     * Best for repeated inserts with same column structure.
     *
     * @param array<array<string, mixed>> $records Array of attribute arrays
     * @param int $batchSize Records per batch (default: 2000)
     * @param bool $ignoreErrors Whether to use INSERT IGNORE
     * @return int Total number of inserted rows
     */
    public static function insertFast(array $records, int $batchSize = 2000, bool $ignoreErrors = false): int
    {
        if (empty($records)) {
            return 0;
        }

        $table = static::getTableName();
        $columns = array_keys($records[0]);
        $columnCount = count($columns);
        $connection = static::getConnection();
        $pdo = $connection->getPdo();

        // Pre-compute column clause
        $columnsClause = '`' . implode('`, `', $columns) . '`';

        // Process in chunks
        $total = 0;
        $chunks = array_chunk($records, $batchSize);

        foreach ($chunks as $chunk) {
            $chunkSize = count($chunk);

            // Get or create cached statement for this batch size
            $cacheKey = "{$table}_{$columnCount}_{$chunkSize}";

            if (!isset(self::$insertStatementCache[$cacheKey])) {
                $rowPlaceholder = '(' . rtrim(str_repeat('?,', $columnCount), ',') . ')';
                $valuesClause = rtrim(str_repeat($rowPlaceholder . ',', $chunkSize), ',');
                $ignore = $ignoreErrors ? 'IGNORE ' : '';
                $sql = "INSERT {$ignore}INTO `{$table}` ({$columnsClause}) VALUES {$valuesClause}";

                self::$insertStatementCache[$cacheKey] = $pdo->prepare($sql);
            }

            $stmt = self::$insertStatementCache[$cacheKey];

            // Build flat bindings
            $bindings = [];
            foreach ($chunk as $record) {
                foreach ($columns as $column) {
                    $bindings[] = $record[$column] ?? null;
                }
            }

            $stmt->execute($bindings);
            $total += $stmt->rowCount();
        }

        return $total;
    }

    /**
     * Insert multiple records in chunks to avoid memory issues.
     *
     * Performance: O(n/chunkSize) - Multiple queries but memory efficient
     * Memory: O(chunkSize) - Only chunkSize records in memory at once
     *
     * @param array<array<string, mixed>> $records Array of attribute arrays
     * @param int $chunkSize Number of records per chunk (default: 500)
     * @param bool $ignoreErrors Whether to use INSERT IGNORE
     * @return int Total number of inserted rows
     *
     * @example
     * ```php
     * // Insert 10,000 records in chunks of 500
     * UserModel::insertChunked($largeArray, 500);
     * ```
     */
    public static function insertChunked(array $records, int $chunkSize = 500, bool $ignoreErrors = false): int
    {
        if (empty($records)) {
            return 0;
        }

        $total = 0;
        $chunks = array_chunk($records, $chunkSize);

        foreach ($chunks as $chunk) {
            $total += static::insertBatch($chunk, $ignoreErrors);
        }

        return $total;
    }

    /**
     * Update multiple records efficiently.
     *
     * Uses CASE statements for bulk updates in a single query.
     *
     * Performance: O(1) - Single UPDATE query
     *
     * @param array<int|string, array<string, mixed>> $updates Map of ID => attributes
     * @return int Number of updated rows
     *
     * @example
     * ```php
     * UserModel::updateBatch([
     *     1 => ['name' => 'John Updated', 'email' => 'john@new.com'],
     *     2 => ['name' => 'Jane Updated', 'email' => 'jane@new.com'],
     * ]);
     * ```
     */
    public static function updateBatch(array $updates): int
    {
        if (empty($updates)) {
            return 0;
        }

        $table = static::getTableName();
        $primaryKey = static::getPrimaryKey();
        $ids = array_keys($updates);

        // Get all columns that need updating
        $columns = [];
        foreach ($updates as $update) {
            $columns = array_merge($columns, array_keys($update));
        }
        $columns = array_unique($columns);
        $columns = array_diff($columns, [$primaryKey]); // Exclude primary key

        if (empty($columns)) {
            return 0;
        }

        // Build CASE statements for each column
        $cases = [];
        $bindings = [];

        foreach ($columns as $column) {
            $caseSql = "`{$column}` = CASE `{$primaryKey}`";
            foreach ($updates as $id => $update) {
                $value = $update[$column] ?? null;
                $caseSql .= " WHEN ? THEN ?";
                $bindings[] = $id;
                $bindings[] = $value;
            }
            $caseSql .= " END";
            $cases[] = $caseSql;
        }

        $casesClause = implode(', ', $cases);
        $idsPlaceholders = implode(', ', array_fill(0, count($ids), '?'));
        $bindings = array_merge($bindings, $ids);

        $sql = "UPDATE `{$table}` SET {$casesClause} WHERE `{$primaryKey}` IN ({$idsPlaceholders})";

        $connection = static::getConnection();
        $stmt = $connection->getPdo()->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    /**
     * Delete multiple records by IDs.
     *
     * Performance: O(1) - Single DELETE query
     *
     * @param array<int|string> $ids Array of primary key values
     * @return int Number of deleted rows
     *
     * @example
     * ```php
     * UserModel::deleteBatch([1, 2, 3, 4, 5]);
     * ```
     */
    public static function deleteBatch(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $table = static::getTableName();
        $primaryKey = static::getPrimaryKey();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $sql = "DELETE FROM `{$table}` WHERE `{$primaryKey}` IN ({$placeholders})";

        $connection = static::getConnection();
        $stmt = $connection->getPdo()->prepare($sql);
        $stmt->execute($ids);

        return $stmt->rowCount();
    }

    /**
     * Upsert (insert or update) multiple records.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for MySQL.
     *
     * Performance: O(1) - Single query
     *
     * @param array<array<string, mixed>> $records Array of attribute arrays
     * @param array<string> $uniqueKeys Columns that define uniqueness
     * @param array<string>|null $updateColumns Columns to update (null = all non-unique)
     * @return int Number of affected rows
     *
     * @example
     * ```php
     * UserModel::upsertBatch([
     *     ['email' => 'john@example.com', 'name' => 'John'],
     *     ['email' => 'jane@example.com', 'name' => 'Jane'],
     * ], ['email']);
     * ```
     */
    public static function upsertBatch(array $records, array $uniqueKeys, ?array $updateColumns = null): int
    {
        return static::upsert($records, $uniqueKeys, $updateColumns);
    }

    /**
     * Upsert (insert or update) multiple records (optimized).
     *
     * @param array<array<string, mixed>> $records Array of attribute arrays
     * @param array<string> $uniqueKeys Columns that define uniqueness
     * @param array<string>|null $updateColumns Columns to update (null = all non-unique)
     * @return int Number of affected rows
     */
    public static function upsert(array $records, array $uniqueKeys, ?array $updateColumns = null): int
    {
        if (empty($records)) {
            return 0;
        }

        $table = static::getTableName();
        $columns = array_keys($records[0]);
        $columnCount = count($columns);
        $recordCount = count($records);

        // Determine columns to update
        $updateCols = $updateColumns ?? array_diff($columns, $uniqueKeys);

        // Build columns clause
        $columnsClause = '`' . implode('`, `', $columns) . '`';

        // Build placeholders using optimized pattern
        $rowPlaceholder = '(' . rtrim(str_repeat('?,', $columnCount), ',') . ')';
        $valuesClause = rtrim(str_repeat($rowPlaceholder . ',', $recordCount), ',');

        // Build flat bindings
        $bindings = [];
        foreach ($records as $record) {
            foreach ($columns as $column) {
                $bindings[] = $record[$column] ?? null;
            }
        }

        // Build ON DUPLICATE KEY UPDATE clause
        $updateParts = [];
        foreach ($updateCols as $column) {
            $updateParts[] = "`{$column}` = VALUES(`{$column}`)";
        }
        $updateClause = implode(', ', $updateParts);

        $sql = "INSERT INTO `{$table}` ({$columnsClause}) VALUES {$valuesClause} ON DUPLICATE KEY UPDATE {$updateClause}";

        $connection = static::getConnection();
        $stmt = $connection->getPdo()->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    /**
     * Clear the prepared statement cache.
     *
     * Call this if you need to free memory after large batch operations.
     *
     * @return void
     */
    public static function clearInsertCache(): void
    {
        self::$insertStatementCache = [];
    }

    /**
     * Get the table name.
     *
     * @return string
     */
    abstract public static function getTableName(): string;

    /**
     * Get the primary key column name.
     *
     * @return string
     */
    abstract public static function getPrimaryKey(): string;

    /**
     * Get the database connection.
     *
     * @return \Toporia\Framework\Database\Contracts\ConnectionInterface
     */
    abstract protected static function getConnection(): ConnectionInterface;
}
