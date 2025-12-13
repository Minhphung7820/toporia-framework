<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Contracts;

/**
 * Interface CriteriaRepositoryInterface
 *
 * Contract for repositories that support the Criteria pattern.
 * Allows dynamic query modification through composable criteria objects.
 *
 * @extends RepositoryInterface
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository\Contracts
 */
interface CriteriaRepositoryInterface extends RepositoryInterface
{
    /**
     * Push a criteria onto the stack.
     *
     * @param CriteriaInterface $criteria Criteria to add
     * @return static
     */
    public function pushCriteria(CriteriaInterface $criteria): static;

    /**
     * Push multiple criteria onto the stack.
     *
     * @param array<CriteriaInterface> $criteria Array of criteria
     * @return static
     */
    public function pushCriteriaArray(array $criteria): static;

    /**
     * Pop the last criteria from the stack.
     *
     * @return CriteriaInterface|null
     */
    public function popCriteria(): ?CriteriaInterface;

    /**
     * Get all applied criteria.
     *
     * @return array<CriteriaInterface>
     */
    public function getCriteria(): array;

    /**
     * Remove specific criteria by class name.
     *
     * @param class-string<CriteriaInterface> $criteriaClass Criteria class to remove
     * @return static
     */
    public function removeCriteria(string $criteriaClass): static;

    /**
     * Clear all criteria.
     *
     * @return static
     */
    public function clearCriteria(): static;

    /**
     * Skip criteria application for next query.
     *
     * @param bool $skip Whether to skip
     * @return static
     */
    public function skipCriteria(bool $skip = true): static;

    /**
     * Check if criteria should be skipped.
     *
     * @return bool
     */
    public function isSkippingCriteria(): bool;

    /**
     * Apply all criteria to query.
     *
     * @return static
     */
    public function applyCriteria(): static;

    /**
     * Get default criteria for this repository.
     *
     * @return array<CriteriaInterface>
     */
    public function getDefaultCriteria(): array;

    /**
     * Reset to default criteria.
     *
     * @return static
     */
    public function resetToDefaultCriteria(): static;
}
