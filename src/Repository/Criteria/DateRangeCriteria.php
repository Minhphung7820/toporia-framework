<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Criteria;

use DateTimeInterface;
use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

/**
 * Class DateRangeCriteria
 *
 * Criteria for filtering by date ranges.
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
class DateRangeCriteria implements CriteriaInterface
{
    protected ?string $startDate;
    protected ?string $endDate;

    /**
     * @param string $column Date column name
     * @param string|DateTimeInterface|null $start Start date
     * @param string|DateTimeInterface|null $end End date
     */
    public function __construct(
        protected string $column,
        string|DateTimeInterface|null $start = null,
        string|DateTimeInterface|null $end = null
    ) {
        $this->startDate = $start instanceof DateTimeInterface
            ? $start->format('Y-m-d H:i:s')
            : $start;
        $this->endDate = $end instanceof DateTimeInterface
            ? $end->format('Y-m-d H:i:s')
            : $end;
    }

    /**
     * {@inheritDoc}
     */
    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
    {
        if ($this->startDate !== null && $this->endDate !== null) {
            return $query->whereBetween($this->column, [$this->startDate, $this->endDate]);
        }

        if ($this->startDate !== null) {
            return $query->where($this->column, '>=', $this->startDate);
        }

        if ($this->endDate !== null) {
            return $query->where($this->column, '<=', $this->endDate);
        }

        return $query;
    }

    /**
     * Create criteria for today.
     */
    public static function today(string $column = 'created_at'): self
    {
        $today = now()->format('Y-m-d');
        return new self($column, $today . ' 00:00:00', $today . ' 23:59:59');
    }

    /**
     * Create criteria for yesterday.
     */
    public static function yesterday(string $column = 'created_at'): self
    {
        $yesterday = now()->subDay()->format('Y-m-d');
        return new self($column, $yesterday . ' 00:00:00', $yesterday . ' 23:59:59');
    }

    /**
     * Create criteria for this week.
     */
    public static function thisWeek(string $column = 'created_at'): self
    {
        $start = now()->startOfWeek()->format('Y-m-d');
        $end = now()->endOfWeek()->format('Y-m-d');
        return new self($column, $start . ' 00:00:00', $end . ' 23:59:59');
    }

    /**
     * Create criteria for this month.
     */
    public static function thisMonth(string $column = 'created_at'): self
    {
        $start = now()->startOfMonth()->format('Y-m-d');
        $end = now()->endOfMonth()->format('Y-m-d');
        return new self($column, $start . ' 00:00:00', $end . ' 23:59:59');
    }

    /**
     * Create criteria for this year.
     */
    public static function thisYear(string $column = 'created_at'): self
    {
        $year = now()->format('Y');
        return new self($column, "{$year}-01-01 00:00:00", "{$year}-12-31 23:59:59");
    }

    /**
     * Create criteria for last N days.
     */
    public static function lastDays(int $days, string $column = 'created_at'): self
    {
        $start = now()->subDays($days)->format('Y-m-d');
        $end = now()->format('Y-m-d');
        return new self($column, $start . ' 00:00:00', $end . ' 23:59:59');
    }

    /**
     * Create criteria for records after date.
     */
    public static function after(string|DateTimeInterface $date, string $column = 'created_at'): self
    {
        return new self($column, $date, null);
    }

    /**
     * Create criteria for records before date.
     */
    public static function before(string|DateTimeInterface $date, string $column = 'created_at'): self
    {
        return new self($column, null, $date);
    }
}
