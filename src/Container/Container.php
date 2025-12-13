<?php

declare(strict_types=1);

namespace Toporia\Framework\Container;

use Closure;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Container\Exception\{ContainerException, NotFoundException};
use Toporia\Framework\Http\FormRequest;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Class Container
 *
 * The central Dependency Injection container for the Toporia Framework.
 * Responsible for resolving class dependencies, managing bindings,
 * singletons, contextual bindings, and autowiring via reflection.
 *
 * Professional DI container with advanced features:
 * - Auto-wiring with reflection
 * - Singleton pattern support
 * - Contextual bindings
 * - Tagged bindings
 * - Extending bindings
 * - Resolving callbacks
 * - Method injection
 * - Circular dependency detection
 * - Aliasing support
 * - Scoped bindings (request lifecycle)
 * - Factory closures
 * - Rebinding callbacks
 *
 * Performance:
 * - O(1) singleton lookup (cached)
 * - O(N) dependency resolution where N = depth
 * - Reflection caching for better performance
 * - Lazy resolution with factory closures
 *
 * Clean Architecture:
 * - Single Responsibility: Dependency injection only
 * - Dependency Inversion: Depends on ContainerInterface
 * - Open/Closed: Extensible via bindings
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
final class Container implements ContainerInterface
{
    /**
     * The current globally available container instance.
     *
     * @var static|null
     */
    private static ?Container $instance = null;

    /**
     * @var array<string, array{concrete: callable|string|null, shared: bool}> Service bindings
     */
    private array $bindings = [];

    /**
     * @var array<string, mixed> Resolved singleton instances
     */
    private array $instances = [];

    /**
     * @var array<string, string> Alias mappings (alias => abstract)
     */
    private array $aliases = [];

    /**
     * @var array<string, string> Abstract aliases (abstract => alias)
     */
    private array $abstractAliases = [];

    /**
     * @var array<string, array<string, callable|string>> Contextual bindings
     * Format: ['Concrete' => ['Abstract' => 'Implementation']]
     */
    private array $contextual = [];

    /**
     * @var array<string, array<string>> Tagged bindings
     * Format: ['tag' => ['Service1', 'Service2']]
     */
    private array $tags = [];

    /**
     * @var array<string, array<callable>> Extending bindings
     * Format: ['Service' => [callback1, callback2]]
     */
    private array $extenders = [];

    /**
     * @var array<string, array<callable>> Resolving callbacks (before caching)
     * Format: ['Service' => [callback1, callback2]]
     */
    private array $resolvingCallbacks = [];

    /**
     * @var array<callable> Global resolving callbacks
     */
    private array $globalResolvingCallbacks = [];

    /**
     * @var array<string, array<callable>> After resolving callbacks
     */
    private array $afterResolvingCallbacks = [];

    /**
     * @var array<callable> Global after resolving callbacks
     */
    private array $globalAfterResolvingCallbacks = [];

    /**
     * @var array<string, array<callable>> Rebinding callbacks
     */
    private array $reboundCallbacks = [];

    /**
     * @var array<string, bool> Scoped bindings (cleared per request)
     */
    private array $scopedInstances = [];

    /**
     * @var array<string, bool> Resolved abstracts tracking
     */
    private array $resolved = [];

    /**
     * @var array<string, bool> Resolution stack for circular dependency detection
     */
    private array $resolving = [];

    /**
     * @var array<callable> Before resolving callbacks (called before resolution)
     */
    private array $beforeResolvingCallbacks = [];

    /**
     * @var array<string, mixed> Build stack with parameters
     */
    private array $with = [];

    /**
     * @var array<string> Build stack for context tracking
     */
    private array $buildStack = [];

    /**
     * @var array<string, ReflectionClass> Reflection class cache for performance
     */
    private array $reflectionClassCache = [];

    /**
     * @var array<string, ReflectionMethod> Reflection method cache for performance
     */
    private array $reflectionMethodCache = [];

    /**
     * Set the globally available instance of the container.
     *
     * @param ContainerInterface|null $container
     * @return ContainerInterface|null
     */
    public static function setInstance(?ContainerInterface $container = null): ?ContainerInterface
    {
        return static::$instance = $container;
    }

    /**
     * Get the globally available instance of the container.
     *
     * @return static
     */
    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * {@inheritdoc}
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        // Resolve alias
        $abstract = $this->getAlias($abstract);

        // Fire before resolving callbacks (for deferred provider loading)
        $this->fireBeforeResolvingCallbacks($abstract);

