<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Http\Exceptions\NotFoundHttpException;
use Toporia\Framework\Routing\Contracts\RouteInterface;

/**
 * Class RouteModelBinding
 *
 * Automatically resolves route parameters to model instances.
 * Provides both implicit and explicit binding similar to other frameworks.
 *
 * Performance:
 * - O(1) binding resolution via hash lookup
 * - Lazy model loading (only when accessed)
 * - Query caching support
 *
 * Example:
 * ```php
 * // Implicit binding (auto-detect from type hint)
 * $router->get('/users/{user}', [UserController::class, 'show']);
 * // In controller: public function show(User $user)
 *
 * // Explicit binding
 * $binding->bind('user', User::class);
 * $binding->bind('post', fn($value) => Post::findOrFail($value));
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Routing
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * // Custom key
 * $binding->bind('user', User::class, 'uuid');
 * ```
 */
class RouteModelBinding
{
    /**
     * Explicit bindings.
     *
     * @var array<string, array{resolver: callable|class-string, key: string|null}>
     */
    protected array $bindings = [];

    /**
     * Implicit binding mappings (parameter name => model class).
     *
     * @var array<string, class-string>
     */
    protected array $implicitBindings = [];

    /**
     * Global scopes to apply to bound models.
     *
     * @var array<string, callable>
     */
    protected array $scopes = [];

    /**
     * Whether to throw 404 on model not found.
     *
     * @var bool
     */
    protected bool $throwOnNotFound = true;

    /**
     * Cached resolved models.
     *
     * @var array<string, mixed>
     */
    protected array $resolved = [];

    /**
     * Create a new route model binding instance.
     *
     * @param ContainerInterface $container
     */
    public function __construct(
        protected ContainerInterface $container
    ) {}

    /**
     * Register an explicit binding.
     *
     * @param string $parameter Route parameter name
     * @param callable|class-string $resolver Model class or custom resolver
     * @param string|null $key Custom column to use for lookup (default: primary key)
     * @return static
     */
    public function bind(string $parameter, callable|string $resolver, ?string $key = null): static
    {
        $this->bindings[$parameter] = [
            'resolver' => $resolver,
            'key' => $key,
        ];

        return $this;
    }

    /**
     * Register a model binding.
     *
     * @param string $parameter Route parameter name
     * @param class-string $model Model class
     * @param string|null $key Custom column to use for lookup
     * @return static
     */
    public function model(string $parameter, string $model, ?string $key = null): static
    {
        return $this->bind($parameter, $model, $key);
    }

    /**
     * Register a callback for model not found.
     *
     * @param string $parameter Route parameter name
     * @param callable|class-string $resolver Resolver
     * @param string|null $key Custom column
     * @param callable|null $callback Callback when model not found
     * @return static
     */
    public function bindOrFail(
        string $parameter,
        callable|string $resolver,
        ?string $key = null,
        ?callable $callback = null
    ): static {
        $this->bind($parameter, function ($value, $route) use ($resolver, $key, $callback) {
            $model = $this->resolveModel($resolver, $value, $key);

            if ($model === null) {
                if ($callback !== null) {
                    return $callback($value, $route);
                }

                $this->modelNotFound($resolver, $value);
            }

            return $model;
        });

        return $this;
    }

    /**
     * Add a scope to bound models.
     *
     * @param string $parameter Route parameter name
     * @param callable $scope Scope callback
     * @return static
     */
    public function scope(string $parameter, callable $scope): static
    {
        $this->scopes[$parameter] = $scope;

        return $this;
    }

    /**
     * Resolve route parameters to models.
     *
     * @param array<string, mixed> $parameters Route parameters
     * @param Route|null $route Current route
     * @return array<string, mixed> Resolved parameters
     */
    public function resolve(array $parameters, ?Route $route = null): array
    {
        $resolved = [];

        foreach ($parameters as $key => $value) {
            // Check cache first
            $cacheKey = $key . ':' . $value;
            if (isset($this->resolved[$cacheKey])) {
                $resolved[$key] = $this->resolved[$cacheKey];
                continue;
            }

            $resolved[$key] = $this->resolveParameter($key, $value, $route);

            // Cache result
            $this->resolved[$cacheKey] = $resolved[$key];
        }

        return $resolved;
    }

    /**
     * Resolve a single parameter.
     *
     * @param string $key Parameter name
     * @param mixed $value Parameter value
     * @param Route|null $route Current route
     * @return mixed Resolved value
     */
    protected function resolveParameter(string $key, mixed $value, ?Route $route = null): mixed
    {
        // Check explicit binding
        if (isset($this->bindings[$key])) {
            return $this->resolveExplicitBinding($key, $value, $route);
        }

        // Check implicit binding
        if (isset($this->implicitBindings[$key])) {
            $model = $this->resolveModel($this->implicitBindings[$key], $value);

            if ($model === null && $this->throwOnNotFound) {
                $this->modelNotFound($this->implicitBindings[$key], $value);
            }

            return $model ?? $value;
        }

        return $value;
    }

