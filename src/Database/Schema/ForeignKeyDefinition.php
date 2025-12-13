<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Schema;


/**
 * Class ForeignKeyDefinition
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
class ForeignKeyDefinition
{
    /**
     * @param array $foreignKey Reference to foreign key definition array.
     */
    public function __construct(
        private array &$foreignKey
    ) {}

    /**
     * Set referenced table and column.
     *
     * @param string $table Referenced table name.
     * @param string|array $columns Referenced column(s).
     * @return self
     */
    public function references(string $table, string|array $columns): self
    {
        $this->foreignKey['on'] = $table;
        $this->foreignKey['references'] = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    /**
     * Set on delete action.
     *
     * @param string $action Action: 'cascade', 'restrict', 'set null', 'no action'.
     * @return self
     */
    public function onDelete(string $action): self
    {
        $this->foreignKey['onDelete'] = strtoupper($action);
        return $this;
    }

    /**
     * Set on update action.
     *
     * @param string $action Action: 'cascade', 'restrict', 'set null', 'no action'.
     * @return self
     */
    public function onUpdate(string $action): self
    {
        $this->foreignKey['onUpdate'] = strtoupper($action);
        return $this;
    }

    /**
     * Set foreign key name.
     *
     * @param string $name Foreign key name.
     * @return self
     */
    public function name(string $name): self
    {
        $this->foreignKey['name'] = $name;
        return $this;
    }

    /**
     * Set referenced table and column using standard constrained() pattern.
     *
     * Infers table name from foreign key column name.
     * Example: 'user_id' -> references 'id' on 'users' table.
     *
     * @param string $table Table name (if empty, inferred from column name).
     * @param string $column Referenced column (default: 'id').
     * @return self
     */
    public function constrained(string $table = '', string $column = 'id'): self
    {
        // If table not provided, infer from first column name
        if (empty($table) && !empty($this->foreignKey['columns'])) {
            $firstColumn = $this->foreignKey['columns'][0];
            $table = str_replace('_id', '', $firstColumn);
            // Simple pluralization
            if (!str_ends_with($table, 's')) {
                $table .= 's';
            }
        }

        if (!empty($table)) {
            $this->references($table, $column);
        }

        return $this;
    }
}
