<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Concerns;

use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;

/**
 * Trait HasCriteria
 *
 * Provides criteria pattern functionality for repositories.
 * Allows composable, reusable query modifications.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository\Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasCriteria
{
    /**
     * @var array<CriteriaInterface> Applied criteria
     */
    protected array $criteria = [];

    /**
     * @var bool Skip criteria for next query
     */
    protected bool $skipCriteria = false;

    /**
     * Push a criteria onto the stack.
     *
     * @param CriteriaInterface $criteria
     * @return static
     */
    public function pushCriteria(CriteriaInterface $criteria): static
    {
        $this->criteria[] = $criteria;
        return $this;
    }

    /**
     * Push multiple criteria onto the stack.
     *
     * @param array<CriteriaInterface> $criteria
     * @return static
     */
    public function pushCriteriaArray(array $criteria): static
    {
        foreach ($criteria as $criterion) {
            $this->pushCriteria($criterion);
        }
        return $this;
    }

    /**
     * Pop the last criteria from the stack.
     *
     * @return CriteriaInterface|null
     */
    public function popCriteria(): ?CriteriaInterface
    {
        return array_pop($this->criteria);
    }

    /**
     * Get all applied criteria.
     *
     * @return array<CriteriaInterface>
     */
    public function getCriteria(): array
    {
        return $this->criteria;
    }

    /**
     * Remove specific criteria by class name.
     *
     * @param class-string<CriteriaInterface> $criteriaClass
     * @return static
     */
    public function removeCriteria(string $criteriaClass): static
    {
        $this->criteria = array_filter(
            $this->criteria,
            fn(CriteriaInterface $c) => !$c instanceof $criteriaClass
        );
        $this->criteria = array_values($this->criteria); // Re-index
        return $this;
    }

    /**
     * Clear all criteria.
     *
     * @return static
     */
    public function clearCriteria(): static
    {
        $this->criteria = [];
        return $this;
    }

    /**
     * Skip criteria application for next query.
     *
     * @param bool $skip
     * @return static
     */
    public function skipCriteria(bool $skip = true): static
    {
        $this->skipCriteria = $skip;
        return $this;
    }

    /**
     * Check if criteria should be skipped.
     *
     * @return bool
     */
    public function isSkippingCriteria(): bool
    {
        return $this->skipCriteria;
    }

    /**
     * Apply all criteria to query.
     *
     * @return static
     */
    public function applyCriteria(): static
    {
        if ($this->skipCriteria) {
            return $this;
        }

        foreach ($this->criteria as $criterion) {
            $this->query = $criterion->apply($this->query, $this);
        }

        return $this;
    }

    /**
     * Apply criteria to given query builder.
     *
     * @param ModelQueryBuilder $query
     * @return ModelQueryBuilder
     */
    protected function applyCriteriaToQuery(ModelQueryBuilder $query): ModelQueryBuilder
    {
        if ($this->skipCriteria) {
            return $query;
        }

        foreach ($this->criteria as $criterion) {
            $query = $criterion->apply($query, $this);
        }

        return $query;
    }

    /**
     * Get default criteria for this repository.
     * Override in child classes to set default criteria.
     *
     * @return array<CriteriaInterface>
     */
    public function getDefaultCriteria(): array
    {
        return [];
    }

    /**
     * Reset to default criteria.
     *
     * @return static
     */
    public function resetToDefaultCriteria(): static
    {
        $this->criteria = $this->getDefaultCriteria();
        return $this;
    }
}
