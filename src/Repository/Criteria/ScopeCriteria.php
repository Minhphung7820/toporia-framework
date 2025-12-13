<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Criteria;

use Closure;
use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

/**
 * Class ScopeCriteria
 *
 * Criteria that applies a custom scope callback.
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
class ScopeCriteria implements CriteriaInterface
{
    /**
     * @param Closure $scope Scope callback receiving query builder
     */
    public function __construct(
        protected Closure $scope
    ) {}

    /**
     * {@inheritDoc}
     */
    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
    {
        ($this->scope)($query, $repository);
        return $query;
    }

    /**
     * Create criteria from callable.
     */
    public static function from(callable $callback): self
    {
        return new self(Closure::fromCallable($callback));
    }
}
