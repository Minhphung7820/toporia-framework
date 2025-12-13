<?php

declare(strict_types=1);

namespace Toporia\Framework\Container;

/**
 * Class ContextualBindingBuilder
 *
 * Fluent builder for contextual bindings.
 * Provides method chaining for readable binding configuration.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Container
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ContextualBindingBuilder
{
    /**
     * @param Container $container Container instance
     * @param array<string> $concrete Concrete classes that need the binding
     */
    public function __construct(
        private Container $container,
        private array $concrete
    ) {}

    /**
     * Specify the abstract class/interface that needs binding.
     *
     * @param string $abstract Abstract class/interface
     * @return NeedsBindingBuilder
     */
    public function needs(string $abstract): NeedsBindingBuilder
    {
        return new NeedsBindingBuilder($this->container, $this->concrete, $abstract);
    }
}
