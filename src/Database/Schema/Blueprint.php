<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Schema;


/**
 * Class Blueprint
 *
 * Core class for the Schema layer providing essential functionality for
 * the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Schema
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class Blueprint
{
    /**
     * @var array<array> Column definitions.
     */
    private array $columns = [];

    /**
     * @var array<array> Index definitions.
     */
    private array $indexes = [];

    /**
     * @var array<array> Foreign key definitions.
     */
    private array $foreignKeys = [];

    /**
     * @var string|array|null Primary key column(s).
     */
    private string|array|null $primaryKey = null;

    /**
     * @var bool Enable timestamps (created_at, updated_at).
     */
    private bool $withTimestamps = false;

    /**
     * @var string|null Table comment.
     */
    private ?string $tableComment = null;

    /**
     * @var string|null Table engine (MySQL).
     */
    private ?string $engine = null;

    /**
     * @var string|null Table charset.
     */
    private ?string $charset = null;

    /**
     * @var string|null Table collation.
     */
    private ?string $collation = null;

    /**
     * @var bool Whether this is an ALTER TABLE operation.
     */
    private bool $isAlter = false;

    /**
     * @var string|null New table name for rename operation.
     */
    private ?string $renameTo = null;

    /**
     * @var array<string> Columns to drop.
     */
    private array $drops = [];

    /**
     * @var array<string> Indexes to drop.
     */
    private array $dropIndexes = [];

    /**
     * @var array<string> Foreign keys to drop.
     */
    private array $dropForeignKeys = [];

    /**
     * @param string $table Table name.
     * @param bool $isAlter Whether this is an ALTER TABLE operation.
     */
    public function __construct(
        private string $table,
        bool $isAlter = false
    ) {
        $this->isAlter = $isAlter;
    }

    // ============================================================================
    // Column Types
    // ============================================================================

    /**
     * Add auto-increment ID column.
     *
     * @param string $name Column name.
     * @return self
     */
    public function id(string $name = 'id'): self
    {
        $this->columns[] = [
            'name' => $name,
            'type' => 'bigInteger',
            'autoIncrement' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary' => true,
        ];

        $this->primaryKey = $name;

        return $this;
    }

    /**
     * Add big integer column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name);
    }

    /**
     * Add integer column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn('integer', $name);
    }

    /**
     * Add medium integer column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function mediumInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('mediumInteger', $name);
    }

    /**
     * Add small integer column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $name);
    }

    /**
     * Add tiny integer column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $name);
    }

    /**
     * Add unsigned big integer column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->bigInteger($name)->unsigned();
    }

    /**
     * Add foreign ID column (unsigned big integer).
     * Modern ORM helper with fluent constrained() support.
     *
     * Usage:
     * $table->foreignId('user_id')->constrained()
     * $table->foreignId('user_id')->constrained('users', 'id')
     * $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade')
     *
     * @param string $name Column name (default: table name + '_id').
     * @return ForeignIdColumnDefinition
     */
    public function foreignId(string $name): ForeignIdColumnDefinition
    {
        // Create column using same pattern as unsignedBigInteger
        $column = &$this->columns[];
        $column['name'] = $name;
        $column['type'] = 'unsignedBigInteger';
        $column['unsigned'] = true;
        $column['nullable'] = false;

        return new ForeignIdColumnDefinition($column, $this, $name);
    }

    /**
     * Add foreign ID column and create foreign key constraint.
     * Modern ORM helper.
     *
     * @param string $name Column name.
     * @param string $references Referenced column (default: 'id').
     * @param string $on Referenced table (if empty, inferred from column name).
     * @return ForeignKeyDefinition
     */
    public function foreignIdFor(string $name, string $references = 'id', string $on = ''): ForeignKeyDefinition
    {
        // If table name not provided, infer from column name
        // e.g., 'user_id' -> 'users', 'category_id' -> 'categories'
        if (empty($on)) {
            $on = str_replace('_id', '', $name);
            // Simple pluralization: add 's' (can be improved)
            if (!str_ends_with($on, 's')) {
                $on .= 's';
            }
        }

        $this->foreignId($name);
        return $this->foreign($name)->references($on, $references);
    }

    /**
     * Add unsigned integer column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function unsignedInteger(string $name): ColumnDefinition
    {
        return $this->integer($name)->unsigned();
    }

    /**
     * Add string column.
     *
     * @param string $name Column name.
     * @param int $length Max length.
     * @return ColumnDefinition
     */
    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('string', $name, ['length' => $length]);
    }

    /**
     * Add char column (fixed length).
     *
     * @param string $name Column name.
     * @param int $length Fixed length.
     * @return ColumnDefinition
     */
    public function char(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('char', $name, ['length' => $length]);
    }

    /**
     * Add text column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn('text', $name);
    }

    /**
     * Add medium text column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn('mediumText', $name);
    }

    /**
     * Add long text column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn('longText', $name);
    }

    /**
     * Add tiny text column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function tinyText(string $name): ColumnDefinition
    {
        return $this->addColumn('tinyText', $name);
    }

    /**
     * Add decimal column.
     *
     * @param string $name Column name.
     * @param int $precision Total digits.
     * @param int $scale Decimal digits.
     * @return ColumnDefinition
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $name, [
            'precision' => $precision,
            'scale' => $scale,
        ]);
    }

    /**
     * Add float column.
     *
     * @param string $name Column name.
     * @param int $precision Total digits.
     * @param int $scale Decimal digits.
     * @return ColumnDefinition
     */
    public function float(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('float', $name, [
            'precision' => $precision,
            'scale' => $scale,
        ]);
    }

    /**
     * Add double column.
     *
     * @param string $name Column name.
     * @param int $precision Total digits.
     * @param int $scale Decimal digits.
     * @return ColumnDefinition
     */
    public function double(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('double', $name, [
            'precision' => $precision,
            'scale' => $scale,
        ]);
    }

    /**
     * Add boolean column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn('boolean', $name);
    }

    /**
     * Add date column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn('date', $name);
    }

    /**
     * Add datetime column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function datetime(string $name): ColumnDefinition
    {
        return $this->addColumn('datetime', $name);
    }

    /**
     * Add timestamp column.
     *
     * @param string $name Column name.
     * @param int $precision Timestamp precision (0-6).
     * @return ColumnDefinition
     */
    public function timestamp(string $name, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn('timestamp', $name, ['precision' => $precision]);
    }

    /**
     * Add time column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function time(string $name): ColumnDefinition
    {
        return $this->addColumn('time', $name);
    }

    /**
     * Add year column (MySQL).
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function year(string $name): ColumnDefinition
    {
        return $this->addColumn('year', $name);
    }

    /**
     * Add JSON column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn('json', $name);
    }

    /**
     * Add JSONB column (PostgreSQL).
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function jsonb(string $name): ColumnDefinition
    {
        return $this->addColumn('jsonb', $name);
    }

    /**
     * Add binary column.
     *
     * @param string $name Column name.
     * @param int $length Length.
     * @return ColumnDefinition
     */
    public function binary(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('binary', $name, ['length' => $length]);
    }

    /**
     * Add blob column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function blob(string $name): ColumnDefinition
    {
        return $this->addColumn('blob', $name);
    }

    /**
     * Add long blob column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function longBlob(string $name): ColumnDefinition
    {
        return $this->addColumn('longBlob', $name);
    }

    /**
     * Add UUID column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function uuid(string $name = 'uuid'): ColumnDefinition
    {
        return $this->addColumn('uuid', $name);
    }

    /**
     * Add IP address column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function ipAddress(string $name = 'ip_address'): ColumnDefinition
    {
        return $this->string($name, 45);
    }

    /**
     * Add MAC address column.
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function macAddress(string $name = 'mac_address'): ColumnDefinition
    {
        return $this->string($name, 17);
    }

    /**
     * Add enum column.
     *
     * @param string $name Column name.
     * @param array<string> $values Enum values.
     * @return ColumnDefinition
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        return $this->addColumn('enum', $name, ['values' => $values]);
    }

    /**
     * Add set column (MySQL).
     *
     * @param string $name Column name.
     * @param array<string> $values Set values.
     * @return ColumnDefinition
     */
    public function set(string $name, array $values): ColumnDefinition
    {
        return $this->addColumn('set', $name, ['values' => $values]);
    }

    /**
     * Add geometry column (spatial).
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function geometry(string $name): ColumnDefinition
    {
        return $this->addColumn('geometry', $name);
    }

    /**
     * Add point column (spatial).
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function point(string $name): ColumnDefinition
    {
        return $this->addColumn('point', $name);
    }

    /**
     * Add line string column (spatial).
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function lineString(string $name): ColumnDefinition
    {
        return $this->addColumn('lineString', $name);
    }

    /**
     * Add polygon column (spatial).
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function polygon(string $name): ColumnDefinition
    {
        return $this->addColumn('polygon', $name);
    }

    /**
     * Add multi point column (spatial).
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function multiPoint(string $name): ColumnDefinition
    {
        return $this->addColumn('multiPoint', $name);
    }

    /**
     * Add multi line string column (spatial).
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function multiLineString(string $name): ColumnDefinition
    {
        return $this->addColumn('multiLineString', $name);
    }

    /**
     * Add multi polygon column (spatial).
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function multiPolygon(string $name): ColumnDefinition
    {
        return $this->addColumn('multiPolygon', $name);
    }

    /**
     * Add geometry collection column (spatial).
     *
     * @param string $name Column name.
     * @return ColumnDefinition
     */
    public function geometryCollection(string $name): ColumnDefinition
    {
        return $this->addColumn('geometryCollection', $name);
    }

    // ============================================================================
    // Table Modifiers
    // ============================================================================

    /**
     * Add created_at and updated_at timestamp columns.
     *
     * @param int $precision Timestamp precision (0-6).
     * @return self
     */
    public function timestamps(int $precision = 0): self
    {
        $this->withTimestamps = true;
        $this->timestamp('created_at', $precision)->nullable();
        $this->timestamp('updated_at', $precision)->nullable();
        return $this;
    }

    /**
     * Add nullable timestamp columns.
     *
     * @return self
     */
    public function nullableTimestamps(): self
    {
        return $this->timestamps();
    }

    /**
     * Add soft deletes column (deleted_at).
     *
     * @param string $column Column name.
     * @return ColumnDefinition
     */
    public function softDeletes(string $column = 'deleted_at'): ColumnDefinition
    {
        return $this->timestamp($column)->nullable();
    }

    /**
     * Add remember token column.
     *
     * @return ColumnDefinition
     */
    public function rememberToken(): ColumnDefinition
    {
        return $this->string('remember_token', 100)->nullable();
    }

    /**
     * Set table comment.
     *
     * @param string $comment Comment text.
     * @return self
     */
    public function comment(string $comment): self
    {
        $this->tableComment = $comment;
        return $this;
    }

    /**
     * Set table engine (MySQL).
     *
     * @param string $engine Engine name (e.g., 'InnoDB', 'MyISAM').
     * @return self
     */
    public function engine(string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * Set table charset.
     *
     * @param string $charset Charset name.
     * @return self
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set table collation.
     *
     * @param string $collation Collation name.
     * @return self
     */
    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    // ============================================================================
    // Indexes
    // ============================================================================

    /**
     * Add primary key constraint.
     *
     * @param string|array $columns Column(s) for primary key.
     * @param string|null $name Index name.
     * @return self
     */
    public function primary(string|array $columns, ?string $name = null): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->primaryKey = count($columns) === 1 ? $columns[0] : $columns;

        $this->indexes[] = [
            'type' => 'primary',
            'columns' => $columns,
            'name' => $name,
        ];

        return $this;
    }

    /**
     * Add unique index.
     *
     * @param string|array $columns Column(s) for unique index.
     * @param string|null $name Index name.
     * @return self
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = [
            'type' => 'unique',
            'columns' => is_array($columns) ? $columns : [$columns],
            'name' => $name,
        ];

        return $this;
    }

    /**
     * Add index.
     *
     * @param string|array $columns Column(s) for index.
     * @param string|null $name Index name.
     * @param string|null $algorithm Index algorithm (e.g., 'btree', 'hash').
     * @return self
     */
    public function index(string|array $columns, ?string $name = null, ?string $algorithm = null): self
    {
        $this->indexes[] = [
            'type' => 'index',
            'columns' => is_array($columns) ? $columns : [$columns],
            'name' => $name,
            'algorithm' => $algorithm,
        ];

        return $this;
    }

    /**
     * Add fulltext index (MySQL).
     *
     * @param string|array $columns Column(s) for fulltext index.
     * @param string|null $name Index name.
     * @return self
     */
    public function fullText(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = [
            'type' => 'fulltext',
            'columns' => is_array($columns) ? $columns : [$columns],
            'name' => $name,
        ];

        return $this;
    }

    /**
     * Add spatial index.
     *
     * @param string|array $columns Column(s) for spatial index.
     * @param string|null $name Index name.
     * @return self
     */
    public function spatialIndex(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = [
            'type' => 'spatial',
            'columns' => is_array($columns) ? $columns : [$columns],
            'name' => $name,
        ];

        return $this;
    }

    // ============================================================================
    // Foreign Keys
    // ============================================================================

    /**
     * Add foreign key constraint.
     *
     * @param string|array $columns Local column(s).
     * @param string|null $name Foreign key name.
     * @return ForeignKeyDefinition
     */
    public function foreign(string|array $columns, ?string $name = null): ForeignKeyDefinition
    {
        $columns = is_array($columns) ? $columns : [$columns];

        $foreignKey = [
            'type' => 'foreign',
            'columns' => $columns,
            'name' => $name,
        ];

        $this->foreignKeys[] = &$foreignKey;

        return new ForeignKeyDefinition($foreignKey);
    }

    // ============================================================================
    // ALTER TABLE Operations
    // ============================================================================

    /**
     * Drop a column.
     *
     * @param string|array $columns Column name(s) to drop.
     * @return self
     */
    public function dropColumn(string|array $columns): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->drops = array_merge($this->drops, $columns);
        return $this;
    }

    /**
     * Drop primary key.
     *
     * @param string|null $name Primary key name.
     * @return self
     */
    public function dropPrimary(?string $name = null): self
    {
        $this->drops[] = ['type' => 'primary', 'name' => $name];
        return $this;
    }

    /**
     * Drop unique index.
     *
     * @param string|array $index Index name or columns.
     * @return self
     */
    public function dropUnique(string|array $index): self
    {
        $this->dropIndexes[] = [
            'type' => 'unique',
            'name' => is_string($index) ? $index : null,
            'columns' => is_array($index) ? $index : null,
        ];
        return $this;
    }

    /**
     * Drop index.
     *
     * @param string|array $index Index name or columns.
     * @return self
     */
    public function dropIndex(string|array $index): self
    {
        $this->dropIndexes[] = [
            'type' => 'index',
            'name' => is_string($index) ? $index : null,
            'columns' => is_array($index) ? $index : null,
        ];
        return $this;
    }

    /**
     * Drop foreign key.
     *
     * @param string|array $foreignKey Foreign key name or columns.
     * @return self
     */
    public function dropForeign(string|array $foreignKey): self
    {
        $this->dropForeignKeys[] = is_string($foreignKey) ? $foreignKey : $foreignKey;
        return $this;
    }

    /**
     * Drop fulltext index.
     *
     * @param string|array $index Index name or columns.
     * @return self
     */
    public function dropFullText(string|array $index): self
    {
        $this->dropIndexes[] = [
            'type' => 'fulltext',
            'name' => is_string($index) ? $index : null,
            'columns' => is_array($index) ? $index : null,
        ];
        return $this;
    }

    /**
     * Drop spatial index.
     *
     * @param string|array $index Index name or columns.
     * @return self
     */
    public function dropSpatialIndex(string|array $index): self
    {
        $this->dropIndexes[] = [
            'type' => 'spatial',
            'name' => is_string($index) ? $index : null,
            'columns' => is_array($index) ? $index : null,
        ];
        return $this;
    }

    /**
     * Rename column.
     *
     * @param string $from Old column name.
     * @param string $to New column name.
     * @return self
     */
    public function renameColumn(string $from, string $to): self
    {
        $this->columns[] = [
            'name' => $from,
            'rename' => $to,
            'type' => 'rename',
        ];
        return $this;
    }

    /**
     * Rename table.
     *
     * @param string $to New table name.
     * @return self
     */
    public function rename(string $to): self
    {
        $this->renameTo = $to;
        return $this;
    }

    /**
     * Get new table name for rename operation.
     *
     * @return string|null
     */
    public function getRenameTo(): ?string
    {
        return $this->renameTo;
    }

    // ============================================================================
    // Helper Methods
    // ============================================================================

    /**
     * Add a column definition.
     *
     * @param string $type Column type.
     * @param string $name Column name.
     * @param array $attributes Additional attributes.
     * @return ColumnDefinition
     */
    private function addColumn(string $type, string $name, array $attributes = []): ColumnDefinition
    {
        $column = array_merge([
            'name' => $name,
            'type' => $type,
            'nullable' => false,
        ], $attributes);

        $this->columns[] = $column;

        return new ColumnDefinition($this->columns[array_key_last($this->columns)]);
    }

    // ============================================================================
    // Getters
    // ============================================================================

    /**
     * Get table name.
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get column definitions.
     *
     * @return array
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get index definitions.
     *
     * @return array
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Get foreign key definitions.
     *
     * @return array
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * Get primary key.
     *
     * @return string|array|null
     */
    public function getPrimaryKey(): string|array|null
    {
        return $this->primaryKey;
    }

    /**
     * Get columns to drop.
     *
     * @return array
     */
    public function getDrops(): array
    {
        return $this->drops;
    }

    /**
     * Get indexes to drop.
     *
     * @return array
     */
    public function getDropIndexes(): array
    {
        return $this->dropIndexes;
    }

    /**
     * Get foreign keys to drop.
     *
     * @return array
     */
    public function getDropForeignKeys(): array
    {
        return $this->dropForeignKeys;
    }

    /**
     * Check if this is an ALTER TABLE operation.
     *
     * @return bool
     */
    public function isAlter(): bool
    {
        return $this->isAlter;
    }

    /**
     * Get table comment.
     *
     * @return string|null
     */
    public function getTableComment(): ?string
    {
        return $this->tableComment;
    }

    /**
     * Get table engine.
     *
     * @return string|null
     */
    public function getEngine(): ?string
    {
        return $this->engine;
    }

    /**
     * Get table charset.
     *
     * @return string|null
     */
    public function getCharset(): ?string
    {
        return $this->charset;
    }

    /**
     * Get table collation.
     *
     * @return string|null
     */
    public function getCollation(): ?string
    {
        return $this->collation;
    }

    /**
     * Check if timestamps are enabled.
     *
     * @return bool
     */
    public function hasTimestamps(): bool
    {
        return $this->withTimestamps;
    }
}
