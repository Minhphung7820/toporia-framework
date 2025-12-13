<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Criteria;

use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

/**
 * Class ActiveCriteria
 *
 * Criteria for filtering active/inactive records.
 * Supports multiple common status column conventions.
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
class ActiveCriteria implements CriteriaInterface
{
    /**
     * @param bool $active Whether to get active records (true) or inactive (false)
     * @param string $column Status column name
     * @param mixed $activeValue Value representing active status
     * @param mixed $inactiveValue Value representing inactive status
     */
    public function __construct(
        protected bool $active = true,
        protected string $column = 'status',
        protected mixed $activeValue = 'active',
        protected mixed $inactiveValue = 'inactive'
    ) {}

    /**
     * {@inheritDoc}
     */
    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
    {
        $value = $this->active ? $this->activeValue : $this->inactiveValue;
        return $query->where($this->column, $value);
    }

    /**
     * Create active records criteria.
     */
    public static function active(string $column = 'status'): self
    {
        return new self(true, $column);
    }

    /**
     * Create inactive records criteria.
     */
    public static function inactive(string $column = 'status'): self
    {
        return new self(false, $column);
    }

    /**
     * Create criteria for boolean is_active column.
     */
    public static function isActive(bool $active = true): self
    {
        return new self($active, 'is_active', true, false);
    }

    /**
     * Create criteria for enabled/disabled.
     */
    public static function enabled(bool $enabled = true): self
    {
        return new self($enabled, 'enabled', true, false);
    }

    /**
     * Create criteria for published content.
     */
    public static function published(): self
    {
        return new self(true, 'status', 'published', 'draft');
    }

    /**
     * Create criteria for verified users.
     */
    public static function verified(string $column = 'email_verified_at'): WhereNullCriteria
    {
        return WhereNullCriteria::notNull($column);
    }
}
