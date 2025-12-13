<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Factory\Concerns;

use Toporia\Framework\Database\Factory;


/**
 * Trait HasStates
 *
 * Trait providing reusable functionality for HasStates in the Concerns
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
trait HasStates
{
    /**
     * Apply multiple states at once.
     *
     * @param array<int, string|callable|array<string, mixed>> $states
     * @return static
     */
    public function states(array $states): static
    {
        foreach ($states as $state) {
            $this->state($state);
        }
        return $this;
    }

    /**
     * Magic method to call state methods dynamically.
     *
     * Allows: $factory->admin() instead of $factory->state('admin')
     *
     * @param string $name Method name
     * @param array<int, mixed> $arguments Arguments
     * @return static
     */
    public function __call(string $name, array $arguments): static
    {
        // If method exists, call it
        if (method_exists($this, $name)) {
            return $this->$name(...$arguments);
        }

        // Otherwise, treat as state name
        return $this->state($name);
    }

    /**
     * Reset states.
     *
     * @return static
     */
    public function resetStates(): static
    {
        $this->states = [];
        return $this;
    }
}

