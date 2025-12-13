<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Contracts;

use Toporia\Framework\Database\Query\QueryBuilder;


/**
 * Interface RelationInterface
 *
 * Contract defining the interface for RelationInterface implementations in
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
interface RelationInterface
{
    /**
     * Get the query builder for this relationship.
     *
     * @return QueryBuilder
     */
    public function getQuery(): QueryBuilder;

    /**
     * Execute the relationship query and get results.
     *
     * @return mixed Single model, collection, or null
     */
    public function getResults(): mixed;

    /**
     * Add eager loading constraints to the query.
     *
     * @param array<int, \Toporia\Framework\Database\ORM\Model> $models Parent models
     * @return void
     */
    public function addEagerConstraints(array $models): void;

    /**
     * Match eager loaded results to their parent models.
     *
     * @param array<int, \Toporia\Framework\Database\ORM\Model> $models Parent models
     * @param mixed $results Eager loaded results
     * @param string $relationName Name of the relationship
     * @return array<int, \Toporia\Framework\Database\ORM\Model>
     */
    public function match(array $models, mixed $results, string $relationName): array;

    /**
     * Get the foreign key for the relationship.
     *
     * @return string
     */
    public function getForeignKey(): string;

    /**
     * Get the local key for the relationship.
     *
     * @return string
     */
    public function getLocalKey(): string;

    /**
     * Get the foreign key column name (for column selection in eager loading).
     *
     * This is typically the same as getForeignKey() but extracted as separate method
     * to support future flexibility (e.g., qualified names like "table.column").
     *
     * @return string Foreign key column name
     */
    public function getForeignKeyName(): string;

    /**
     * Create a new instance for eager loading without parent constraints.
     *
     * @param QueryBuilder $freshQuery Fresh query builder without constraints
     * @return static New relation instance ready for eager loading
     */
    public function newEagerInstance(QueryBuilder $freshQuery): static;

    /**
     * Get the related model class name.
     *
     * @return string Fully qualified class name of the related model
     */
    public function getRelatedClass(): string;
}
