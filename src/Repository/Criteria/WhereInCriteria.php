<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Criteria;

use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

/**
 * Class WhereInCriteria
 *
 * Criteria for WHERE IN conditions.
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
class WhereInCriteria implements CriteriaInterface
{
    /**
     * @param string $column Column name
     * @param array<mixed> $values Array of values
     * @param bool $not Whether to use NOT IN
     */
    public function __construct(
        protected string $column,
        protected array $values,
        protected bool $not = false
    ) {}

    /**
     * {@inheritDoc}
     */
    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
    {
        if (empty($this->values)) {
            // Empty array: IN returns no results, NOT IN returns all
            return $this->not ? $query : $query->whereRaw('1 = 0');
        }

        return $this->not
            ? $query->whereNotIn($this->column, $this->values)
            : $query->whereIn($this->column, $this->values);
    }

    /**
     * Create NOT IN criteria.
     */
    public static function notIn(string $column, array $values): self
    {
        return new self($column, $values, true);
    }
}
