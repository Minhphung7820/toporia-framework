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
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasBatchOperations
{
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
        if (empty($records)) {
            return 0;
        }

        $table = static::getTableName();
        $columns = array_keys($records[0]);

        // Build VALUES clause
        $values = [];
        $bindings = [];
        $placeholders = [];

        foreach ($records as $record) {
            $rowPlaceholders = [];
            foreach ($columns as $column) {
                $value = $record[$column] ?? null;
                $rowPlaceholders[] = '?';
                $bindings[] = $value;
            }
            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $valuesClause = implode(', ', $placeholders);
        $columnsClause = implode(', ', array_map(fn($col) => "`{$col}`", $columns));

        // Build SQL
        $ignore = $ignoreErrors ? 'IGNORE' : '';
        $sql = "INSERT {$ignore} INTO `{$table}` ({$columnsClause}) VALUES {$valuesClause}";

        // Execute
        $connection = static::getConnection();
        $stmt = $connection->getPdo()->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
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
    public static function upsertBatch(array $records, array $uniqueKeys): int
    {
        if (empty($records)) {
            return 0;
        }

        $table = static::getTableName();
        $columns = array_keys($records[0]);
        $updateColumns = array_diff($columns, $uniqueKeys);

        // Build INSERT part
        $values = [];
        $bindings = [];
        $placeholders = [];

        foreach ($records as $record) {
            $rowPlaceholders = [];
            foreach ($columns as $column) {
                $value = $record[$column] ?? null;
                $rowPlaceholders[] = '?';
                $bindings[] = $value;
            }
            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $valuesClause = implode(', ', $placeholders);
        $columnsClause = implode(', ', array_map(fn($col) => "`{$col}`", $columns));

        // Build ON DUPLICATE KEY UPDATE part
        $updateClause = [];
        foreach ($updateColumns as $column) {
            $updateClause[] = "`{$column}` = VALUES(`{$column}`)";
        }
        $updateClause = implode(', ', $updateClause);

        $sql = "INSERT INTO `{$table}` ({$columnsClause}) VALUES {$valuesClause} ON DUPLICATE KEY UPDATE {$updateClause}";

        $connection = static::getConnection();
        $stmt = $connection->getPdo()->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
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

