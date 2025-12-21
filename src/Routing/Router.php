<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing;

use Toporia\Framework\Routing\Contracts\{RouteCollectionInterface, RouteInterface, RouterInterface};
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Http\Contracts\{JsonResponseInterface, RedirectResponseInterface, ResponseInterface, StreamedResponseInterface};
use Toporia\Framework\Http\Exceptions\{NotFoundHttpException, MethodNotAllowedHttpException};
use Toporia\Framework\Http\Middleware\MiddlewarePipeline;
use Toporia\Framework\Http\{Request, Response};
use Toporia\Framework\Routing\RouteModelBinding;
use Toporia\Framework\Routing\SubdomainRouter;

/**
 * Class Router
 *
 * HTTP Router with middleware support and dependency injection.
 * Provides RESTful route registration, parameter extraction, middleware pipeline,
 * named routes, and dependency injection for controllers.
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
 */
final class Router implements RouterInterface
{
    /**
     * @var RouteCollectionInterface Route collection.
     */
    private RouteCollectionInterface $routes;

    /**
     * @var MiddlewarePipeline Middleware pipeline builder.
     */
    private MiddlewarePipeline $middlewarePipeline;

    /**
     * @var string Current prefix for route groups
     */
    private string $currentPrefix = '';

    /**
     * @var array<string> Current middleware stack for route groups
     */
    private array $currentMiddleware = [];

    /**
     * @var string|null Current namespace for route groups
     */
    private ?string $currentNamespace = null;

    /**
     * @var string Current name prefix for route groups
     */
    private string $currentNamePrefix = '';

    /**
     * @var RouteModelBinding|null Route model binding instance
     */
    private ?RouteModelBinding $modelBinding = null;

    /**
     * @var SubdomainRouter|null Subdomain router instance
     */
    private ?SubdomainRouter $subdomainRouter = null;

    /**
     * @var mixed Fallback handler for unmatched routes (404 handler)
     */
    private mixed $fallbackHandler = null;

    /**
     * @var RoutePerformanceMonitor|null Optional performance monitor
     */
    private ?RoutePerformanceMonitor $performanceMonitor = null;

    /**
     * @param Request $request Current HTTP request.
     * @param Response $response HTTP response handler.
     * @param ContainerInterface $container Dependency injection container.
     * @param RouteCollectionInterface|null $routes Optional custom route collection.
     */
    public function __construct(
        private Request $request,
        private Response $response,
        private ContainerInterface $container,
        ?RouteCollectionInterface $routes = null
    ) {
        $this->routes = $routes ?? new RouteCollection();
        $this->middlewarePipeline = new MiddlewarePipeline($container);
    }

