<?php

declare(strict_types=1);

namespace Toporia\Framework\Database;

use Toporia\Framework\Database\Contracts\{ConnectionInterface, GrammarInterface};
use Toporia\Framework\Database\Query\QueryBuilder;

/**
 * Connection Proxy for Fluent API
 *
 * Wraps ConnectionInterface to provide fluent API for QueryBuilder creation.
 * Implements ConnectionInterface to be a transparent proxy.
 * Enables syntax: DB()->connection('mysql')->table('users')
 *
 * Design Pattern: Proxy Pattern
 * - Provides simplified interface for QueryBuilder creation
 * - Maintains connection reference for performance
 * - Transparent proxy: delegates all ConnectionInterface methods
 *
 * SOLID Principles:
 * - Single Responsibility: Provides fluent API while maintaining ConnectionInterface contract
 * - Dependency Inversion: Depends on ConnectionInterface abstraction
 * - Liskov Substitution: Can be used anywhere ConnectionInterface is expected
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database
 * @since       2025-01-23
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class ConnectionProxy implements ConnectionInterface
{
    /**
     * @param ConnectionInterface $connection Database connection
     */
    public function __construct(
        private readonly ConnectionInterface $connection
    ) {}

    /**
     * Get the underlying connection instance.
     *
     * @return ConnectionInterface
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Get connection configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->connection->getConfig();
    }

    /**
     * Execute query in streaming mode for large datasets.
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @return \Generator<array>
     */
    public function executeStreaming(string $query, array $bindings = []): \Generator
    {
        return $this->connection->executeStreaming($query, $bindings);
    }

    /**
     * Check if streaming is supported for current driver.
     *
     * @return bool
     */
    public function supportsStreaming(): bool
    {
        return $this->connection->supportsStreaming();
    }

    /**
     * {@inheritdoc}
     */
    public function getPdo(): \PDO
    {
        return $this->connection->getPdo();
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $query, array $bindings = []): \PDOStatement
    {
        return $this->connection->execute($query, $bindings);
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        return $this->connection->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function rollback(): bool
    {
        return $this->connection->rollback();
    }

    /**
     * {@inheritdoc}
     */
    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(?string $name = null): string
    {
        return $this->connection->lastInsertId($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return $this->connection->getDriverName();
    }

    /**
     * {@inheritdoc}
     */
    public function getGrammar(): GrammarInterface
    {
        return $this->connection->getGrammar();
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        $this->connection->disconnect();
    }

    /**
     * {@inheritdoc}
     */
    public function reconnect(): void
    {
        $this->connection->reconnect();
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $query, array $bindings = []): array
    {
        return $this->connection->query($query, $bindings);
    }

    /**
     * {@inheritdoc}
     */
    public function select(string $query, array $bindings = []): array
    {
        return $this->connection->select($query, $bindings);
    }

    /**
     * {@inheritdoc}
     */
    public function selectOne(string $query, array $bindings = []): ?array
    {
        return $this->connection->selectOne($query, $bindings);
    }

    /**
     * {@inheritdoc}
     */
    public function affectingStatement(string $query, array $bindings = []): int
    {
        return $this->connection->affectingStatement($query, $bindings);
    }

    /**
     * {@inheritdoc}
     *
     * Create a QueryBuilder instance for the connection's table.
     * This method provides the fluent API that ConnectionProxy is designed for.
     *
     * Usage:
     * ```php
     * DB()->connection('mysql')->table('users')->where('status', 'active')->get();
     * DB()->connection('mongodb')->table('messages')->where('user_id', 123)->get();
     * ```
     *
     * Performance: Connection is cached, Grammar is cached per connection
     */
    public function table(string $table): QueryBuilder
    {
        // Delegate to underlying connection
        return $this->connection->table($table);
    }

    /**
     * {@inheritdoc}
     */
    public function unprepared(string $query): bool
    {
        return $this->connection->unprepared($query);
    }

    // =========================================================================
    // RAW SQL METHODS
    // =========================================================================

    /**
     * Execute a raw SQL statement (INSERT, UPDATE, DELETE).
     *
     * @param string $sql Raw SQL statement
     * @param array<int|string, mixed> $bindings Query parameter bindings
     * @return int Number of affected rows
     */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->connection->affectingStatement($sql, $bindings);
    }
}
