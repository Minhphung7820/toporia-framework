<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing;

use Toporia\Framework\Routing\Contracts\RouteInterface;

/**
 * Class Route
 *
 * Route implementation with parameter extraction support.
 * Compiles route patterns into regex for efficient matching.
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
final class Route implements RouteInterface
{
    /**
     * @var string|null Compiled route pattern (regex).
     */
    private ?string $compiledPattern = null;

    /**
     * @var array<string> Parameter names extracted from URI.
     */
    private array $parameterNames = [];

    /**
     * @var string|null Route name.
     */
    private ?string $name = null;

    /**
     * @var array<string, string> Parameter constraints (regex patterns)
     */
    private array $constraints = [];

    /**
     * @param string|array $methods HTTP method(s).
     * @param string $uri URI pattern with optional {param} placeholders.
     * @param mixed $handler Route handler (callable, controller array, etc.).
     * @param array<string> $middleware Middleware classes.
     */
    public function __construct(
        private string|array $methods,
        private string $uri,
        private mixed $handler,
        private array $middleware = []
    ) {
        $this->methods = is_string($methods) ? [$methods] : $methods;
        $this->compileRoute();
    }

    /**
     * {@inheritdoc}
     */
    public function getMethods(): string|array
    {
        return $this->methods;
    }

    /**
     * {@inheritdoc}
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * {@inheritdoc}
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * {@inheritdoc}
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function middleware(string|array $middleware): self
    {
        $middlewareArray = is_array($middleware) ? $middleware : [$middleware];
        $this->middleware = array_merge($this->middleware, $middlewareArray);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function matches(string $method, string $uri): ?array
    {
        // Check HTTP method
        if (!in_array($method, $this->methods, true)) {
            return null;
        }

        // Exact match
        if ($this->uri === $uri) {
            return [];
        }

        // Pattern match with parameters
        if ($this->compiledPattern && preg_match($this->compiledPattern, $uri, $matches)) {
            $parameters = [];
            foreach ($this->parameterNames as $name) {
                if (isset($matches[$name])) {
                    $parameters[$name] = $matches[$name];
                }
            }
            return $parameters;
        }

        return null;
    }

    /**
     * Add parameter constraints.
     *
     * Useful for SPA routes: Route::any('/{any}', ...)->where('any', '.*')
     *
     * @param string|array $parameter Parameter name or array of constraints
     * @param string|null $pattern Regex pattern (if $parameter is string)
     * @return self
     */
    public function where(string|array $parameter, ?string $pattern = null): self
    {
        if (is_array($parameter)) {
            // Multiple constraints: where(['id' => '\d+', 'slug' => '[a-z-]+'])
            $this->constraints = array_merge($this->constraints, $parameter);
        } else {
            // Single constraint: where('id', '\d+')
            $this->constraints[$parameter] = $pattern ?? '[^/]+';
        }

        // Recompile route with new constraints
        $this->compileRoute();

        return $this;
    }

    /**
     * Compile the route pattern into a regex.
     */
    private function compileRoute(): void
    {
        // Extract parameter names
        if (preg_match_all('#\{([^/]+)\}#', $this->uri, $matches)) {
            $this->parameterNames = $matches[1];

            // Build pattern with constraints
            $pattern = $this->uri;
            foreach ($this->parameterNames as $name) {
                // Validate parameter name (must be alphanumeric and underscore only for named groups)
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                    throw new \InvalidArgumentException("Invalid parameter name: {$name}. Parameter names must be alphanumeric and start with a letter or underscore.");
                }

                // Use custom constraint if provided, otherwise default to [^/]+
                $constraint = $this->constraints[$name] ?? '[^/]+';

                // Handle negative lookahead patterns (e.g., (?!api/).*)
                // Negative lookahead cannot be inside named group, so we need special handling
                if (str_starts_with($constraint, '(?!')) {
                    // Extract the lookahead pattern and the rest
                    // Pattern like (?!api/).* should become: (?!api/)(?P<any>.*)
                    if (preg_match('/^\((\?![^)]+)\)(.*)$/', $constraint, $lookaheadMatches)) {
                        $lookahead = '(' . $lookaheadMatches[1] . ')';
                        $rest = $lookaheadMatches[2] ?: '.*';
                        $replacement = $lookahead . '(?P<' . $name . '>' . $rest . ')';
                    } else {
                        // Fallback: wrap entire constraint in named group (may not work for all cases)
                        $replacement = '(?P<' . $name . '>' . $constraint . ')';
                    }
                } else {
                    // Normal constraint: wrap in named group
                    $replacement = '(?P<' . $name . '>' . $constraint . ')';
                }

                $pattern = str_replace('{' . $name . '}', $replacement, $pattern);
            }

            $this->compiledPattern = '#^' . $pattern . '$#';
        }
    }
}
