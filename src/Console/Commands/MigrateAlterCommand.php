<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Database\DatabaseManager;
use Toporia\Framework\Database\Schema\{Blueprint, SchemaBuilder};

/**
 * Class MigrateAlterCommand
 *
 * Allows altering existing table structure without creating new migrations.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MigrateAlterCommand extends Command
{
    protected string $signature = 'migrate:alter {table : Table name to alter}';
    protected string $description = 'Alter existing table structure';

    private const COLOR_RESET = "\033[0m";
    private const COLOR_INFO = "\033[36m";
    private const COLOR_SUCCESS = "\033[32m";
    private const COLOR_ERROR = "\033[31m";
    private const COLOR_WARNING = "\033[33m";

    public function __construct(
        private DatabaseManager $db
    ) {
    }

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $table = $this->argument('table');

        if (empty($table)) {
            $this->error('Table name is required');
            return 1;
        }

        try {
            $connection = $this->db->connection();
            $schema = new SchemaBuilder($connection);

            // Check if table exists
            if (!$schema->hasTable($table)) {
                $this->error("Table '{$table}' does not exist");
                return 1;
            }

            $this->info("Altering table: {$table}");

            // Get options
            $add = $this->option('add');
            $drop = $this->option('drop');
            $modify = $this->option('modify');
            $rename = $this->option('rename');
            $index = $this->option('index');
            $unique = $this->option('unique');
            $foreign = $this->option('foreign');
            $dropIndex = $this->option('drop-index');
            $dropUnique = $this->option('drop-unique');
            $dropForeign = $this->option('drop-foreign');

            // Build alterations
            $schema->table($table, function (Blueprint $blueprint) use (
                $add, $drop, $modify, $rename,
                $index, $unique, $foreign,
                $dropIndex, $dropUnique, $dropForeign
            ) {
                // Add columns
                if ($add) {
                    $this->handleAddColumns($blueprint, $add);
                }

                // Drop columns
                if ($drop) {
                    $this->handleDropColumns($blueprint, $drop);
                }

                // Modify columns
                if ($modify) {
                    $this->handleModifyColumns($blueprint, $modify);
                }

                // Rename columns
                if ($rename) {
                    $this->handleRenameColumns($blueprint, $rename);
                }

                // Add indexes
                if ($index) {
                    $this->handleAddIndexes($blueprint, $index);
                }

                // Add unique indexes
                if ($unique) {
                    $this->handleAddUnique($blueprint, $unique);
                }

                // Add foreign keys
                if ($foreign) {
                    $this->handleAddForeignKeys($blueprint, $foreign);
                }

                // Drop indexes
                if ($dropIndex) {
                    $this->handleDropIndexes($blueprint, $dropIndex);
                }

                // Drop unique indexes
                if ($dropUnique) {
                    $this->handleDropUnique($blueprint, $dropUnique);
                }

                // Drop foreign keys
                if ($dropForeign) {
                    $this->handleDropForeignKeys($blueprint, $dropForeign);
                }
            });

            $this->success("Table '{$table}' altered successfully");

            return 0;

        } catch (\Throwable $e) {
            $this->error("Failed to alter table: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Handle adding columns.
     *
     * Format: --add=name:type:length,email:string:255
     */
    private function handleAddColumns(Blueprint $blueprint, string $add): void
    {
        $columns = explode(',', $add);
        foreach ($columns as $columnDef) {
            $parts = explode(':', $columnDef);
            $name = trim($parts[0]);
            $type = trim($parts[1] ?? 'string');
            $length = isset($parts[2]) ? (int) $parts[2] : null;

            match ($type) {
                'string' => $blueprint->string($name, $length ?? 255),
                'text' => $blueprint->text($name),
                'integer' => $blueprint->integer($name),
                'bigInteger' => $blueprint->bigInteger($name),
                'boolean' => $blueprint->boolean($name),
                'date' => $blueprint->date($name),
                'datetime' => $blueprint->datetime($name),
                'timestamp' => $blueprint->timestamp($name),
                'decimal' => $blueprint->decimal($name, $length ?? 10, 2),
                'json' => $blueprint->json($name),
                default => $blueprint->string($name, $length ?? 255),
            };
        }
    }

    /**
     * Handle dropping columns.
     *
     * Format: --drop=column1,column2
     */
    private function handleDropColumns(Blueprint $blueprint, string $drop): void
    {
        $columns = array_map('trim', explode(',', $drop));
        $blueprint->dropColumn($columns);
    }

    /**
     * Handle modifying columns.
     *
     * Format: --modify=name:type:length
     */
    private function handleModifyColumns(Blueprint $blueprint, string $modify): void
    {
        $columns = explode(',', $modify);
        foreach ($columns as $columnDef) {
            $parts = explode(':', $columnDef);
            $name = trim($parts[0]);
            $type = trim($parts[1] ?? 'string');
            $length = isset($parts[2]) ? (int) $parts[2] : null;

            $column = match ($type) {
                'string' => $blueprint->string($name, $length ?? 255),
                'text' => $blueprint->text($name),
                'integer' => $blueprint->integer($name),
                'bigInteger' => $blueprint->bigInteger($name),
                'boolean' => $blueprint->boolean($name),
                'date' => $blueprint->date($name),
                'datetime' => $blueprint->datetime($name),
                'timestamp' => $blueprint->timestamp($name),
                'decimal' => $blueprint->decimal($name, $length ?? 10, 2),
                'json' => $blueprint->json($name),
                default => $blueprint->string($name, $length ?? 255),
            };

            $column->change();
        }
    }

    /**
     * Handle renaming columns.
     *
     * Format: --rename=old_name:new_name
     */
    private function handleRenameColumns(Blueprint $blueprint, string $rename): void
    {
        $renames = explode(',', $rename);
        foreach ($renames as $renameDef) {
            $parts = explode(':', $renameDef);
            $from = trim($parts[0]);
            $to = trim($parts[1] ?? '');
            if ($from && $to) {
                $blueprint->renameColumn($from, $to);
            }
        }
    }

    /**
     * Handle adding indexes.
     *
     * Format: --index=column1,column2
     */
    private function handleAddIndexes(Blueprint $blueprint, string $index): void
    {
        $indexes = explode(',', $index);
        foreach ($indexes as $indexDef) {
            $columns = array_map('trim', explode(':', $indexDef));
            $blueprint->index($columns);
        }
    }

    /**
     * Handle adding unique indexes.
     *
     * Format: --unique=column1,column2
     */
    private function handleAddUnique(Blueprint $blueprint, string $unique): void
    {
        $uniques = explode(',', $unique);
        foreach ($uniques as $uniqueDef) {
            $columns = array_map('trim', explode(':', $uniqueDef));
            $blueprint->unique($columns);
        }
    }

    /**
     * Handle adding foreign keys.
     *
     * Format: --foreign=column:references:on_table:on_delete:on_update
     */
    private function handleAddForeignKeys(Blueprint $blueprint, string $foreign): void
    {
        $foreigns = explode(',', $foreign);
        foreach ($foreigns as $foreignDef) {
            $parts = explode(':', $foreignDef);
            $column = trim($parts[0]);
            $references = trim($parts[1] ?? 'id');
            $on = trim($parts[2] ?? '');
            $onDelete = trim($parts[3] ?? '');
            $onUpdate = trim($parts[4] ?? '');

            if ($column && $on) {
                $fk = $blueprint->foreign($column);
                $fk->references($on, $references);
                if ($onDelete) {
                    $fk->onDelete($onDelete);
                }
                if ($onUpdate) {
                    $fk->onUpdate($onUpdate);
                }
            }
        }
    }

    /**
     * Handle dropping indexes.
     */
    private function handleDropIndexes(Blueprint $blueprint, string $dropIndex): void
    {
        $indexes = array_map('trim', explode(',', $dropIndex));
        foreach ($indexes as $index) {
            $blueprint->dropIndex($index);
        }
    }

    /**
     * Handle dropping unique indexes.
     */
    private function handleDropUnique(Blueprint $blueprint, string $dropUnique): void
    {
        $uniques = array_map('trim', explode(',', $dropUnique));
        foreach ($uniques as $unique) {
            $blueprint->dropUnique($unique);
        }
    }

    /**
     * Handle dropping foreign keys.
     */
    private function handleDropForeignKeys(Blueprint $blueprint, string $dropForeign): void
    {
        $foreigns = array_map('trim', explode(',', $dropForeign));
        foreach ($foreigns as $foreign) {
            $blueprint->dropForeign($foreign);
        }
    }
}