        // Check if already resolved as singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Detect circular dependencies
        if (isset($this->resolving[$abstract])) {
            throw new ContainerException(
                "Circular dependency detected while resolving '{$abstract}'. " .
                "Resolution stack: " . implode(' -> ', array_keys($this->resolving)) . " -> {$abstract}"
            );
        }

        $this->resolving[$abstract] = true;
        $this->with[] = $parameters;

        try {
            $concrete = $this->getConcrete($abstract);
            $instance = $this->build($concrete, $parameters);

            // Apply extenders
            foreach ($this->getExtenders($abstract) as $extender) {
                $instance = $extender($instance, $this);
            }

            // Fire resolving callbacks
            $this->fireResolvingCallbacks($abstract, $instance);

            // Auto-validate FormRequest instances
            if ($instance instanceof FormRequest) {
                $instance->validate();
            }

            // Fire after resolving callbacks
            $this->fireAfterResolvingCallbacks($abstract, $instance);

            // Cache if singleton
            if ($this->isShared($abstract)) {
                $this->instances[$abstract] = $instance;
            }

            // Track as resolved
            $this->resolved[$abstract] = true;

            return $instance;
        } finally {
            unset($this->resolving[$abstract]);
            array_pop($this->with);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        return $this->bound($id) || $this->isAlias($id) || class_exists($id);
    }

    /**
     * {@inheritdoc}
     */
    public function bound(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);

