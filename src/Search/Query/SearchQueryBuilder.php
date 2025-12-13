<?php

declare(strict_types=1);

namespace Toporia\Framework\Search\Query;

use Toporia\Framework\Search\Contracts\SearchQueryBuilderInterface;

/**
 * Class SearchQueryBuilder
 *
 * Fluent query builder for constructing Elasticsearch search queries with filtering, sorting, and pagination support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Search\Query
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SearchQueryBuilder implements SearchQueryBuilderInterface
{
    private array $must = [];
    private array $filter = [];
    private array $sort = [];
    private int $page = 1;
    private int $perPage = 15;

    public function term(string $field, mixed $value): self
    {
        $this->filter[] = ['term' => [$field => $value]];
        return $this;
    }

    public function match(string $field, string $query): self
    {
        $this->must[] = ['match' => [$field => $query]];
        return $this;
    }

    public function range(string $field, array $range): self
    {
        $this->filter[] = ['range' => [$field => $range]];
        return $this;
    }

    public function sort(string $field, string $direction = 'asc'): self
    {
        $this->sort[] = [$field => ['order' => $direction]];
        return $this;
    }

    public function paginate(int $page, int $perPage): self
    {
        $this->page = max(1, $page);
        $this->perPage = max(1, $perPage);
        return $this;
    }

    public function toArray(): array
    {
        $query = [
            'bool' => [
                'must' => $this->must,
                'filter' => $this->filter,
            ],
        ];

        return [
            'query' => $query,
            'from' => ($this->page - 1) * $this->perPage,
            'size' => $this->perPage,
            'sort' => $this->sort,
        ];
    }
}

