<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Criteria;

use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

/**
 * Class OrderByCriteria
 *
 * Criteria for ordering query results.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository\Criteria
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class OrderByCriteria implements CriteriaInterface
{
    /**
     * @param string $column Column to order by
     * @param string $direction Sort direction (asc/desc)
     */
    public function __construct(
        protected string $column,
        protected string $direction = 'asc'
    ) {}

    /**
     * {@inheritDoc}
     */
    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
    {
        return $query->orderBy($this->column, $this->direction);
    }

    /**
     * Create ascending order criteria.
     */
    public static function asc(string $column): self
    {
        return new self($column, 'asc');
    }

    /**
     * Create descending order criteria.
     */
    public static function desc(string $column): self
    {
        return new self($column, 'desc');
    }

    /**
     * Create latest (descending by date) criteria.
     */
    public static function latest(string $column = 'created_at'): self
    {
        return new self($column, 'desc');
    }

    /**
     * Create oldest (ascending by date) criteria.
     */
    public static function oldest(string $column = 'created_at'): self
    {
        return new self($column, 'asc');
    }
}
