<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Builders;


/**
 * Class TestBuilder
 *
 * Core class for the Builders layer providing essential functionality for
 * the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Builders
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class TestBuilder
{
    protected array $data = [];
    protected array $relationships = [];
    protected array $callbacks = [];

    /**
     * Create a new builder instance.
     *
     * Performance: O(1)
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Set data.
     *
     * Performance: O(1)
     */
    public function with(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * Set a single attribute.
     *
     * Performance: O(1)
     */
    public function withAttribute(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Add relationship.
     *
     * Performance: O(1)
     */
    public function withRelationship(string $name, mixed $value): self
    {
        $this->relationships[$name] = $value;
        return $this;
    }

    /**
     * Add callback to execute after creation.
     *
     * Performance: O(1)
     */
    public function afterCreating(callable $callback): self
    {
        $this->callbacks[] = $callback;
        return $this;
    }

    /**
     * Build the test data.
     *
     * Performance: O(N) where N = number of callbacks
     */
    public function build(): array
    {
        $result = $this->data;

        foreach ($this->callbacks as $callback) {
            $result = $callback($result);
        }

        return $result;
    }
}
