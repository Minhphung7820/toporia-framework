<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Contracts;

use Toporia\Framework\Database\ORM\ModelQueryBuilder;

/**
 * Interface CriteriaInterface
 *
 * Contract for query criteria that can be applied to repositories.
 * Allows encapsulation of complex query logic into reusable classes.
 *
 * Benefits:
 * - Single Responsibility: Each criteria handles one query concern
 * - Reusability: Same criteria can be used across multiple repositories
 * - Testability: Criteria can be unit tested in isolation
 * - Composability: Multiple criteria can be chained
 *
 * @example
 * ```php
 * class ActiveUsersCriteria implements CriteriaInterface
 * {
 *     public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
 *     {
 *         return $query->where('status', 'active')
 *                      ->whereNotNull('email_verified_at');
 *     }
 * }
 *
 * // Usage
 * $users = $userRepository
 *     ->pushCriteria(new ActiveUsersCriteria())
 *     ->pushCriteria(new OrderByLatestCriteria())
 *     ->all();
 * ```
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository\Contracts
 */
interface CriteriaInterface
{
    /**
     * Apply criteria to query builder.
     *
     * @param ModelQueryBuilder $query Current query builder
     * @param RepositoryInterface $repository Repository instance
     * @return ModelQueryBuilder Modified query builder
     */
    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder;
}
