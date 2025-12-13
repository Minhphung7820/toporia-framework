<?php

declare(strict_types=1);

namespace Toporia\Framework\Container;

use Closure;

/**
 * Class NeedsBindingBuilder
 *
 * Second part of contextual binding chain.
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
final class NeedsBindingBuilder
{
    /**
     * @param Container $container Container instance
     * @param array<string> $concrete Concrete classes
     * @param string $abstract Abstract class/interface
     */
    public function __construct(
        private Container $container,
        private array $concrete,
        private string $abstract
    ) {}

    /**
     * Specify the implementation to use.
     *
     * @param callable|string|array $implementation Implementation class, factory, or array
     * @return void
     */
    public function give(callable|string|array $implementation): void
    {
        foreach ($this->concrete as $concrete) {
            $this->container->addContextualBinding($concrete, $this->abstract, $implementation);
        }
    }

    /**
     * Specify that tagged services should be given.
     *
     * @param string $tag Tag name
     * @return void
     */
    public function giveTagged(string $tag): void
    {
        $this->give(function (Container $container) use ($tag) {
            return iterator_to_array($container->tagged($tag));
        });
    }

    /**
     * Specify a configuration value to be given.
     *
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return void
     */
    public function giveConfig(string $key, mixed $default = null): void
    {
        $this->give(function (Container $container) use ($key, $default) {
            $config = $container->make('config');
            return method_exists($config, 'get')
                ? $config->get($key, $default)
                : $default;
        });
    }
}