        return isset($this->bindings[$abstract])
            || isset($this->instances[$abstract]);
    }

    /**
     * {@inheritdoc}
     */
    public function resolved(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);

        return isset($this->resolved[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * {@inheritdoc}
     */
    public function bind(string $id, callable|string|null $concrete = null, bool $shared = false): void
    {
        $id = $this->getAlias($id);

        // Remove stale instance
        unset($this->instances[$id], $this->aliases[$id]);

        $concrete = $concrete ?? $id;

        $this->bindings[$id] = [
            'concrete' => $concrete,
            'shared' => $shared,
        ];

        // Fire rebinding callbacks if already resolved
        if ($this->resolved($id)) {
            $this->rebound($id);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function bindIf(string $abstract, callable|string|null $concrete = null, bool $shared = false): void
    {
        if (!$this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function singleton(string $id, callable|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    /**
     * {@inheritdoc}
     */
    public function singletonIf(string $abstract, callable|string|null $concrete = null): void
    {
        if (!$this->bound($abstract)) {
            $this->singleton($abstract, $concrete);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function scoped(string $abstract, callable|string|null $concrete = null): void
    {
        $this->scopedInstances[$abstract] = true;
        $this->singleton($abstract, $concrete);
    }

    /**
     * {@inheritdoc}
     */
    public function forgetScopedInstances(): void
    {
        foreach (array_keys($this->scopedInstances) as $abstract) {
            unset($this->instances[$abstract]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function instance(string $id, mixed $instance): mixed
    {
        $id = $this->getAlias($id);

        // Remove from aliases
        unset($this->aliases[$id]);

        $this->instances[$id] = $instance;
        unset($this->bindings[$id]);

        // Fire rebinding callbacks
        if ($this->resolved($id)) {
            $this->rebound($id);
        }

        return $instance;
    }

    /**
     * {@inheritdoc}
     */
    public function alias(string $abstract, string $alias): void
    {
        if ($alias === $abstract) {
            throw new ContainerException("Cannot alias [{$abstract}] to itself.");
        }

        $this->aliases[$alias] = $abstract;
        $this->abstractAliases[$abstract][] = $alias;
    }

    /**
     * {@inheritdoc}
     */
    public function isAlias(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias(string $abstract): string
    {
        return isset($this->aliases[$abstract])
            ? $this->getAlias($this->aliases[$abstract])
            : $abstract;
    }

    /**
     * {@inheritdoc}
     */
    public function factory(string $abstract): Closure
    {
        return fn() => $this->make($abstract);
    }

    /**
     * {@inheritdoc}
     */
    public function wrap(Closure $closure): Closure
    {
        $resolved = null;

        return function () use ($closure, &$resolved) {
            if ($resolved === null) {
                $resolved = $closure($this);
            }
            return $resolved;
        };
    }

    /**
     * {@inheritdoc}
     */
    public function refresh(string $abstract, object $target, string $method): mixed
    {
        return $this->rebinding($abstract, function ($app, $instance) use ($target, $method) {
            $target->{$method}($instance);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function call(callable|array|string $callable, array $parameters = []): mixed
    {
        // Parse string callable "Class@method" or "Class::method"
        if (is_string($callable)) {
            if (str_contains($callable, '@')) {
                $callable = explode('@', $callable, 2);
            } elseif (str_contains($callable, '::')) {
                $callable = explode('::', $callable, 2);
            }
        }

        if (is_array($callable)) {
            [$class, $method] = $callable;

            // Resolve class if string
            if (is_string($class)) {
                $class = $this->make($class);
            }

            $reflection = $this->getReflectionMethod($class, $method);
            $dependencies = $this->resolveMethodDependencies($reflection, $parameters);

            return $reflection->invokeArgs($class, $dependencies);
        }

        // Closure or invokable
        if ($callable instanceof Closure || is_object($callable)) {
            $reflection = new ReflectionMethod($callable, '__invoke');
            $dependencies = $this->resolveMethodDependencies($reflection, $parameters);

            return $callable(...$dependencies);
        }

        $reflection = new ReflectionFunction($callable);
        $dependencies = $this->resolveMethodDependencies($reflection, $parameters);

        return $reflection->invokeArgs($dependencies);
    }

    /**
     * Register a contextual binding builder.
     *
     * @param string|array $concrete Concrete class(es)
     * @return ContextualBindingBuilder
     */
    public function when(string|array $concrete): ContextualBindingBuilder
    {
        $concrete = is_array($concrete) ? $concrete : [$concrete];

        return new ContextualBindingBuilder($this, $concrete);
    }

    /**
     * Tag bindings.
     *
     * @param array<string> $abstracts Service identifiers
     * @param string|array $tags Tag name(s)
     * @return void
     */
    public function tag(array $abstracts, string|array $tags): void
    {
        $tags = is_array($tags) ? $tags : [$tags];

        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            foreach ($abstracts as $abstract) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve all tagged services.
     *
     * @param string $tag Tag name
     * @return iterable Resolved services (generator for memory efficiency)
     */
    public function tagged(string $tag): iterable
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }

        foreach ($this->tags[$tag] as $abstract) {
            yield $this->make($abstract);
        }
    }

    /**
     * Extend a resolved service.
     *
     * @param string $abstract Service identifier
     * @param Closure $closure Extender callback
     * @return void
     */
    public function extend(string $abstract, Closure $closure): void
    {
        $abstract = $this->getAlias($abstract);

        // If already resolved, apply immediately
        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);
            $this->rebound($abstract);
        } else {
            $this->extenders[$abstract][] = $closure;

            if ($this->resolved($abstract)) {
                $this->rebound($abstract);
            }
        }
    }

    /**
     * Register a resolving callback.
     *
     * @param string|Closure $abstract Service identifier or global callback
     * @param Closure|null $callback Callback
     * @return void
     */
    public function resolving(string|Closure $abstract, ?Closure $callback = null): void
    {
        if ($abstract instanceof Closure) {
            $this->globalResolvingCallbacks[] = $abstract;
        } else {
            $this->resolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a callback to be called before resolving a service.
     *
     * This is useful for loading deferred service providers on-demand.
     *
     * @param Closure $callback Callback receives the abstract being resolved
     * @return void
     */
    public function beforeResolving(Closure $callback): void
    {
        $this->beforeResolvingCallbacks[] = $callback;
    }

    /**
     * Fire before resolving callbacks.
     *
     * @param string $abstract Service being resolved
     * @return void
     */
    private function fireBeforeResolvingCallbacks(string $abstract): void
    {
        foreach ($this->beforeResolvingCallbacks as $callback) {
            $callback($abstract, $this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function afterResolving(string|callable $abstract, ?callable $callback = null): void
    {
        if (is_callable($abstract) && !is_string($abstract)) {
            $this->globalAfterResolvingCallbacks[] = $abstract;
        } else {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a rebinding callback.
     *
     * @param string $abstract Service identifier
     * @param Closure $callback Callback
     * @return mixed
     */
    public function rebinding(string $abstract, Closure $callback): mixed
    {
        $this->reboundCallbacks[$abstract = $this->getAlias($abstract)][] = $callback;

        if ($this->bound($abstract)) {
            return $this->make($abstract);
        }

        return null;
    }

    /**
     * Check if binding is shared.
     *
     * @param string $abstract Service identifier
     * @return bool
     */
    public function isShared(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);

        return isset($this->instances[$abstract])
            || (isset($this->bindings[$abstract]['shared']) && $this->bindings[$abstract]['shared']);
    }

    /**
     * Forget a binding.
     *
     * @param string $abstract Service identifier
     * @return void
     */
    public function forget(string $abstract): void
    {
        $abstract = $this->getAlias($abstract);

        unset(
            $this->bindings[$abstract],
            $this->instances[$abstract],
            $this->resolved[$abstract],
            $this->aliases[$abstract]
        );
    }

    /**
     * Flush all bindings and instances.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->abstractAliases = [];
        $this->contextual = [];
        $this->tags = [];
        $this->extenders = [];
        $this->resolvingCallbacks = [];
        $this->globalResolvingCallbacks = [];
        $this->afterResolvingCallbacks = [];
        $this->globalAfterResolvingCallbacks = [];
        $this->reboundCallbacks = [];
        $this->scopedInstances = [];
        $this->resolved = [];
        $this->reflectionClassCache = [];
        $this->reflectionMethodCache = [];
    }

    /**
     * Get all bindings.
     *
     * @return array
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Add contextual binding.
     *
     * @param string $concrete Concrete class
     * @param string $abstract Abstract interface
     * @param callable|string $implementation Implementation
     * @return void
     */
    public function addContextualBinding(string $concrete, string $abstract, callable|string $implementation): void
    {
        $this->contextual[$concrete][$this->getAlias($abstract)] = $implementation;
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Get concrete implementation for abstract.
     *
     * @param string $abstract
     * @return callable|string
     */
    private function getConcrete(string $abstract): callable|string
    {
        // Check contextual bindings
        $contextual = $this->getContextualConcrete($abstract);
        if ($contextual !== null) {
            return $contextual;
        }

        // Check regular bindings
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        // Return abstract itself for auto-wiring
        return $abstract;
    }

    /**
     * Get contextual concrete for current build context.
     *
     * @param string $abstract
     * @return callable|string|null
     */
    private function getContextualConcrete(string $abstract): callable|string|null
    {
        // Check build stack for context
        if (!empty($this->buildStack)) {
            $concrete = end($this->buildStack);

            if (isset($this->contextual[$concrete][$abstract])) {
                return $this->contextual[$concrete][$abstract];
            }
        }

        return null;
    }

    /**
     * Build concrete instance.
     *
     * @param callable|string $concrete
     * @param array $parameters
     * @return mixed
     */
    private function build(callable|string $concrete, array $parameters = []): mixed
    {
        // Closure factory
        if ($concrete instanceof Closure) {
            return $concrete($this, ...array_values($parameters));
        }

        // Callable factory
        if (is_callable($concrete)) {
            return $concrete($this, ...array_values($parameters));
        }

        // Auto-wire class
        if (is_string($concrete) && class_exists($concrete)) {
            return $this->autowire($concrete, $parameters);
        }

        throw new NotFoundException("Target [{$concrete}] is not instantiable.");
    }

    /**
     * Auto-wire class dependencies.
     *
     * @param string $className
     * @param array $parameters
     * @return object
     */
    private function autowire(string $className, array $parameters = []): object
    {
        $this->buildStack[] = $className;

        try {
            $reflection = $this->getReflectionClass($className);

            if (!$reflection->isInstantiable()) {
                throw new ContainerException(
                    "Target [{$className}] is not instantiable." .
                    ($reflection->isInterface() ? " Consider binding an implementation." : "")
                );
            }

            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return $reflection->newInstance();
            }

            // Merge with stack parameters
            $parameters = array_merge($this->getLastParameterOverride(), $parameters);
            $dependencies = $this->resolveMethodDependencies($constructor, $parameters);

            return $reflection->newInstanceArgs($dependencies);
        } finally {
            array_pop($this->buildStack);
        }
    }

    /**
     * Resolve method/constructor dependencies.
     *
     * @param ReflectionMethod|ReflectionFunction $reflection
     * @param array $parameters
     * @return array
     */
    private function resolveMethodDependencies(
        ReflectionMethod|ReflectionFunction $reflection,
        array $parameters
    ): array {
        $dependencies = [];

        foreach ($reflection->getParameters() as $parameter) {
            $dependency = $this->resolveParameter($parameter, $parameters);
            $dependencies[] = $dependency;
        }

        return $dependencies;
    }

    /**
     * Resolve a single parameter.
     *
     * @param ReflectionParameter $parameter
     * @param array $parameters
     * @return mixed
     */
    private function resolveParameter(ReflectionParameter $parameter, array $parameters): mixed
    {
        $name = $parameter->getName();

        // Use provided parameter
        if (array_key_exists($name, $parameters)) {
            return $parameters[$name];
        }

        // Use positional parameter
        if (array_key_exists($parameter->getPosition(), $parameters)) {
            return $parameters[$parameter->getPosition()];
        }

        $type = $parameter->getType();

        // Handle union types
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType && !$unionType->isBuiltin()) {
                    try {
                        return $this->make($unionType->getName());
                    } catch (ContainerException|NotFoundException) {
                        continue;
                    }
                }
            }
        }

        // Handle named types
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $className = $type->getName();

            // Check for contextual binding
            $contextual = $this->getContextualConcrete($className);
            if ($contextual !== null) {
                return $this->build($contextual);
            }

            try {
                return $this->make($className);
            } catch (ContainerException|NotFoundException $e) {
                // Fall through to default value check
                if ($parameter->isDefaultValueAvailable()) {
                    return $parameter->getDefaultValue();
                }

                if ($type->allowsNull()) {
                    return null;
                }

                throw $e;
            }
        }

        // Use default value
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        // Allow null
        if ($parameter->allowsNull()) {
            return null;
        }

        // Variadic parameter
        if ($parameter->isVariadic()) {
            return [];
        }

        // Cannot resolve
        $context = $reflection = $parameter->getDeclaringFunction();
        $contextName = $reflection instanceof ReflectionMethod
            ? $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName()
            : $reflection->getName();

        throw new ContainerException(
            "Unresolvable dependency [{$name}] in [{$contextName}]"
        );
    }

    /**
     * Get last parameter override from build stack.
     *
     * @return array
     */
    private function getLastParameterOverride(): array
    {
        return $this->with[count($this->with) - 1] ?? [];
    }

    /**
     * Get extenders for abstract.
     *
     * @param string $abstract
     * @return array
     */
    private function getExtenders(string $abstract): array
    {
        return $this->extenders[$abstract] ?? [];
    }

    /**
     * Fire resolving callbacks.
     *
     * @param string $abstract
     * @param mixed $instance
     * @return void
     */
    private function fireResolvingCallbacks(string $abstract, mixed $instance): void
    {
        // Fire global callbacks
        foreach ($this->globalResolvingCallbacks as $callback) {
            $callback($instance, $this);
        }

        // Fire type-specific callbacks
        foreach ($this->resolvingCallbacks as $type => $callbacks) {
            if ($abstract === $type || (is_object($instance) && $instance instanceof $type)) {
                foreach ($callbacks as $callback) {
                    $callback($instance, $this);
                }
            }
        }
    }

    /**
     * Fire after resolving callbacks.
     *
     * @param string $abstract
     * @param mixed $instance
     * @return void
     */
    private function fireAfterResolvingCallbacks(string $abstract, mixed $instance): void
    {
        // Fire global callbacks
        foreach ($this->globalAfterResolvingCallbacks as $callback) {
            $callback($instance, $this);
        }

        // Fire type-specific callbacks
        foreach ($this->afterResolvingCallbacks as $type => $callbacks) {
            if ($abstract === $type || (is_object($instance) && $instance instanceof $type)) {
                foreach ($callbacks as $callback) {
                    $callback($instance, $this);
                }
            }
        }
    }

    /**
     * Fire rebound callbacks.
     *
     * @param string $abstract
     * @return void
     */
    private function rebound(string $abstract): void
    {
        $instance = $this->make($abstract);

        foreach ($this->getReboundCallbacks($abstract) as $callback) {
            $callback($this, $instance);
        }
    }

    /**
     * Get rebound callbacks for abstract.
     *
     * @param string $abstract
     * @return array
     */
    private function getReboundCallbacks(string $abstract): array
    {
        return $this->reboundCallbacks[$abstract] ?? [];
    }

    /**
     * Get cached reflection class.
     *
     * @param string $className
     * @return ReflectionClass
     */
    private function getReflectionClass(string $className): ReflectionClass
    {
        if (!isset($this->reflectionClassCache[$className])) {
            try {
                $this->reflectionClassCache[$className] = new ReflectionClass($className);
            } catch (ReflectionException $e) {
                throw new ContainerException(
                    "Target class [{$className}] does not exist.",
                    0,
                    $e
                );
            }
        }

        return $this->reflectionClassCache[$className];
    }

    /**
     * Get cached reflection method.
     *
     * @param object|string $class
     * @param string $method
     * @return ReflectionMethod
     */
    private function getReflectionMethod(object|string $class, string $method): ReflectionMethod
    {
        $className = is_string($class) ? $class : $class::class;
        $key = "{$className}::{$method}";

        if (!isset($this->reflectionMethodCache[$key])) {
            try {
                $this->reflectionMethodCache[$key] = new ReflectionMethod($class, $method);
            } catch (ReflectionException $e) {
                throw new ContainerException(
                    "Method [{$method}] does not exist on [{$className}].",
                    0,
                    $e
                );
            }
        }

        return $this->reflectionMethodCache[$key];
    }
}
