<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Schema;

use Toporia\Framework\Database\Contracts\ConnectionInterface;


/**
 * Class SchemaBuilder
 *
 * Database schema builder for creating and modifying database tables,
 * columns, indexes, and foreign keys with support for multiple database
 * drivers (MySQL, PostgreSQL, SQLite).
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
class SchemaBuilder
{
    /**
     * @param ConnectionInterface $connection Database connection.
     */
    public function __construct(
        private ConnectionInterface $connection
    ) {}

    /**
     * Create a new table.
     *
     * @param string $table Table name.
     * @param callable $callback Callback receives Blueprint.
     * @return void
     */
    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $sql = $this->compileCreate($blueprint);

        $this->connection->execute($sql);
    }

    /**
     * Alter an existing table.
     *
     * @param string $table Table name.
     * @param callable $callback Callback receives Blueprint.
     * @return void
     */
    public function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, true);
        $callback($blueprint);

        // Handle table rename separately
        if ($renameTo = $blueprint->getRenameTo()) {
            $this->rename($table, $renameTo);
        }

        $sql = $this->compileAlter($blueprint);

        if (empty($sql)) {
            return;
        }

        // Execute multiple statements separately (for PostgreSQL/SQLite compatibility)
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $this->connection->execute($statement);
            }
        }
    }

    /**
     * Drop a table if exists.
     *
     * @param string $table Table name.
     * @return void
     */
    public function dropIfExists(string $table): void
    {
        $driver = $this->connection->getDriverName();
        $tableName = $this->quoteIdentifier($table, $driver);
        $sql = "DROP TABLE IF EXISTS {$tableName}";
        $this->connection->execute($sql);
    }

    /**
     * Drop a table.
     *
     * @param string $table Table name.
     * @return void
     */
    public function drop(string $table): void
    {
        $driver = $this->connection->getDriverName();
        $tableName = $this->quoteIdentifier($table, $driver);
        $sql = "DROP TABLE {$tableName}";
        $this->connection->execute($sql);
    }

    /**
     * Rename a table.
     *
     * @param string $from Old table name.
     * @param string $to New table name.
     * @return void
     */
    public function rename(string $from, string $to): void
    {
        $driver = $this->connection->getDriverName();
        $fromName = $this->quoteIdentifier($from, $driver);
        $toName = $this->quoteIdentifier($to, $driver);

        $sql = match ($driver) {
            'mysql' => "RENAME TABLE {$fromName} TO {$toName}",
            'pgsql', 'sqlite' => "ALTER TABLE {$fromName} RENAME TO {$toName}",
            default => throw new \RuntimeException("Unsupported driver: {$driver}")
        };

        $this->connection->execute($sql);
    }

    /**
     * Check if column exists in table.
     *
     * @param string $table Table name.
     * @param string $column Column name.
     * @return bool
     */
    public function hasColumn(string $table, string $column): bool
    {
        $driver = $this->connection->getDriverName();
        $tableName = $this->quoteIdentifier($table, $driver);

        // SECURITY: Use parameterized queries to prevent SQL injection
        return match ($driver) {
            'mysql' => $this->hasColumnMysql($tableName, $column),
            'pgsql' => $this->hasColumnPgsql($table, $column),
            'sqlite' => $this->hasColumnSqlite($tableName, $column),
            default => throw new \RuntimeException("Unsupported driver: {$driver}")
        };
    }

    /**
     * Check if column exists in MySQL table.
     */
    private function hasColumnMysql(string $tableName, string $column): bool
    {
        $sql = "SHOW COLUMNS FROM {$tableName} WHERE Field = ?";
        $result = $this->connection->selectOne($sql, [$column]);
        return $result !== null;
    }

    /**
     * Check if column exists in PostgreSQL table.
     */
    private function hasColumnPgsql(string $table, string $column): bool
    {
        $sql = "SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?";
        $result = $this->connection->selectOne($sql, [$table, $column]);
        return $result !== null;
    }

    /**
     * Check if column exists in SQLite table.
     */
    private function hasColumnSqlite(string $tableName, string $column): bool
    {
        $sql = "PRAGMA table_info({$tableName})";
        $results = $this->connection->select($sql);

        foreach ($results as $row) {
            if (($row['name'] ?? $row->name ?? null) === $column) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if table exists.
     *
     * SECURITY: Uses parameterized queries to prevent SQL injection.
     *
     * @param string $table Table name.
     * @return bool
     */
    public function hasTable(string $table): bool
    {
        $driver = $this->connection->getDriverName();

        // SECURITY: Use parameterized queries to prevent SQL injection
        return match ($driver) {
            'mysql' => $this->hasTableMysql($table),
            'pgsql' => $this->hasTablePgsql($table),
            'sqlite' => $this->hasTableSqlite($table),
            default => throw new \RuntimeException("Unsupported driver: {$driver}")
        };
    }

    /**
     * Check if table exists in MySQL.
     */
    private function hasTableMysql(string $table): bool
    {
        $sql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
        $result = $this->connection->selectOne($sql, [$table]);
        return $result !== null;
    }

    /**
     * Check if table exists in PostgreSQL.
     */
    private function hasTablePgsql(string $table): bool
    {
        $sql = "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = ?";
        $result = $this->connection->selectOne($sql, [$table]);
        return $result !== null;
    }

    /**
     * Check if table exists in SQLite.
     */
    private function hasTableSqlite(string $table): bool
    {
        $sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?";
        $result = $this->connection->selectOne($sql, [$table]);
        return $result !== null;
    }

    /**
     * Compile CREATE TABLE statement.
     *
     * @param Blueprint $blueprint Table blueprint.
     * @return string SQL statement.
     */
    private function compileCreate(Blueprint $blueprint): string
    {
        $driver = $this->connection->getDriverName();
        $tableName = $this->quoteIdentifier($blueprint->getTable(), $driver);

        $columns = array_map(
            fn($column) => $this->compileColumn($column, $driver, false),
            $blueprint->getColumns()
        );

        // Add primary key
        if ($pk = $blueprint->getPrimaryKey()) {
            if (is_array($pk)) {
                $cols = implode(', ', array_map(fn($col) => $this->quoteIdentifier($col, $driver), $pk));
                $columns[] = "PRIMARY KEY ({$cols})";
            } else {
                $columns[] = "PRIMARY KEY (" . $this->quoteIdentifier($pk, $driver) . ")";
            }
        }

        // Add indexes
        foreach ($blueprint->getIndexes() as $index) {
            $cols = implode(', ', array_map(fn($col) => $this->quoteIdentifier($col, $driver), $index['columns']));
            $indexName = $index['name'] ? $this->quoteIdentifier($index['name'], $driver) : null;

            match ($index['type']) {
                'unique' => $columns[] = $indexName ? "CONSTRAINT {$indexName} UNIQUE ({$cols})" : "UNIQUE ({$cols})",
                'index' => $columns[] = $indexName ? "INDEX {$indexName} ({$cols})" : "INDEX ({$cols})",
                'fulltext' => $columns[] = $indexName ? "FULLTEXT INDEX {$indexName} ({$cols})" : "FULLTEXT INDEX ({$cols})",
                'spatial' => $columns[] = $indexName ? "SPATIAL INDEX {$indexName} ({$cols})" : "SPATIAL INDEX ({$cols})",
                default => null,
            };
        }

        // Add foreign keys
        foreach ($blueprint->getForeignKeys() as $fk) {
            $localCols = implode(', ', array_map(fn($col) => $this->quoteIdentifier($col, $driver), $fk['columns']));
            $refCols = implode(', ', array_map(fn($col) => $this->quoteIdentifier($col, $driver), $fk['references']));
            $refTable = $this->quoteIdentifier($fk['on'], $driver);
            $fkName = $fk['name'] ? $this->quoteIdentifier($fk['name'], $driver) : null;

            $fkSql = $fkName ? "CONSTRAINT {$fkName} FOREIGN KEY ({$localCols}) REFERENCES {$refTable}({$refCols})"
                : "FOREIGN KEY ({$localCols}) REFERENCES {$refTable}({$refCols})";

            if (isset($fk['onDelete'])) {
                $fkSql .= " ON DELETE {$fk['onDelete']}";
            }
            if (isset($fk['onUpdate'])) {
                $fkSql .= " ON UPDATE {$fk['onUpdate']}";
            }

            $columns[] = $fkSql;
        }

        $columnsSql = implode(', ', $columns);

        $sql = "CREATE TABLE IF NOT EXISTS {$tableName} ({$columnsSql})";

        // Add table options
        if ($engine = $blueprint->getEngine()) {
            $sql .= " ENGINE={$engine}";
        }
        if ($charset = $blueprint->getCharset()) {
            $sql .= " DEFAULT CHARSET={$charset}";
        }
        if ($collation = $blueprint->getCollation()) {
            $sql .= " COLLATE={$collation}";
        }
        if ($comment = $blueprint->getTableComment()) {
            $commentEscaped = addslashes($comment);
            $sql .= " COMMENT='{$commentEscaped}'";
        }

        return $sql;
    }

    /**
     * Compile ALTER TABLE statement.
     *
     * @param Blueprint $blueprint Table blueprint.
     * @return string SQL statement.
     */
    private function compileAlter(Blueprint $blueprint): string
    {
        $driver = $this->connection->getDriverName();
        $tableName = $this->quoteIdentifier($blueprint->getTable(), $driver);
        $alterStatements = [];
        $separateStatements = [];

        // Drop columns
        foreach ($blueprint->getDrops() as $drop) {
            if (is_string($drop)) {
                $alterStatements[] = "DROP COLUMN " . $this->quoteIdentifier($drop, $driver);
            } elseif (is_array($drop) && $drop['type'] === 'primary') {
                $alterStatements[] = "DROP PRIMARY KEY";
            }
        }

        // Drop indexes (some drivers need separate DROP INDEX)
        foreach ($blueprint->getDropIndexes() as $dropIndex) {
            $indexName = $dropIndex['name'];
            $columns = $dropIndex['columns'];

            if ($indexName) {
                $indexNameQuoted = $this->quoteIdentifier($indexName, $driver);
                match ($driver) {
                    'mysql' => $alterStatements[] = "DROP INDEX {$indexNameQuoted}",
                    'pgsql', 'sqlite' => $separateStatements[] = "DROP INDEX {$indexNameQuoted}",
                    default => $alterStatements[] = "DROP INDEX {$indexNameQuoted}",
                };
            } elseif ($columns) {
                $indexName = $this->generateIndexName($blueprint->getTable(), $columns, $dropIndex['type']);
                $indexNameQuoted = $this->quoteIdentifier($indexName, $driver);
                match ($driver) {
                    'mysql' => $alterStatements[] = "DROP INDEX {$indexNameQuoted}",
                    'pgsql', 'sqlite' => $separateStatements[] = "DROP INDEX {$indexNameQuoted}",
                    default => $alterStatements[] = "DROP INDEX {$indexNameQuoted}",
                };
            }
        }

        // Drop foreign keys
        foreach ($blueprint->getDropForeignKeys() as $dropFk) {
            if (is_string($dropFk)) {
                $fkName = $this->quoteIdentifier($dropFk, $driver);
                match ($driver) {
                    'mysql' => $alterStatements[] = "DROP FOREIGN KEY {$fkName}",
                    'pgsql' => $alterStatements[] = "DROP CONSTRAINT {$fkName}",
                    'sqlite' => null, // SQLite doesn't support dropping foreign keys easily
                    default => $alterStatements[] = "DROP FOREIGN KEY {$fkName}",
                };
            }
        }

        // Add/modify columns
        foreach ($blueprint->getColumns() as $column) {
            if (isset($column['rename'])) {
                // Rename column
                $oldName = $this->quoteIdentifier($column['name'], $driver);
                $newName = $this->quoteIdentifier($column['rename'], $driver);
                $alterStatements[] = match ($driver) {
                    'mysql' => "CHANGE COLUMN {$oldName} {$newName} " . $this->getColumnType($column, $driver),
                    'pgsql' => "RENAME COLUMN {$oldName} TO {$newName}",
                    'sqlite' => "RENAME COLUMN {$oldName} TO {$newName}",
                    default => throw new \RuntimeException("Unsupported driver: {$driver}")
                };
            } elseif (isset($column['change']) || $this->isAlterColumn($column)) {
                // Modify column
                $alterStatements[] = match ($driver) {
                    'mysql' => "MODIFY COLUMN " . $this->compileColumn($column, $driver, true),
                    'pgsql' => "ALTER COLUMN " . $this->quoteIdentifier($column['name'], $driver) . " TYPE " . $this->getColumnType($column, $driver),
                    'sqlite' => null, // SQLite has limited ALTER TABLE support
                    default => "MODIFY COLUMN " . $this->compileColumn($column, $driver, true),
                };
            } else {
                // Add column
                $columnSql = match ($driver) {
                    'mysql' => "ADD COLUMN " . $this->compileColumn($column, $driver, true),
                    'pgsql' => "ADD COLUMN " . $this->compileColumn($column, $driver, true),
                    'sqlite' => "ADD COLUMN " . $this->compileColumn($column, $driver, true),
                    default => "ADD COLUMN " . $this->compileColumn($column, $driver, true),
                };

                if ($driver === 'mysql') {
                    if (isset($column['after'])) {
                        $columnSql .= " AFTER " . $this->quoteIdentifier($column['after'], $driver);
                    } elseif (isset($column['first'])) {
                        $columnSql .= " FIRST";
                    }
                }

                $alterStatements[] = $columnSql;
            }
        }

        // Add indexes (some need separate CREATE INDEX)
        foreach ($blueprint->getIndexes() as $index) {
            $cols = implode(', ', array_map(fn($col) => $this->quoteIdentifier($col, $driver), $index['columns']));
            $indexName = $index['name'] ? $this->quoteIdentifier($index['name'], $driver) : $this->generateIndexName($blueprint->getTable(), $index['columns'], $index['type']);

            match ($index['type']) {
                'primary' => $alterStatements[] = "ADD PRIMARY KEY ({$cols})",
                'unique' => match ($driver) {
                    'mysql' => $alterStatements[] = "ADD UNIQUE KEY {$indexName} ({$cols})",
                    'pgsql' => $alterStatements[] = "ADD CONSTRAINT {$indexName} UNIQUE ({$cols})",
                    'sqlite' => $alterStatements[] = "ADD UNIQUE ({$cols})",
                    default => $alterStatements[] = "ADD UNIQUE ({$cols})",
                },
                'index' => match ($driver) {
                    'mysql' => $alterStatements[] = "ADD INDEX {$indexName} ({$cols})",
                    'pgsql', 'sqlite' => $separateStatements[] = "CREATE INDEX {$indexName} ON {$tableName} ({$cols})",
                    default => $alterStatements[] = "ADD INDEX ({$cols})",
                },
                'fulltext' => match ($driver) {
                    'mysql' => $alterStatements[] = "ADD FULLTEXT INDEX {$indexName} ({$cols})",
                    default => null, // Not supported
                },
                'spatial' => match ($driver) {
                    'mysql', 'pgsql' => $alterStatements[] = "ADD SPATIAL INDEX {$indexName} ({$cols})",
                    default => null, // Not supported
                },
                default => null,
            };
        }

        // Add foreign keys
        foreach ($blueprint->getForeignKeys() as $fk) {
            $localCols = implode(', ', array_map(fn($col) => $this->quoteIdentifier($col, $driver), $fk['columns']));
            $refCols = implode(', ', array_map(fn($col) => $this->quoteIdentifier($col, $driver), $fk['references']));
            $refTable = $this->quoteIdentifier($fk['on'], $driver);
            $fkName = $fk['name'] ? $this->quoteIdentifier($fk['name'], $driver) : $this->generateIndexName($blueprint->getTable(), $fk['columns'], 'foreign');

            $fkSql = match ($driver) {
                'mysql' => $fkName ? "ADD CONSTRAINT {$fkName} FOREIGN KEY ({$localCols}) REFERENCES {$refTable}({$refCols})"
                    : "ADD FOREIGN KEY ({$localCols}) REFERENCES {$refTable}({$refCols})",
                'pgsql' => "ADD CONSTRAINT {$fkName} FOREIGN KEY ({$localCols}) REFERENCES {$refTable}({$refCols})",
                'sqlite' => "ADD FOREIGN KEY ({$localCols}) REFERENCES {$refTable}({$refCols})",
                default => "ADD FOREIGN KEY ({$localCols}) REFERENCES {$refTable}({$refCols})",
            };

            if (isset($fk['onDelete'])) {
                $fkSql .= " ON DELETE {$fk['onDelete']}";
            }
            if (isset($fk['onUpdate'])) {
                $fkSql .= " ON UPDATE {$fk['onUpdate']}";
            }

            $alterStatements[] = $fkSql;
        }

        // Combine all statements
        $allStatements = [];

        // ALTER TABLE statement
        if (!empty($alterStatements)) {
            $allStatements[] = "ALTER TABLE {$tableName} " . implode(', ', $alterStatements);
        }

        // Separate statements (CREATE INDEX, etc.)
        $allStatements = array_merge($allStatements, $separateStatements);

        return implode('; ', array_filter($allStatements));
    }

    /**
     * Generate index name from table and columns.
     *
     * @param string $table Table name.
     * @param array $columns Column names.
     * @param string $type Index type.
     * @return string Index name.
     */
    private function generateIndexName(string $table, array $columns, string $type): string
    {
        $prefix = match ($type) {
            'unique' => 'unique',
            'index' => 'index',
            'fulltext' => 'fulltext',
            'spatial' => 'spatial',
            default => 'index',
        };

        $suffix = implode('_', $columns);
        return "{$table}_{$prefix}_{$suffix}";
    }

    /**
     * Check if column should be treated as ALTER (modify existing).
     *
     * @param array $column Column definition.
     * @return bool
     */
    private function isAlterColumn(array $column): bool
    {
        // If column has 'change' flag or is being modified
        return isset($column['change']) || isset($column['modify']);
    }

    /**
     * Compile column definition.
     *
     * @param array $column Column definition.
     * @param string $driver Database driver.
     * @param bool $isAlter Whether this is for ALTER TABLE.
     * @return string Column SQL.
     */
    private function compileColumn(array $column, string $driver, bool $isAlter = false): string
    {
        $sql = $this->quoteIdentifier($column['name'], $driver) . ' ';

        $sql .= $this->getColumnType($column, $driver);

        if (!empty($column['unsigned'])) {
            $sql .= ' UNSIGNED';
        }

        if (!empty($column['autoIncrement'])) {
            $sql .= match ($driver) {
                'mysql' => ' AUTO_INCREMENT',
                'pgsql' => '', // Handled by SERIAL type
                'sqlite' => ' AUTOINCREMENT',
                default => ''
            };
        }

        if (empty($column['nullable'])) {
            $sql .= ' NOT NULL';
        }

        if (array_key_exists('default', $column)) {
            $default = $this->quoteValue($column['default']);
            $sql .= " DEFAULT {$default}";
        } elseif (isset($column['useCurrent']) && $column['useCurrent']) {
            $sql .= match ($driver) {
                'mysql' => ' DEFAULT CURRENT_TIMESTAMP',
                'pgsql' => ' DEFAULT CURRENT_TIMESTAMP',
                'sqlite' => ' DEFAULT CURRENT_TIMESTAMP',
                default => ''
            };
        }

        if (isset($column['useCurrentOnUpdate']) && $column['useCurrentOnUpdate']) {
            $sql .= match ($driver) {
                'mysql' => ' ON UPDATE CURRENT_TIMESTAMP',
                default => ''
            };
        }

        if (isset($column['comment'])) {
            $commentEscaped = addslashes($column['comment']);
            $sql .= match ($driver) {
                'mysql' => " COMMENT '{$commentEscaped}'",
                default => ''
            };
        }

        if (isset($column['charset'])) {
            $sql .= match ($driver) {
                'mysql' => " CHARACTER SET {$column['charset']}",
                default => ''
            };
        }

        if (isset($column['collation'])) {
            $sql .= match ($driver) {
                'mysql' => " COLLATE {$column['collation']}",
                default => ''
            };
        }

        return $sql;
    }

    /**
     * Get column type SQL.
     *
     * @param array $column Column definition.
     * @param string $driver Database driver.
     * @return string Type SQL.
     */
    private function getColumnType(array $column, string $driver): string
    {
        $type = $column['type'];

        return match ($type) {
            'bigInteger', 'unsignedBigInteger' => match ($driver) {
                'mysql' => 'BIGINT',
                'pgsql' => !empty($column['autoIncrement']) ? 'BIGSERIAL' : 'BIGINT',
                'sqlite' => 'INTEGER',
                default => 'BIGINT'
            },
            'integer' => match ($driver) {
                'mysql' => 'INT',
                'pgsql' => !empty($column['autoIncrement']) ? 'SERIAL' : 'INTEGER',
                'sqlite' => 'INTEGER',
                default => 'INTEGER'
            },
            'mediumInteger' => match ($driver) {
                'mysql' => 'MEDIUMINT',
                'pgsql' => 'INTEGER',
                'sqlite' => 'INTEGER',
                default => 'INTEGER'
            },
            'smallInteger' => match ($driver) {
                'mysql' => 'SMALLINT',
                'pgsql' => 'SMALLINT',
                'sqlite' => 'INTEGER',
                default => 'SMALLINT'
            },
            'tinyInteger' => match ($driver) {
                'mysql' => 'TINYINT',
                'pgsql' => 'SMALLINT',
                'sqlite' => 'INTEGER',
                default => 'TINYINT'
            },
            'string' => match ($driver) {
                'mysql', 'pgsql' => 'VARCHAR(' . ($column['length'] ?? 255) . ')',
                'sqlite' => 'TEXT',
                default => 'VARCHAR(255)'
            },
            'char' => match ($driver) {
                'mysql', 'pgsql' => 'CHAR(' . ($column['length'] ?? 255) . ')',
                'sqlite' => 'TEXT',
                default => 'CHAR(255)'
            },
            'text' => 'TEXT',
            'mediumText' => match ($driver) {
                'mysql' => 'MEDIUMTEXT',
                'pgsql', 'sqlite' => 'TEXT',
                default => 'TEXT'
            },
            'longText' => match ($driver) {
                'mysql' => 'LONGTEXT',
                'pgsql', 'sqlite' => 'TEXT',
                default => 'TEXT'
            },
            'tinyText' => match ($driver) {
                'mysql' => 'TINYTEXT',
                'pgsql', 'sqlite' => 'TEXT',
                default => 'TEXT'
            },
            'decimal' => sprintf(
                'DECIMAL(%d, %d)',
                $column['precision'] ?? 10,
                $column['scale'] ?? 2
            ),
            'float' => sprintf(
                'FLOAT(%d, %d)',
                $column['precision'] ?? 8,
                $column['scale'] ?? 2
            ),
            'double' => sprintf(
                'DOUBLE(%d, %d)',
                $column['precision'] ?? 8,
                $column['scale'] ?? 2
            ),
            'boolean' => match ($driver) {
                'mysql' => 'TINYINT(1)',
                'pgsql' => 'BOOLEAN',
                'sqlite' => 'INTEGER',
                default => 'BOOLEAN'
            },
            'date' => 'DATE',
            'datetime' => match ($driver) {
                'mysql' => 'DATETIME',
                'pgsql' => 'TIMESTAMP',
                'sqlite' => 'TEXT',
                default => 'DATETIME'
            },
            'timestamp' => match ($driver) {
                'mysql' => 'TIMESTAMP',
                'pgsql' => 'TIMESTAMP',
                'sqlite' => 'TEXT',
                default => 'TIMESTAMP'
            },
            'time' => 'TIME',
            'year' => match ($driver) {
                'mysql' => 'YEAR',
                'pgsql' => 'INTEGER',
                'sqlite' => 'INTEGER',
                default => 'INTEGER'
            },
            'json' => match ($driver) {
                'mysql' => 'JSON',
                'pgsql' => 'JSONB',
                'sqlite' => 'TEXT',
                default => 'TEXT'
            },
            'jsonb' => match ($driver) {
                'pgsql' => 'JSONB',
                'mysql', 'sqlite' => 'JSON',
                default => 'JSON'
            },
            'binary' => match ($driver) {
                'mysql' => 'BINARY(' . ($column['length'] ?? 255) . ')',
                'pgsql' => 'BYTEA',
                'sqlite' => 'BLOB',
                default => 'BINARY'
            },
            'blob' => match ($driver) {
                'mysql' => 'BLOB',
                'pgsql' => 'BYTEA',
                'sqlite' => 'BLOB',
                default => 'BLOB'
            },
            'longBlob' => match ($driver) {
                'mysql' => 'LONGBLOB',
                'pgsql' => 'BYTEA',
                'sqlite' => 'BLOB',
                default => 'BLOB'
            },
            'uuid' => match ($driver) {
                'pgsql' => 'UUID',
                'mysql' => 'CHAR(36)',
                'sqlite' => 'TEXT',
                default => 'CHAR(36)'
            },
            'enum' => match ($driver) {
                'mysql' => 'ENUM(' . implode(', ', array_map(fn($v) => "'" . addslashes($v) . "'", $column['values'] ?? [])) . ')',
                'pgsql' => 'VARCHAR', // PostgreSQL uses CHECK constraint instead
                'sqlite' => 'TEXT',
                default => 'VARCHAR'
            },
            'set' => match ($driver) {
                'mysql' => 'SET(' . implode(', ', array_map(fn($v) => "'" . addslashes($v) . "'", $column['values'] ?? [])) . ')',
                'pgsql' => 'VARCHAR',
                'sqlite' => 'TEXT',
                default => 'VARCHAR'
            },
            'geometry' => match ($driver) {
                'mysql', 'pgsql' => 'GEOMETRY',
                'sqlite' => 'BLOB',
                default => 'GEOMETRY'
            },
            'point' => match ($driver) {
                'mysql', 'pgsql' => 'POINT',
                'sqlite' => 'BLOB',
                default => 'POINT'
            },
            'lineString' => match ($driver) {
                'mysql', 'pgsql' => 'LINESTRING',
                'sqlite' => 'BLOB',
                default => 'LINESTRING'
            },
            'polygon' => match ($driver) {
                'mysql', 'pgsql' => 'POLYGON',
                'sqlite' => 'BLOB',
                default => 'POLYGON'
            },
            'multiPoint' => match ($driver) {
                'mysql', 'pgsql' => 'MULTIPOINT',
                'sqlite' => 'BLOB',
                default => 'MULTIPOINT'
            },
            'multiLineString' => match ($driver) {
                'mysql', 'pgsql' => 'MULTILINESTRING',
                'sqlite' => 'BLOB',
                default => 'MULTILINESTRING'
            },
            'multiPolygon' => match ($driver) {
                'mysql', 'pgsql' => 'MULTIPOLYGON',
                'sqlite' => 'BLOB',
                default => 'MULTIPOLYGON'
            },
            'geometryCollection' => match ($driver) {
                'mysql', 'pgsql' => 'GEOMETRYCOLLECTION',
                'sqlite' => 'BLOB',
                default => 'GEOMETRYCOLLECTION'
            },
            default => strtoupper($type)
        };
    }

    /**
     * Quote value for SQL.
     *
     * @param mixed $value Value to quote.
     * @return string Quoted value.
     */
    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . addslashes((string) $value) . "'";
    }

    /**
     * Quote identifier (table/column name) based on driver.
     *
     * @param string $identifier Identifier to quote.
     * @param string $driver Database driver.
     * @return string Quoted identifier.
     */
    private function quoteIdentifier(string $identifier, string $driver): string
    {
        return match ($driver) {
            'mysql' => "`{$identifier}`",
            'pgsql' => "\"{$identifier}\"",
            'sqlite' => "`{$identifier}`",
            default => $identifier
        };
    }
}
