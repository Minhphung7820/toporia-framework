<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Schema;

/**
 * Foreign ID Column Definition
 *
 * Extends ColumnDefinition to add constrained() method for fluent syntax.
 * Allows: $table->foreignId('user_id')->constrained()
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
class ForeignIdColumnDefinition extends ColumnDefinition
{
    /**
     * @param array $column Reference to column definition array.
     * @param Blueprint $blueprint Blueprint instance for creating foreign key.
     * @param string $columnName Column name for foreign key creation.
     */
    public function __construct(
        array &$column,
        private Blueprint $blueprint,
        private string $columnName
    ) {
        parent::__construct($column);
    }

    /**
     * Create foreign key constraint using fluent syntax.
     *
     * Infers table name from column name if not provided.
     * Example: 'user_id' -> references 'id' on 'users' table.
     *
     * @param string|null $table Referenced table name (inferred if null).
     * @param string $column Referenced column (default: 'id').
     * @return ForeignKeyDefinition
     */
    public function constrained(?string $table = null, string $column = 'id'): ForeignKeyDefinition
    {
        // If table not provided, infer from column name
        if ($table === null) {
            $table = str_replace('_id', '', $this->columnName);
            // Simple pluralization: add 's' (can be improved with proper pluralization)
            if (!str_ends_with($table, 's')) {
                $table .= 's';
            }
        }

        // Create foreign key constraint
        return $this->blueprint->foreign($this->columnName)->references($table, $column);
    }
}
