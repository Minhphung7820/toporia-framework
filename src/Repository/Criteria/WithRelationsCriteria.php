<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Criteria;

use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

/**
 * Class WithRelationsCriteria
 *
 * Criteria for eager loading relationships.
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
class WithRelationsCriteria implements CriteriaInterface
{
    /**
     * @var array<string|int, string|\Closure>
     */
    protected array $relations;

    /**
     * @param array<string|int, string|\Closure>|string $relations Relations to eager load
     */
    public function __construct(array|string $relations)
    {
        $this->relations = is_array($relations) ? $relations : [$relations];
    }

    /**
     * {@inheritDoc}
     */
    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
    {
        return $query->with($this->relations);
    }

    /**
     * Create criteria with constrained relation.
     *
     * @param string $relation Relation name
     * @param \Closure $callback Constraint callback
     * @return self
     */
    public static function withConstraint(string $relation, \Closure $callback): self
    {
        return new self([$relation => $callback]);
    }
}
