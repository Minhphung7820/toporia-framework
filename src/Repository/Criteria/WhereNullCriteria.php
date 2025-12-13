<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Criteria;

use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

/**
 * Class WhereNullCriteria
 *
 * Criteria for WHERE NULL/NOT NULL conditions.
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
class WhereNullCriteria implements CriteriaInterface
{
    /**
     * @param string $column Column name
     * @param bool $not Whether to use NOT NULL
     */
    public function __construct(
        protected string $column,
        protected bool $not = false
    ) {}

    /**
     * {@inheritDoc}
     */
    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
    {
        return $this->not
            ? $query->whereNotNull($this->column)
            : $query->whereNull($this->column);
    }

    /**
     * Create NOT NULL criteria.
     */
    public static function notNull(string $column): self
    {
        return new self($column, true);
    }
}
