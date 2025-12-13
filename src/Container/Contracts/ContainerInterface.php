<?php

declare(strict_types=1);

namespace Toporia\Framework\Container\Contracts;

use Closure;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Toporia\Framework\Container\Exception\{ContainerException, NotFoundException};

/**
 * Interface ContainerInterface
 *
 * Contract defining the interface for ContainerInterface implementations
 * in the Dependency Injection container layer of the Toporia Framework.
 *
 * Extends PSR-11 ContainerInterface for full interoperability with other
 * PSR-11 compatible libraries and frameworks.
 *
 * PSR-11 compliance:
 * - get(string $id): mixed - Returns entry from container
 * - has(string $id): bool - Returns true if entry exists
 *
 * Additional features (Toporia-style):
 * - Binding (bind, singleton, instance)
 * - Contextual binding (when/needs/give)
 * - Tagged bindings
 * - Scoped bindings (request lifecycle)
 * - Method injection (call)
 * - Aliases
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Container\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     * @return mixed Entry.
     * @throws NotFoundException  No entry was found for this identifier.
     * @throws ContainerException Error while retrieving the entry.
     */
    public function get(string $id): mixed;

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for.
     * @return bool
     */
    public function has(string $id): bool;

    /**
     * Bind a service factory to the container.
     *
     * @param string $id Service identifier.
     * @param callable|string|null $concrete Concrete implementation (null = auto-bind to $id).
     * @param bool $shared Whether the service should be shared (singleton).
     * @return void
     */
    public function bind(string $id, callable|string|null $concrete = null, bool $shared = false): void;

    /**
     * Bind a singleton service to the container.
     * The service will be created once and reused on subsequent calls.
     *
     * @param string $id Service identifier.
     * @param callable|string|null $concrete Concrete implementation (null = auto-bind to $id).
     * @return void
     */
    public function singleton(string $id, callable|string|null $concrete = null): void;

    /**
     * Register an existing instance as a singleton.
     *
     * @param string $id Service identifier.
     * @param mixed $instance The service instance.
     * @return mixed The instance
     */
    public function instance(string $id, mixed $instance): mixed;

    /**
     * Resolve and call a callable with dependency injection.
     *
     * @param callable|array|string $callable The callable to invoke.
     * @param array $parameters Additional parameters to pass.
     * @return mixed The result of the callable.
     * @throws ContainerException
     */
    public function call(callable|array|string $callable, array $parameters = []): mixed;

    /**
     * Resolve a service from the container (alias for get with parameters).
     *
     * @param string $abstract Service identifier.
     * @param array<string, mixed> $parameters Override parameters.
     * @return mixed Resolved instance.
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function make(string $abstract, array $parameters = []): mixed;

    /**
     * Register an alias for an abstract type.
     *
     * @param string $abstract The abstract type.
     * @param string $alias The alias name.
     * @return void
     */
    public function alias(string $abstract, string $alias): void;

    /**
     * Determine if an alias is registered.
     *
     * @param string $name The alias name.
     * @return bool
     */
    public function isAlias(string $name): bool;

    /**
     * Get the alias for an abstract if available.
     *
     * @param string $abstract The abstract type.
     * @return string The resolved abstract (original or aliased).
     */
    public function getAlias(string $abstract): string;

    /**
     * Register a binding if it hasn't already been registered.
     *
     * @param string $abstract Service identifier.
     * @param callable|string|null $concrete Concrete implementation.
     * @param bool $shared Whether singleton.
     * @return void
     */
    public function bindIf(string $abstract, callable|string|null $concrete = null, bool $shared = false): void;

    /**
     * Register a singleton if it hasn't already been registered.
     *
     * @param string $abstract Service identifier.
     * @param callable|string|null $concrete Concrete implementation.
     * @return void
     */
    public function singletonIf(string $abstract, callable|string|null $concrete = null): void;

    /**
     * Register a scoped binding (cleared at end of request/scope).
     *
     * @param string $abstract Service identifier.
     * @param callable|string|null $concrete Concrete implementation.
     * @return void
     */
    public function scoped(string $abstract, callable|string|null $concrete = null): void;

    /**
     * Get a factory closure for the given abstract type.
     *
     * @param string $abstract Service identifier.
     * @return Closure Factory closure.
     */
    public function factory(string $abstract): Closure;

    /**
     * Wrap a closure so that it is shared (singleton pattern).
     *
     * @param Closure $closure The closure to wrap.
     * @return Closure Wrapped singleton closure.
     */
    public function wrap(Closure $closure): Closure;

    /**
     * Refresh an instance on a given target and method.
     *
     * @param string $abstract Service identifier.
     * @param object $target Target object.
     * @param string $method Method to call with refreshed instance.
     * @return mixed
     */
    public function refresh(string $abstract, object $target, string $method): mixed;

    /**
     * Register a callback to be called after resolving.
     *
     * @param string|callable $abstract Service identifier or callback.
     * @param callable|null $callback Callback to fire.
     * @return void
     */
    public function afterResolving(string|callable $abstract, ?callable $callback = null): void;

    /**
     * Clear all scoped instances.
     *
     * @return void
     */
    public function forgetScopedInstances(): void;

    /**
     * Check if a binding exists.
     *
     * @param string $abstract Service identifier.
     * @return bool
     */
    public function bound(string $abstract): bool;

    /**
     * Check if a service has been resolved.
     *
     * @param string $abstract Service identifier.
     * @return bool
     */
    public function resolved(string $abstract): bool;

    /**
     * Register a resolving callback.
     *
     * @param string|Closure $abstract Service identifier or global callback.
     * @param Closure|null $callback Callback to fire before resolution.
     * @return void
     */
    public function resolving(string|Closure $abstract, ?Closure $callback = null): void;

    /**
     * Flush all bindings and resolved instances.
     *
     * @return void
     */
    public function flush(): void;
}
