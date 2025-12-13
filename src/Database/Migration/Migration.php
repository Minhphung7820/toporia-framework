<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Migration;

use Toporia\Framework\Database\Schema\Schema;
use Toporia\Framework\Database\Schema\SchemaBuilder;


/**
 * Abstract Class Migration
 *
 * Base class for database migrations providing up/down methods for
 * versioned schema changes and database structure evolution.
 *
 * Supports two syntax styles:
 * ```php
 * // Style 1: Instance property (backward compatible)
 * $this->schema->create('users', function ($table) { ... });
 *
 * // Style 2: Static facade (fluent)
 * Schema::create('users', function ($table) { ... });
 *
 * // Style 3: Multiple connections via facade
 * Schema::connection('mysql_logs')->create('logs', function ($table) { ... });
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Migration
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class Migration
{
    /**
     * @var SchemaBuilder Schema builder instance.
     */
    protected SchemaBuilder $schema;

    /**
     * The database connection that should be used by the migration.
     *
     * @var string|null
     */
    protected ?string $connection = null;

    /**
     * Set the schema builder.
     *
     * @param SchemaBuilder $schema
     * @return void
     */
    public function setSchema(SchemaBuilder $schema): void
    {
        $this->schema = $schema;
    }

    /**
     * Get SchemaBuilder for a specific connection.
     *
     * Allows using any connection dynamically within migrations.
     *
     * Example:
     * ```php
     * // Use migration's default connection
     * $this->schema()->create('users', ...);
     *
     * // Use specific connection
     * $this->schema('mysql_logs')->create('logs', ...);
     * $this->schema('pgsql')->create('analytics', ...);
     * ```
     *
     * @param string|null $connection Connection name (null uses migration's connection or default)
     * @return SchemaBuilder
     */
    protected function schema(?string $connection = null): SchemaBuilder
    {
        // If connection specified, use it directly
        if ($connection !== null) {
            return Schema::connection($connection);
        }

        // If migration has a specific connection, use it
        if ($this->connection !== null) {
            return Schema::connection($this->connection);
        }

        // Fall back to injected schema (default connection)
        return $this->schema;
    }

    /**
     * Get the migration connection name.
     *
     * @return string|null
     */
    public function getConnection(): ?string
    {
        return $this->connection;
    }

    /**
     * Run the migration (create tables/columns).
     *
     * @return void
     */
    abstract public function up(): void;

    /**
     * Reverse the migration (drop tables/columns).
     *
     * @return void
     */
    abstract public function down(): void;
}
