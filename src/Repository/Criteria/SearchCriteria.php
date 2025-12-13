<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Criteria;

use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

/**
 * Class SearchCriteria
 *
 * Criteria for searching across multiple columns.
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
class SearchCriteria implements CriteriaInterface
{
    /**
     * @param string $term Search term
     * @param array<string> $columns Columns to search in
     * @param bool $exact Whether to match exact (equals) or partial (LIKE)
     */
    public function __construct(
        protected string $term,
        protected array $columns,
        protected bool $exact = false
    ) {}

    /**
     * {@inheritDoc}
     */
    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
    {
        if (empty($this->term) || empty($this->columns)) {
            return $query;
        }

        return $query->where(function (ModelQueryBuilder $q) {
            foreach ($this->columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';

                if ($this->exact) {
                    $q->{$method}($column, $this->term);
                } else {
                    $q->{$method}($column, 'like', '%' . $this->escapeLike($this->term) . '%');
                }
            }
        });
    }

    /**
     * Escape special LIKE characters.
     */
    protected function escapeLike(string $value): string
    {
        return str_replace(
            ['%', '_', '\\'],
            ['\\%', '\\_', '\\\\'],
            $value
        );
    }

    /**
     * Create full-text search criteria.
     */
    public static function fullText(string $term, array $columns): self
    {
        return new self($term, $columns, false);
    }

    /**
     * Create exact match search criteria.
     */
    public static function exactMatch(string $term, array $columns): self
    {
        return new self($term, $columns, true);
    }
}