    /**
     * Set middleware aliases for resolving short names.
     *
     * @param array<string, string> $aliases
     * @return self
     */
    public function setMiddlewareAliases(array $aliases): self
    {
        $this->middlewarePipeline->addAliases($aliases);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $path, mixed $handler, array $middleware = []): RouteInterface
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * {@inheritdoc}
     */
    public function post(string $path, mixed $handler, array $middleware = []): RouteInterface
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $path, mixed $handler, array $middleware = []): RouteInterface
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * {@inheritdoc}
     */
    public function patch(string $path, mixed $handler, array $middleware = []): RouteInterface
    {
        return $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path, mixed $handler, array $middleware = []): RouteInterface
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * {@inheritdoc}
     */
    public function any(string $path, mixed $handler, array $middleware = []): RouteInterface
    {
        return $this->addRoute(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler, $middleware);
    }

    /**
     * Register a RESTful resource controller.
     *
     * Creates standard CRUD routes for a resource:
     * - GET    /photos           -> index
     * - GET    /photos/create    -> create
     * - POST   /photos           -> store
     * - GET    /photos/{id}      -> show
     * - GET    /photos/{id}/edit -> edit
     * - PUT    /photos/{id}      -> update
     * - PATCH  /photos/{id}      -> update
     * - DELETE /photos/{id}      -> destroy
     *
     * @param string $name Resource name (e.g., 'photos', 'users')
     * @param string $controller Controller class
     * @param array{
     *     only?: array<string>,
     *     except?: array<string>,
     *     names?: array<string, string>,
     *     parameters?: array<string, string>,
     *     middleware?: array<string>
     * } $options Resource options
     * @return self
     *
     * @example
     * ```php
     * Route::resource('photos', PhotoController::class);
     * Route::resource('photos', PhotoController::class, ['only' => ['index', 'show']]);
     * Route::resource('photos', PhotoController::class, ['except' => ['destroy']]);
     * ```
     */
    public function resource(string $name, string $controller, array $options = []): self
    {
        $name = trim($name, '/');
        $parameter = $options['parameters'][$name] ?? rtrim($name, 's'); // photos -> photo
        $middleware = $options['middleware'] ?? [];

        // Define all resource routes
        $resourceRoutes = [
            'index'   => ['GET', "/{$name}", 'index'],
            'create'  => ['GET', "/{$name}/create", 'create'],
            'store'   => ['POST', "/{$name}", 'store'],
            'show'    => ['GET', "/{$name}/{{$parameter}}", 'show'],
            'edit'    => ['GET', "/{$name}/{{$parameter}}/edit", 'edit'],
            'update'  => [['PUT', 'PATCH'], "/{$name}/{{$parameter}}", 'update'],
            'destroy' => ['DELETE', "/{$name}/{{$parameter}}", 'destroy'],
        ];

        // Filter routes based on only/except options
        $actions = array_keys($resourceRoutes);
        if (isset($options['only'])) {
            $actions = array_intersect($actions, $options['only']);
        }
        if (isset($options['except'])) {
            $actions = array_diff($actions, $options['except']);
        }

        // Register filtered routes
        foreach ($actions as $action) {
            [$method, $uri, $controllerMethod] = $resourceRoutes[$action];

            $route = $this->addRoute($method, $uri, [$controller, $controllerMethod], $middleware);

            // Apply custom route name if provided
            $routeName = $options['names'][$action] ?? "{$name}.{$action}";
            $route->name($routeName);
        }

        return $this;
    }

    /**
     * Register an API resource controller (without create/edit routes).
     *
     * Same as resource() but excludes HTML form routes (create, edit).
     *
     * @param string $name Resource name
     * @param string $controller Controller class
     * @param array $options Resource options
     * @return self
     */
    public function apiResource(string $name, string $controller, array $options = []): self
    {
        $options['except'] = array_merge(
            $options['except'] ?? [],
            ['create', 'edit']
        );

        return $this->resource($name, $controller, $options);
    }

    /**
     * Register a fallback handler for unmatched routes (404 handler).
     *
     * This handler will be called automatically when no route matches the request.
     * It acts as a global 404 handler, not a catch-all route.
     *
     * @param mixed $handler Fallback handler (controller, closure, etc.)
     * @return self
     *
     * @example
     * ```php
     * // In routes/api.php
     * Route::fallback(function (Request $request) {
     *     abort(404);
     *     // or
     *     return response()->json(['error' => 'Not found'], 404);
     * });
     * ```
     */
    public function fallback(mixed $handler): self
    {
        $this->fallbackHandler = $handler;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(): void
    {
        $method = $this->request->method();
        $path = $this->request->path();

        $match = $this->routes->match($method, $path);

        if ($match === null) {
            // Check if path exists for any method to determine 404 vs 405
            if ($this->routes->pathExists($path)) {
                // Path exists but method is not allowed - return 405
                $this->handleMethodNotAllowed($path);
            } else {
                // Path doesn't exist - return 404
                $this->handleNotFound();
            }
            return;
        }

        ['route' => $route, 'parameters' => $parameters] = $match;

        $this->executeRoute($route, $parameters);
    }

    /**
     * Handle 404 Not Found response.
     *
     * If a fallback handler is registered, it will be called.
     * Otherwise, throws NotFoundHttpException for the error handler.
     *
     * @return void
     * @throws NotFoundHttpException
     */
    private function handleNotFound(): void
    {
        // If fallback handler is registered, execute it
        if ($this->fallbackHandler !== null) {
            $this->executeFallbackHandler();
            return;
        }

        // Throw NotFoundHttpException - will be caught by error handler
        throw new NotFoundHttpException(
            sprintf('The requested URL "%s" was not found.', $this->request->path())
        );
    }

    /**
     * Handle 405 Method Not Allowed response.
     *
     * This is called when the path exists but the HTTP method is not allowed.
     * Throws MethodNotAllowedHttpException with Allow header.
     *
     * @param string $path Requested path
     * @return void
     * @throws MethodNotAllowedHttpException
     */
    private function handleMethodNotAllowed(string $path): void
    {
        // Get allowed methods for this path
        $allowedMethods = $this->routes->getAllowedMethods($path);

        // Throw MethodNotAllowedHttpException with allowed methods
        // The exception includes the Allow header automatically
        throw new MethodNotAllowedHttpException(
            $allowedMethods,
            sprintf(
                'The %s method is not allowed for "%s". Allowed: %s',
                $this->request->method(),
                $path,
                implode(', ', $allowedMethods)
            )
        );
    }

    /**
     * Execute the fallback handler.
     *
     * Wraps fallback in middleware pipeline to ensure security middleware
     * (CSRF, CORS, etc.) is applied even to 404 handlers.
     *
     * @return void
     */
    private function executeFallbackHandler(): void
    {
        $handler = $this->fallbackHandler;

        // Build the core handler
        $coreHandler = $this->buildCoreHandler($handler, []);

        // Apply global middleware to fallback handler for security
        // This ensures CSRF, CORS, and other security middleware run on 404s
        $pipeline = $this->middlewarePipeline->build($this->currentMiddleware, $coreHandler);

        // Execute pipeline and get result
        $result = $pipeline($this->request, $this->response);

        // Handle response
        $this->sendResponse($result);
    }

    /**
     * Send the response based on result type.
     *
     * @param mixed $result Handler result
     * @return void
     */
    private function sendResponse(mixed $result): void
    {
        if ($result instanceof JsonResponseInterface) {
            $result->sendResponse();
        } elseif ($result instanceof RedirectResponseInterface) {
            $result->sendResponse();
        } elseif ($result instanceof StreamedResponseInterface) {
            // Send headers before streaming content
            $result->sendHeaders();
            $result->sendContent();
        } elseif ($result instanceof ResponseInterface) {
            $result->send($result->getContent());
        } elseif (is_string($result)) {
            $this->response->html($result);
        } elseif (is_array($result) || is_object($result)) {
            $this->response->json($result);
        }
    }

    /**
     * Add a route to the collection.
     *
     * @param string|array $methods HTTP method(s).
     * @param string $path URI pattern.
     * @param mixed $handler Route handler.
     * @param array<string> $middleware Middleware classes.
     * @return RouteInterface
     */
    private function addRoute(
        string|array $methods,
        string $path,
        mixed $handler,
        array $middleware
    ): RouteInterface {
        // Apply current prefix from groups
        $fullPath = $this->currentPrefix . '/' . ltrim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');
        if ($fullPath !== '/') {
            $fullPath = rtrim($fullPath, '/');
        }

        // Merge group middleware with route middleware
        $fullMiddleware = array_merge($this->currentMiddleware, $middleware);

        // Apply namespace to handler if it's an array with class string
        if (is_array($handler) && isset($handler[0]) && is_string($handler[0])) {
            if ($this->currentNamespace && !str_contains($handler[0], '\\')) {
                $handler[0] = $this->currentNamespace . '\\' . $handler[0];
            }
        }

        $route = new Route($methods, $fullPath, $handler, $fullMiddleware);

        // Apply name prefix if set
        if ($this->currentNamePrefix) {
            // Store the name prefix for later use when name() is called on route
            // This will be handled by Route class
        }

        $this->routes->add($route);
        return $route;
    }

    /**
     * Execute a matched route with middleware pipeline.
     *
     * @param RouteInterface $route Matched route.
     * @param array $parameters Extracted route parameters.
     * @return void
     */
    private function executeRoute(RouteInterface $route, array $parameters): void
    {
        $handler = $route->getHandler();

        // Start performance monitoring (zero overhead if disabled)
        if ($this->performanceMonitor !== null) {
            $this->performanceMonitor->start();
        }

        // Apply route model binding if configured
        if ($this->modelBinding !== null) {
            // Auto-discover model bindings from type hints before resolving
            $this->modelBinding->discoverFromRoute($route);

            $parameters = $this->modelBinding->resolve($parameters, $route);
        }

        // Store route parameters in request attributes
        // This allows FormRequest->route() and middleware to access route parameters
        foreach ($parameters as $key => $value) {
            $this->request->setAttribute("route.{$key}", $value);
        }

        // Build the core handler
        $coreHandler = $this->buildCoreHandler($handler, $parameters);

        // Expand middleware groups to actual middleware classes
        $middleware = $this->expandMiddlewareGroups($route->getMiddleware());

        // Build middleware pipeline using MiddlewarePipeline class
        $pipeline = $this->middlewarePipeline->build($middleware, $coreHandler);

        // Execute pipeline and get result
        $result = $pipeline($this->request, $this->response);

        // End performance monitoring
        if ($this->performanceMonitor !== null) {
            $this->performanceMonitor->end($route);
        }

        // Handle response
        $this->sendResponse($result);
    }

    /**
     * Build the core route handler.
     *
     * @param mixed $handler Route handler definition.
     * @param array $parameters Route parameters.
     * @return callable
     */
    private function buildCoreHandler(mixed $handler, array $parameters): callable
    {
        return function (Request $req, Response $res) use ($handler, $parameters) {
            // Temporarily bind Request and Response in container for auto-wiring
            // This allows controllers/actions to inject Request/Response in method parameters
            $this->container->instance(Request::class, $req);
            $this->container->instance(Response::class, $res);

            // Array handler [ControllerClass::class, 'method']
            if (is_array($handler) && is_string($handler[0])) {
                // Auto-wire controller with all dependencies
                $controller = $this->container->get($handler[0]);
                $method = $handler[1];

                // Use container->call() for method parameter injection
                // Container automatically validates FormRequest during dependency resolution
                return $this->container->call([$controller, $method], $parameters);
            }

            // Callable handler
            if (is_callable($handler)) {
                return $this->container->call($handler, array_merge(
                    ['request' => $req, 'response' => $res],
                    $parameters
                ));
            }

            // Invokable class
            if (is_string($handler) && class_exists($handler)) {
                $instance = $this->container->get($handler);
                return $this->container->call([$instance, '__invoke'], array_merge(
                    ['request' => $req, 'response' => $res],
                    $parameters
                ));
            }

            throw new \RuntimeException('Invalid route handler');
        };
    }

    /**
     * Expand middleware groups to actual middleware classes.
     *
     * Resolves middleware group names (e.g., 'web', 'api') to their
     * actual middleware class lists from the middleware configuration.
     *
     * @param array<string> $middleware Middleware stack (may contain group names)
     * @return array<string> Expanded middleware stack (only class names)
     */
    private function expandMiddlewareGroups(array $middleware): array
    {
        $expanded = [];

        // Load middleware groups from config
        $middlewareConfig = $this->container->has('config')
            ? $this->container->get('config')->get('middleware.groups', [])
            : [];

        foreach ($middleware as $middlewareItem) {
            // Check if it's a group name
            if (isset($middlewareConfig[$middlewareItem]) && is_array($middlewareConfig[$middlewareItem])) {
                // Expand the group recursively
                $groupMiddleware = $this->expandMiddlewareGroups($middlewareConfig[$middlewareItem]);
                $expanded = array_merge($expanded, $groupMiddleware);
            } else {
                // It's a middleware class or alias
                $expanded[] = $middlewareItem;
            }
        }

        return $expanded;
    }

    /**
     * Get the route collection.
     *
     * @return RouteCollectionInterface
     */
    public function getRoutes(): RouteCollectionInterface
    {
        return $this->routes;
    }

    // ============================================================================
    // Route Grouping Support
    // ============================================================================

    /**
     * {@inheritdoc}
     */
    public function group(array $attributes, callable $callback): void
    {
        $previousPrefix = $this->currentPrefix;
        $previousMiddleware = $this->currentMiddleware;
        $previousNamespace = $this->currentNamespace;
        $previousNamePrefix = $this->currentNamePrefix;

        // Apply group attributes
        if (isset($attributes['prefix'])) {
            $this->currentPrefix = $previousPrefix . '/' . trim($attributes['prefix'], '/');
        }

        if (isset($attributes['middleware'])) {
            $this->currentMiddleware = array_merge(
                $previousMiddleware,
                (array) $attributes['middleware']
            );
        }

        if (isset($attributes['namespace'])) {
            $this->currentNamespace = rtrim($attributes['namespace'], '\\');
        }

        if (isset($attributes['name'])) {
            $this->currentNamePrefix = $previousNamePrefix . $attributes['name'];
        }

        // Execute callback
        $callback($this);

        // Restore previous state
        $this->currentPrefix = $previousPrefix;
        $this->currentMiddleware = $previousMiddleware;
        $this->currentNamespace = $previousNamespace;
        $this->currentNamePrefix = $previousNamePrefix;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentPrefix(): string
    {
        return $this->currentPrefix;
    }

    /**
     * {@inheritdoc}
     */
    public function setCurrentPrefix(string $prefix): void
    {
        $this->currentPrefix = $prefix;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentMiddleware(): array
    {
        return $this->currentMiddleware;
    }

    /**
     * {@inheritdoc}
     */
    public function setCurrentMiddleware(array $middleware): void
    {
        $this->currentMiddleware = $middleware;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentNamespace(): ?string
    {
        return $this->currentNamespace;
    }

    /**
     * {@inheritdoc}
     */
    public function setCurrentNamespace(?string $namespace): void
    {
        $this->currentNamespace = $namespace;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentNamePrefix(): string
    {
        return $this->currentNamePrefix;
    }

    /**
     * {@inheritdoc}
     */
    public function setCurrentNamePrefix(string $prefix): void
    {
        $this->currentNamePrefix = $prefix;
    }

    /**
     * Compile routes for caching.
     *
     * Returns optimized array structure for O(1) lookup.
     *
     * Performance: Pre-compiles regex patterns, flattens structure.
     *
     * @return array Compiled routes data
     */
    public function compileRoutes(): array
    {
        $compiled = [];

        foreach ($this->routes->all() as $route) {
            $methods = $route->getMethods();
            $uri = $route->getUri();

            $compiled[] = [
                'methods' => is_array($methods) ? $methods : [$methods],
                'uri' => $uri,
                'handler' => $this->serializeHandler($route->getHandler()),
                'middleware' => $route->getMiddleware(),
                'name' => $route->getName(),
            ];
        }

        return $compiled;
    }

    /**
     * Load routes from cache.
     *
     * Reconstructs Route objects from cached data.
     *
     * @param array $cached Cached routes data
     * @return void
     */
    public function loadCachedRoutes(array $cached): void
    {
        $collection = new RouteCollection();

        foreach ($cached as $data) {
            $route = new Route(
                $data['methods'],
                $data['uri'],
                $this->unserializeHandler($data['handler'])
            );

            if (!empty($data['middleware'])) {
                $route->middleware($data['middleware']);
            }

            if (!empty($data['name'])) {
                $route->name($data['name']);
            }

            $collection->add($route);
        }

        $this->routes = $collection;
    }

    /**
     * Serialize handler for caching.
     *
     * @param mixed $handler Route handler
     * @return array Serializable handler data
     */
    private function serializeHandler(mixed $handler): array
    {
        if (is_array($handler)) {
            return ['type' => 'array', 'value' => $handler];
        }

        if (is_string($handler)) {
            return ['type' => 'string', 'value' => $handler];
        }

        // Closures cannot be cached
        return ['type' => 'closure', 'value' => null];
    }

    /**
     * Unserialize handler from cache.
     *
     * @param array $data Serialized handler data
     * @return mixed Handler
     */
    private function unserializeHandler(array $data): mixed
    {
        return match ($data['type']) {
            'array', 'string' => $data['value'],
            default => fn() => null // Closure placeholder
        };
    }

    // ============================================================================
    // Route Model Binding
    // ============================================================================

    /**
     * Get or create the model binding instance.
     *
     * @return RouteModelBinding
     */
    public function getModelBinding(): RouteModelBinding
    {
        if ($this->modelBinding === null) {
            $this->modelBinding = new RouteModelBinding($this->container);
        }

        return $this->modelBinding;
    }

    /**
     * Set the model binding instance.
     *
     * @param RouteModelBinding $binding
     * @return self
     */
    public function setModelBinding(RouteModelBinding $binding): self
    {
        $this->modelBinding = $binding;

        return $this;
    }

    /**
     * Bind a model to a route parameter.
     *
     * @param string $parameter Route parameter name
     * @param string|callable $resolver Model class or resolver callback
     * @param string|null $key Custom column for lookup
     * @return self
     */
    public function bind(string $parameter, string|callable $resolver, ?string $key = null): self
    {
        $this->getModelBinding()->bind($parameter, $resolver, $key);

        return $this;
    }

    /**
     * Register a model binding.
     *
     * @param string $parameter Route parameter name
     * @param string $model Model class
     * @param string|null $key Custom column
     * @return self
     */
    public function model(string $parameter, string $model, ?string $key = null): self
    {
        return $this->bind($parameter, $model, $key);
    }

    // ============================================================================
    // Subdomain Routing
    // ============================================================================

    /**
     * Get or create the subdomain router instance.
     *
     * @return SubdomainRouter
     */
    public function getSubdomainRouter(): SubdomainRouter
    {
        if ($this->subdomainRouter === null) {
            $this->subdomainRouter = new SubdomainRouter();
        }

        return $this->subdomainRouter;
    }

    /**
     * Set the subdomain router instance.
     *
     * @param SubdomainRouter $router
     * @return self
     */
    public function setSubdomainRouter(SubdomainRouter $router): self
    {
        $this->subdomainRouter = $router;

        return $this;
    }

    /**
     * Create a route group for a specific domain.
     *
     * @param string $domain Domain pattern (e.g., '{tenant}.example.com')
     * @param callable $callback Route definitions callback
     * @return self
     */
    public function domain(string $domain, callable $callback): self
    {
        $this->getSubdomainRouter()->group($domain, $callback, $this);

        return $this;
    }

    /**
     * Get subdomain parameters from current request.
     *
     * @return array<string, string>
     */
    public function getSubdomainParameters(): array
    {
        return $this->getSubdomainRouter()->getSubdomainParameters();
    }

    /**
     * Get a specific subdomain parameter.
     *
     * @param string $key Parameter name
     * @param string|null $default Default value
     * @return string|null
     */
    public function getSubdomainParameter(string $key, ?string $default = null): ?string
    {
        return $this->getSubdomainRouter()->getSubdomainParameter($key, $default);
    }

    /**
     * Set performance monitor.
     *
     * @param RoutePerformanceMonitor $monitor Performance monitor instance
     * @return self
     */
    public function setPerformanceMonitor(RoutePerformanceMonitor $monitor): self
    {
        $this->performanceMonitor = $monitor;
        return $this;
    }

    /**
     * Get performance monitor.
     *
     * @return RoutePerformanceMonitor|null
     */
    public function getPerformanceMonitor(): ?RoutePerformanceMonitor
    {
        return $this->performanceMonitor;
    }
}
