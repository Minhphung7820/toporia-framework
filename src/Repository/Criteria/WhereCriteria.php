<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Criteria;

use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

/**
 * Class WhereCriteria
 *
 * Criteria for WHERE conditions.
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
class WhereCriteria implements CriteriaInterface
{
    /**
     * @param string $column Column name
     * @param mixed $operator Operator or value
     * @param mixed $value Value (optional if operator is value)
     */
    public function __construct(
        protected string $column,
        protected mixed $operator,
        protected mixed $value = null
    ) {
        // Support shorthand: where('column', 'value') instead of where('column', '=', 'value')
        if ($this->value === null && !in_array($this->operator, ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like'], true)) {
            $this->value = $this->operator;
            $this->operator = '=';
        }
    }

    /**
     * {@inheritDoc}
     */
    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
    {
        return $query->where($this->column, $this->operator, $this->value);
    }

    /**
     * Create equals criteria.
     */
    public static function equals(string $column, mixed $value): self
    {
        return new self($column, '=', $value);
    }

    /**
     * Create not equals criteria.
     */
    public static function notEquals(string $column, mixed $value): self
    {
        return new self($column, '!=', $value);
    }

    /**
     * Create like criteria.
     */
    public static function like(string $column, string $value): self
    {
        return new self($column, 'like', $value);
    }

    /**
     * Create contains criteria (LIKE %value%).
     */
    public static function contains(string $column, string $value): self
    {
        return new self($column, 'like', '%' . $value . '%');
    }

    /**
     * Create starts with criteria (LIKE value%).
     */
    public static function startsWith(string $column, string $value): self
    {
        return new self($column, 'like', $value . '%');
    }

    /**
     * Create ends with criteria (LIKE %value).
     */
    public static function endsWith(string $column, string $value): self
    {
        return new self($column, 'like', '%' . $value);
    }

    /**
     * Create greater than criteria.
     */
    public static function greaterThan(string $column, mixed $value): self
    {
        return new self($column, '>', $value);
    }

    /**
     * Create less than criteria.
     */
    public static function lessThan(string $column, mixed $value): self
    {
        return new self($column, '<', $value);
    }
}
