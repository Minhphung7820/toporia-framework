<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Contracts;


/**
 * Interface ModelInterface
 *
 * Contract defining the interface for ModelInterface implementations in
 * the Database query building and ORM layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface ModelInterface
{
    /**
     * Save the model to the database (insert or update).
     *
     * @return bool
     */
    public function save(): bool;

    /**
     * Delete the model from the database.
     *
     * @return bool
     */
    public function delete(): bool;

    /**
     * Refresh the model from the database.
     *
     * @return self
     */
    public function refresh(): self;

    /**
     * Get the model's attributes as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Get the model's attributes as JSON.
     *
     * @return string
     */
    public function toJson(): string;

    /**
     * Check if the model exists in the database.
     *
     * @return bool
     */
    public function exists(): bool;

    /**
     * Get the table name for the model.
     *
     * @return string
     */
    public static function getTableName(): string;

    /**
     * Get the primary key name.
     *
     * @return string
     */
    public static function getPrimaryKey(): string;
}