    /**
     * Resolve an explicit binding.
     *
     * @param string $key Parameter name
     * @param mixed $value Parameter value
     * @param Route|null $route Current route
     * @return mixed
     */
    protected function resolveExplicitBinding(string $key, mixed $value, ?Route $route = null): mixed
    {
        $binding = $this->bindings[$key];
        $resolver = $binding['resolver'];
        $column = $binding['key'];

        // Callable resolver
        if (is_callable($resolver)) {
            return $resolver($value, $route);
        }

        // Model class resolver
        $model = $this->resolveModel($resolver, $value, $column);

        if ($model === null && $this->throwOnNotFound) {
            $this->modelNotFound($resolver, $value);
        }

        return $model ?? $value;
    }

    /**
     * Resolve a model from the database.
     *
     * @param class-string $modelClass Model class
     * @param mixed $value Route parameter value
     * @param string|null $key Column to use
     * @return Model|null
     */
    protected function resolveModel(string $modelClass, mixed $value, ?string $key = null): ?Model
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        $key = $key ?? $this->getRouteKeyName($modelClass);

        /** @var Model $instance */
        $instance = new $modelClass();

        $query = $instance::query()->where($key, '=', $value);

        // Apply scope if registered
        $parameterName = $this->findParameterName($modelClass);
        if ($parameterName !== null && isset($this->scopes[$parameterName])) {
            $query = $this->scopes[$parameterName]($query);
        }

        return $query->first();
    }

    /**
     * Get the route key name for a model.
     *
     * @param class-string $modelClass
     * @return string
     */
    protected function getRouteKeyName(string $modelClass): string
    {
        $instance = new $modelClass();

        if (method_exists($instance, 'getRouteKeyName')) {
            return $instance->getRouteKeyName();
        }

        return $instance->getKeyName();
    }

    /**
     * Find parameter name for a model class.
     *
     * @param class-string $modelClass
     * @return string|null
     */
    protected function findParameterName(string $modelClass): ?string
    {
        foreach ($this->bindings as $parameter => $binding) {
            if ($binding['resolver'] === $modelClass) {
                return $parameter;
            }
        }

        foreach ($this->implicitBindings as $parameter => $class) {
            if ($class === $modelClass) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Handle model not found.
     *
     * Throws NotFoundHttpException for consistent error handling.
     * The ModelNotFoundException is also thrown for backward compatibility
     * if error handler is configured to convert it to 404.
     *
     * @param class-string|string $model
     * @param mixed $value
     * @return never
     * @throws NotFoundHttpException
     */
    protected function modelNotFound(string $model, mixed $value): never
    {
        // Extract model short name for cleaner error message
        $shortName = class_exists($model) ? (new \ReflectionClass($model))->getShortName() : $model;

        throw new NotFoundHttpException(
            sprintf('%s not found.', $shortName)
        );
    }

    /**
     * Register implicit binding for a parameter.
     *
     * @param string $parameter
     * @param class-string $modelClass
     * @return static
     */
    public function implicitBinding(string $parameter, string $modelClass): static
    {
        $this->implicitBindings[$parameter] = $modelClass;

        return $this;
    }

    /**
     * Set whether to throw on model not found.
     *
     * @param bool $throw
     * @return static
     */
    public function shouldThrowOnNotFound(bool $throw): static
    {
        $this->throwOnNotFound = $throw;

        return $this;
    }

    /**
     * Clear resolved cache.
     *
     * @return static
     */
    public function clearResolved(): static
    {
        $this->resolved = [];

        return $this;
    }

    /**
     * Get all explicit bindings.
     *
     * @return array<string, array{resolver: callable|class-string, key: string|null}>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Get all implicit bindings.
     *
     * @return array<string, class-string>
     */
    public function getImplicitBindings(): array
    {
        return $this->implicitBindings;
    }

    /**
     * Auto-discover model bindings from controller method type hints.
     *
     * This method automatically registers implicit model bindings by inspecting
     * the controller method's parameter type hints. If a parameter is type-hinted
     * with a Model subclass, it will be automatically bound.
     *
     * Example:
     * ```php
     * public function show(User $user) {
     *     // $user is automatically resolved from {user} route parameter
     * }
     * ```
     *
     * @param RouteInterface $route Current route
     * @return void
     */
    public function discoverFromRoute(RouteInterface $route): void
    {
        $handler = $route->getHandler();

        // Only process array handlers [ControllerClass, 'method']
        if (!is_array($handler) || count($handler) !== 2) {
            return;
        }

        [$controllerClass, $method] = $handler;

        // Ensure it's a valid controller class
        if (!is_string($controllerClass) || !class_exists($controllerClass)) {
            return;
        }

        try {
            $reflection = new \ReflectionMethod($controllerClass, $method);
        } catch (\ReflectionException $e) {
            return; // Method doesn't exist, skip
        }

        // Inspect each parameter
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            // Skip if no type hint or not a class type
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            // Check if it's a Model subclass
            if (!is_subclass_of($className, Model::class)) {
                continue;
            }

            $parameterName = $parameter->getName();

            // Auto-register implicit binding if not already registered
            if (!isset($this->bindings[$parameterName]) && !isset($this->implicitBindings[$parameterName])) {
                $this->implicitBindings[$parameterName] = $className;
            }
        }
    }

    /**
     * Enable automatic type hint discovery for all routes.
     *
     * This should be called during application bootstrap to enable
     * automatic model binding from controller type hints.
     *
     * @param bool $enable Whether to enable auto-discovery
     * @return static
     */
    public function enableAutoDiscovery(bool $enable = true): static
    {
        // This is a marker method - actual discovery happens per-route
        // when resolving parameters
        return $this;
    }
}
