<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query\Concerns;

use Closure;
use Generator;
use Toporia\Framework\Database\Query\RowCollection;

/**
 * Trait BuildsChunking
 *
 * Chunking and lazy loading builders for Query Builder.
 * Provides efficient methods for processing large datasets.
 *
 * Features:
 * - Chunk processing: chunk, chunkById, each
 * - Lazy loading: lazy, lazyById, cursor
 * - Memory-efficient iteration
 * - Generator-based streaming
 *
 * Performance:
 * - O(1) memory usage (constant memory regardless of dataset size)
 * - Database-level pagination (LIMIT/OFFSET or cursor-based)
 * - Prevents memory exhaustion on large datasets
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles chunking/lazy loading
 * - Open/Closed: Extensible for new chunking strategies
 * - High Reusability: Works with any QueryBuilder instance
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Query\Concerns
 * @since       2025-01-22
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait BuildsChunking
{
    /**
     * Chunk the results of the query.
     *
     * Processes results in chunks to avoid loading all data into memory.
     * Useful for processing large datasets (e.g., batch jobs, data exports).
     *
     * Example:
     * ```php
     * DB::table('users')->chunk(100, function($users) {
     *     foreach ($users as $user) {
     *         // Process each user
     *         processUser($user);
     *     }
     * });
     * ```
     *
     * Performance:
     * - Memory: O(chunkSize) - Only loads one chunk at a time
     * - Time: O(N) where N = total records
     * - Database: Multiple queries (one per chunk)
     *
     * @param int $count Number of records per chunk
     * @param Closure $callback Callback receiving chunk collection
     * @return bool True if all chunks were processed
     */
    public function chunk(int $count, Closure $callback): bool
    {
        $page = 1;

        do {
            $results = $this->forPage($page, $count)->get();

            if ($results->isEmpty()) {
                break;
            }

            // Call callback with chunk
            if ($callback($results, $page) === false) {
                return false; // Stop chunking if callback returns false
            }

            $page++;
        } while ($results->count() === $count);

        return true;
    }

    /**
     * Chunk the results by ID.
     *
     * More efficient than chunk() for large datasets because it uses
     * WHERE id > lastId instead of OFFSET (which gets slower as offset increases).
     *
     * Example:
     * ```php
     * DB::table('users')->chunkById(100, function($users) {
     *     foreach ($users as $user) {
     *         processUser($user);
     *     }
     * });
     * ```
     *
     * Performance:
     * - Memory: O(chunkSize) - Constant memory usage
     * - Time: O(N) - Linear time complexity
     * - Database: Uses indexed WHERE id > lastId (faster than OFFSET)
     *
     * @param int $count Number of records per chunk
     * @param Closure $callback Callback receiving chunk collection
     * @param string|null $column ID column name (default: 'id')
     * @param string|null $alias Alias for the ID column in results
     * @return bool True if all chunks were processed
     */
    public function chunkById(int $count, Closure $callback, ?string $column = null, ?string $alias = null): bool
    {
        $column = $column ?? 'id';
        $alias = $alias ?? $column;
        $lastId = null;

        do {
            $query = clone $this;

            if ($lastId !== null) {
                $query->where($column, '>', $lastId);
            }

            $results = $query->orderBy($column, 'ASC')->limit($count)->get();

            if ($results->isEmpty()) {
                break;
            }

            // Call callback with chunk
            if ($callback($results) === false) {
                return false; // Stop chunking if callback returns false
            }

            // Get last ID from chunk
            $lastRow = $results->last();
            $lastId = $lastRow[$alias] ?? null;

            if ($lastId === null) {
                break;
            }
        } while ($results->count() === $count);

        return true;
    }

    /**
     * Execute a callback over each item while chunking.
     *
     * Convenience method that chunks results and calls callback for each item.
     *
     * Example:
     * ```php
     * DB::table('users')->each(function($user) {
     *     processUser($user);
     * });
     * ```
     *
     * Performance: Same as chunk() - O(chunkSize) memory
     *
     * @param Closure $callback Callback receiving each item
     * @param int $count Number of records per chunk
     * @return bool True if all items were processed
     */
    public function each(Closure $callback, int $count = 1000): bool
    {
        return $this->chunk($count, function ($results) use ($callback) {
            foreach ($results as $item) {
                if ($callback($item) === false) {
                    return false; // Stop processing if callback returns false
                }
            }
        });
    }

    /**
     * Execute a callback over each item while chunking by ID.
     *
     * More efficient than each() for large datasets.
     *
     * Example:
     * ```php
     * DB::table('users')->eachById(function($user) {
     *     processUser($user);
     * });
     * ```
     *
     * Performance: Same as chunkById() - O(chunkSize) memory, faster than each()
     *
     * @param Closure $callback Callback receiving each item
     * @param int $count Number of records per chunk
     * @param string|null $column ID column name (default: 'id')
     * @param string|null $alias Alias for the ID column
     * @return bool True if all items were processed
     */
    public function eachById(Closure $callback, int $count = 1000, ?string $column = null, ?string $alias = null): bool
    {
        return $this->chunkById($count, function ($results) use ($callback) {
            foreach ($results as $item) {
                if ($callback($item) === false) {
                    return false; // Stop processing if callback returns false
                }
            }
        }, $column, $alias);
    }

    /**
     * Get a lazy collection for the query.
     *
     * Returns a generator that yields results one at a time.
     * Memory-efficient for large datasets.
     *
     * Example:
     * ```php
     * $users = DB::table('users')->lazy();
     * foreach ($users as $user) {
     *     processUser($user);
     * }
     * ```
     *
     * Performance:
     * - Memory: O(1) - Only one record in memory at a time
     * - Time: O(N) - Processes all records
     * - Database: Single query with LIMIT/OFFSET pagination
     *
     * @param int $chunkSize Number of records to fetch per database query
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function lazy(int $chunkSize = 1000): Generator
    {
        $page = 1;

        do {
            $results = $this->forPage($page, $chunkSize)->get();

            if ($results->isEmpty()) {
                break;
            }

            foreach ($results as $item) {
                yield $item;
            }

            $page++;
        } while ($results->count() === $chunkSize);
    }

    /**
     * Get a lazy collection by ID.
     *
     * More efficient than lazy() for large datasets.
     *
     * Example:
     * ```php
     * $users = DB::table('users')->lazyById();
     * foreach ($users as $user) {
     *     processUser($user);
     * }
     * ```
     *
     * Performance:
     * - Memory: O(1) - Only one record in memory at a time
     * - Time: O(N) - Processes all records
     * - Database: Uses indexed WHERE id > lastId (faster than OFFSET)
     *
     * @param int $chunkSize Number of records to fetch per database query
     * @param string|null $column ID column name (default: 'id')
     * @param string|null $alias Alias for the ID column
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): Generator
    {
        $column = $column ?? 'id';
        $alias = $alias ?? $column;
        $lastId = null;

        do {
            $query = clone $this;

            if ($lastId !== null) {
                $query->where($column, '>', $lastId);
            }

            $results = $query->orderBy($column, 'ASC')->limit($chunkSize)->get();

            if ($results->isEmpty()) {
                break;
            }

            foreach ($results as $item) {
                yield $item;
                $lastId = $item[$alias] ?? null;
            }
        } while ($results->count() === $chunkSize);
    }

    /**
     * Get a cursor for the query.
     *
     * Returns a generator that uses PDO's fetch() to stream results.
     * Most memory-efficient method for large datasets.
     *
     * Example:
     * ```php
     * $users = DB::table('users')->cursor();
     * foreach ($users as $user) {
     *     processUser($user);
     * }
     * ```
     *
     * Performance:
     * - Memory: O(1) - Only one record in memory at a time
     * - Time: O(N) - Processes all records
     * - Database: Single query, PDO streams results
     *
     * Note: Cursor keeps database connection open during iteration.
     * Don't use for long-running processes that need connection pooling.
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function cursor(): Generator
    {
        $sql = $this->toSql();
        $statement = $this->connection->getPdo()->prepare($sql);

        // Bind parameters (flatten nested bindings for positional binding)
        $bindingValues = $this->getBindings();
        foreach ($bindingValues as $index => $value) {
            $type = match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                is_null($value) => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };
            $statement->bindValue($index + 1, $value, $type);
        }

        $statement->execute();
        $statement->setFetchMode(\PDO::FETCH_ASSOC);

        while ($row = $statement->fetch()) {
            yield $row;
        }
    }

    /**
     * Get results for a specific page.
     *
     * Helper method for pagination and chunking.
     *
     * Example:
     * ```php
     * $page2 = DB::table('users')->forPage(2, 10)->get();
     * // Gets records 11-20
     * ```
     *
     * Performance: O(1) - Just sets LIMIT and OFFSET
     *
     * @param int $page Page number (1-indexed)
     * @param int $perPage Number of records per page
     * @return $this
     */
    public function forPage(int $page, int $perPage = 15): self
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }
}
