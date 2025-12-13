<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Factory\Concerns;

use Toporia\Framework\Database\Factory;
use Closure;


/**
 * Trait HasSequences
 *
 * Trait providing reusable functionality for HasSequences in the Concerns
 * layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasSequences
{
    /**
     * Sequence index counter.
     *
     * @var int
     */
    protected int $sequenceIndex = 0;

    /**
     * Sequence definitions.
     *
     * @var array<int, array<string, mixed>|Closure>
     */
    protected array $sequences = [];

    /**
     * Define sequence of attribute variations.
     *
     * @param array<int, array<string, mixed>|Closure> ...$sequences
     * @return static
     */
    public function sequence(array|Closure ...$sequences): static
    {
        $this->sequences = $sequences;
        $this->sequenceIndex = 0;

        return $this;
    }

    /**
     * Get next sequence attributes.
     *
     * @return array<string, mixed>
     */
    protected function getNextSequence(): array
    {
        if (empty($this->sequences)) {
            return [];
        }

        $index = $this->sequenceIndex % count($this->sequences);
        $sequence = $this->sequences[$index];
        $this->sequenceIndex++;

        if ($sequence instanceof Closure) {
            return $sequence($this->sequenceIndex - 1);
        }

        return $sequence;
    }

    /**
     * Reset sequence index.
     *
     * @return static
     */
    public function resetSequence(): static
    {
        $this->sequenceIndex = 0;
        return $this;
    }
}

